<?php

declare(strict_types=1);

namespace Agovena\Extensions\Pterodactyl;

use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\HealthResult;
use App\Agovena\Provisioning\Contracts\ConfiguresProvisionedProducts;
use App\Agovena\Provisioning\Contracts\ConfiguresProvisioningServers;
use App\Agovena\Provisioning\Contracts\Provisioner;
use App\Agovena\Provisioning\Contracts\ProvisionerActions;
use App\Agovena\Provisioning\Contracts\ProvisionerLifecycle;
use App\Agovena\Provisioning\Contracts\ProvisionerPanel;
use App\Agovena\Provisioning\ProvisionerAction;
use App\Agovena\Provisioning\ProvisionerPanelData;
use App\Agovena\Provisioning\ServiceInstanceInfo;
use App\Models\ProvisioningServer;
use Illuminate\Validation\ValidationException;

final class PterodactylProvisioner implements ConfiguresProvisionedProducts, ConfiguresProvisioningServers, Provisioner, ProvisionerActions, ProvisionerLifecycle, ProvisionerPanel
{
    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
        private readonly PterodactylApi $api,
    ) {}

    public function id(): string
    {
        return 'pterodactyl';
    }

    public function label(): string
    {
        return __('pterodactyl::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('panel_url', 'pterodactyl::messages.settings.panel_url', required: true, help: 'pterodactyl::messages.settings.panel_url_help'),
            new ExtensionSettingDefinition('application_api_key', 'pterodactyl::messages.settings.application_api_key', secret: true, required: true, help: 'pterodactyl::messages.settings.application_api_key_help'),
            new ExtensionSettingDefinition('client_api_key', 'pterodactyl::messages.settings.client_api_key', secret: true, help: 'pterodactyl::messages.settings.client_api_key_help'),
            new ExtensionSettingDefinition('user_id', 'pterodactyl::messages.settings.user_id', required: true, help: 'pterodactyl::messages.settings.user_id_help'),
            new ExtensionSettingDefinition('verify_tls', 'pterodactyl::messages.settings.verify_tls', type: 'boolean', default: true, help: 'pterodactyl::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'pterodactyl::messages.settings.timeout', default: '15', help: 'pterodactyl::messages.settings.timeout_help'),
        ];
    }

    public function testServer(array $settings): HealthResult
    {
        try {
            $this->api->withConnection($settings)->connectionTest();

            return HealthResult::ok('pterodactyl');
        } catch (PterodactylProviderException $exception) {
            return HealthResult::fail($exception->errorKey);
        }
    }

    /**
     * @return list<ExtensionSettingDefinition>
     */
    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('location_id', 'pterodactyl::messages.product.location_id', required: true, help: 'pterodactyl::messages.product.location_id_help'),
            new ExtensionSettingDefinition('nest_id', 'pterodactyl::messages.product.nest_id', required: true, help: 'pterodactyl::messages.product.nest_id_help'),
            new ExtensionSettingDefinition('egg_id', 'pterodactyl::messages.product.egg_id', required: true, help: 'pterodactyl::messages.product.egg_id_help'),
            new ExtensionSettingDefinition('memory', 'pterodactyl::messages.product.memory', default: '512'),
            new ExtensionSettingDefinition('swap', 'pterodactyl::messages.product.swap', default: '0'),
            new ExtensionSettingDefinition('disk', 'pterodactyl::messages.product.disk', default: '1024'),
            new ExtensionSettingDefinition('io', 'pterodactyl::messages.product.io', default: '500'),
            new ExtensionSettingDefinition('cpu', 'pterodactyl::messages.product.cpu', default: '100'),
            new ExtensionSettingDefinition('databases', 'pterodactyl::messages.product.databases', default: '0'),
            new ExtensionSettingDefinition('allocations', 'pterodactyl::messages.product.allocations', default: '1'),
            new ExtensionSettingDefinition('backups', 'pterodactyl::messages.product.backups', default: '0'),
            new ExtensionSettingDefinition('docker_image', 'pterodactyl::messages.product.docker_image', help: 'pterodactyl::messages.product.docker_image_help'),
            new ExtensionSettingDefinition('startup', 'pterodactyl::messages.product.startup', help: 'pterodactyl::messages.product.startup_help'),
            new ExtensionSettingDefinition('environment', 'pterodactyl::messages.product.environment', type: 'text', help: 'pterodactyl::messages.product.environment_help'),
            new ExtensionSettingDefinition('dedicated_ip', 'pterodactyl::messages.product.dedicated_ip', default: '0', help: 'pterodactyl::messages.product.dedicated_ip_help'),
        ];
    }

    public function provision(ServiceInstanceInfo $instance): void
    {
        $this->assertConfigured($instance);
        $api = $this->apiFor($instance);
        $mapping = $this->mapping($instance->id);
        if ($mapping !== null) {
            return;
        }

        $externalId = $this->externalId($instance->id);

        try {
            $existing = $api->findServerByExternalId($externalId);
            if ($existing !== null) {
                $this->storeMapping($instance->id, $existing, $externalId);

                return;
            }

            $settings = $this->providerSettings($instance);
            $locationId = $this->intSetting($settings, 'location_id');
            $nestId = $this->intSetting($settings, 'nest_id');
            $eggId = $this->intSetting($settings, 'egg_id');
            if ($locationId === null || $nestId === null || $eggId === null) {
                throw ValidationException::withMessages([
                    'instance' => __('pterodactyl::messages.errors.invalid_mapping'),
                ]);
            }

            $egg = $api->getEgg($nestId, $eggId);
            $created = $api->createServer($this->createPayload($instance, $settings, $egg, $externalId, $locationId, $eggId));
            $this->storeMapping($instance->id, $created, $externalId);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (PterodactylProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => __($exception->errorKey),
            ]);
        }
    }

    public function poll(ServiceInstanceInfo $instance): ServiceInstanceInfo
    {
        return $this->syncStatus($instance);
    }

    public function activate(ServiceInstanceInfo $instance): void
    {
        // Panel install completion is observed via poll/syncStatus.
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        $api = $this->apiFor($instance);
        $this->mutate($instance, fn (PterodactylServer $mapping) => $api->suspend($mapping->server_id));
    }

    public function unsuspend(ServiceInstanceInfo $instance): void
    {
        $api = $this->apiFor($instance);
        $this->mutate($instance, fn (PterodactylServer $mapping) => $api->unsuspend($mapping->server_id));
    }

    public function terminate(ServiceInstanceInfo $instance): void
    {
        $mapping = $this->mapping($instance->id);
        if ($mapping === null) {
            return;
        }

        $api = $this->apiFor($instance);
        try {
            $api->delete($mapping->server_id);
        } catch (PterodactylProviderException $exception) {
            if ($exception->status !== 404) {
                throw ValidationException::withMessages([
                    'instance' => __($exception->errorKey),
                ]);
            }
        }

        $mapping->delete();
    }

    public function changePlan(ServiceInstanceInfo $instance, string $plan): void
    {
        unset($plan);
        $mapping = $this->requireMapping($instance);
        $settings = $this->providerSettings($instance);
        $api = $this->apiFor($instance);

        try {
            $server = $api->getServer($mapping->server_id);
            $api->updateBuild($mapping->server_id, [
                'allocation' => (int) ($server['allocation'] ?? 0),
                'memory' => $this->intSetting($settings, 'memory') ?? 512,
                'swap' => $this->intSetting($settings, 'swap') ?? 0,
                'disk' => $this->intSetting($settings, 'disk') ?? 1024,
                'io' => $this->intSetting($settings, 'io') ?? 500,
                'cpu' => $this->intSetting($settings, 'cpu') ?? 100,
                'feature_limits' => [
                    'databases' => $this->intSetting($settings, 'databases') ?? 0,
                    'allocations' => $this->intSetting($settings, 'allocations') ?? 1,
                    'backups' => $this->intSetting($settings, 'backups') ?? 0,
                ],
            ]);
        } catch (PterodactylProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => __($exception->errorKey),
            ]);
        }
    }

    public function syncStatus(ServiceInstanceInfo $instance): ServiceInstanceInfo
    {
        $api = $this->apiFor($instance);
        try {
            $mapping = $this->mapping($instance->id);
            if ($mapping === null) {
                $existing = $api->findServerByExternalId($this->externalId($instance->id));
                if ($existing === null) {
                    return $instance;
                }
                $mapping = $this->storeMapping($instance->id, $existing, $this->externalId($instance->id));
            }

            $server = $api->getServer($mapping->server_id);
        } catch (PterodactylProviderException $exception) {
            if ($exception->status === 404) {
                return new ServiceInstanceInfo(
                    id: $instance->id,
                    label: $instance->label,
                    status: 'terminated',
                    providerKey: $this->id(),
                    externalRef: $instance->externalRef,
                    meta: $instance->meta,
                );
            }

            throw ValidationException::withMessages([
                'instance' => __($exception->errorKey),
            ]);
        }

        $mapping->panel_status = (string) ($server['status'] ?? '');
        $mapping->identifier = (string) ($server['identifier'] ?? $mapping->identifier);
        $mapping->uuid = isset($server['uuid']) ? (string) $server['uuid'] : $mapping->uuid;
        $mapping->save();

        return new ServiceInstanceInfo(
            id: $instance->id,
            label: $instance->label,
            status: PterodactylStatusMapper::lifecycleStatus($server),
            providerKey: $this->id(),
            externalRef: (string) $mapping->server_id,
            meta: $instance->meta,
        );
    }

    public function actions(ServiceInstanceInfo $instance): array
    {
        if ($instance->status !== 'active' || ! $this->hasClientKey($instance)) {
            return [];
        }

        if ($this->mapping($instance->id) === null) {
            return [];
        }

        return [
            new ProvisionerAction('start', __('pterodactyl::messages.actions.start')),
            new ProvisionerAction('stop', __('pterodactyl::messages.actions.stop'), dangerous: true),
            new ProvisionerAction('restart', __('pterodactyl::messages.actions.restart'), dangerous: true),
        ];
    }

    public function runAction(ServiceInstanceInfo $instance, string $actionId): void
    {
        if (! in_array($actionId, ['start', 'stop', 'restart'], true)) {
            throw ValidationException::withMessages([
                'action' => __('pterodactyl::messages.errors.action_unavailable'),
            ]);
        }

        if (! $this->hasClientKey($instance)) {
            throw ValidationException::withMessages([
                'action' => __('pterodactyl::messages.errors.power_unavailable'),
            ]);
        }

        $mapping = $this->requireMapping($instance);
        $api = $this->apiFor($instance);

        try {
            $api->power($mapping->identifier, $actionId);
        } catch (PterodactylProviderException $exception) {
            throw ValidationException::withMessages([
                'action' => __($exception->errorKey === 'pterodactyl::messages.errors.power_unavailable'
                    ? $exception->errorKey
                    : 'pterodactyl::messages.errors.power_failed'),
            ]);
        }
    }

    public function panel(ServiceInstanceInfo $instance): ?ProvisionerPanelData
    {
        $mapping = $this->mapping($instance->id);
        if ($mapping === null) {
            return null;
        }
        $api = $this->apiFor($instance);

        $statusKey = 'unknown';
        $server = null;
        try {
            $server = $api->getServer($mapping->server_id);
            $statusKey = PterodactylStatusMapper::displayStatus($server);
            if ($this->hasClientKey($instance)) {
                try {
                    $client = $api->clientServer($mapping->identifier);
                    $server['current_state'] = $client['current_state'] ?? $client['currentState'] ?? null;
                    $statusKey = PterodactylStatusMapper::displayStatus($server);
                } catch (PterodactylProviderException) {
                    // Application status is enough when Client API is unavailable.
                }
            }
        } catch (PterodactylProviderException) {
            $statusKey = 'unknown';
        }

        $fields = [
            ['label' => __('pterodactyl::messages.panel.status'), 'value' => __('pterodactyl::messages.status.'.$statusKey)],
            ['label' => __('pterodactyl::messages.panel.identifier'), 'value' => $mapping->identifier],
        ];

        try {
            $panel = PterodactylPanelUrl::normalize($this->panelUrl($instance));
            $fields[] = [
                'label' => __('pterodactyl::messages.panel.panel_url'),
                'value' => $panel.'/server/'.$mapping->identifier,
            ];
        } catch (PterodactylProviderException) {
            // Skip the link when the stored URL is invalid.
        }

        return new ProvisionerPanelData(__('pterodactyl::messages.panel.title'), $fields);
    }

    public function health(): HealthResult
    {
        $server = ProvisioningServer::query()
            ->where('provider_key', $this->id())
            ->where('is_active', true)
            ->first();
        if ($server !== null) {
            return $this->testServer($server->settings);
        }

        $url = $this->panelUrl();
        if ($url === '') {
            return HealthResult::fail(__('pterodactyl::messages.health.missing_url'));
        }

        try {
            $normalized = PterodactylPanelUrl::normalize($url);
        } catch (PterodactylProviderException) {
            return HealthResult::fail(__('pterodactyl::messages.health.invalid_url'));
        }

        if (trim((string) $this->settings->get('pterodactyl', 'application_api_key', '')) === '') {
            return HealthResult::fail(__('pterodactyl::messages.health.missing_key'));
        }

        if (trim((string) $this->settings->get('pterodactyl', 'user_id', '')) === '') {
            return HealthResult::fail(__('pterodactyl::messages.health.missing_user'));
        }

        try {
            $this->api->connectionTest();
        } catch (PterodactylProviderException $exception) {
            $key = $exception->errorKey;
            if ($key === 'pterodactyl::messages.errors.unauthorized') {
                return HealthResult::fail(__('pterodactyl::messages.health.unauthorized'));
            }
            if ($key === 'pterodactyl::messages.errors.timeout') {
                return HealthResult::fail(__('pterodactyl::messages.health.unreachable'));
            }

            return HealthResult::fail(__('pterodactyl::messages.health.unreachable'));
        }

        return HealthResult::ok(__('pterodactyl::messages.health.ok', ['url' => $normalized]));
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function storeMapping(int $instanceId, array $server, string $externalId): PterodactylServer
    {
        return PterodactylServer::query()->updateOrCreate(
            ['service_instance_id' => $instanceId],
            [
                'server_id' => (int) $server['id'],
                'identifier' => (string) $server['identifier'],
                'uuid' => isset($server['uuid']) ? (string) $server['uuid'] : null,
                'external_id' => $externalId,
                'panel_status' => isset($server['status']) ? (string) $server['status'] : null,
            ],
        );
    }

    private function mapping(int $instanceId): ?PterodactylServer
    {
        return PterodactylServer::query()->where('service_instance_id', $instanceId)->first();
    }

    private function requireMapping(ServiceInstanceInfo $instance): PterodactylServer
    {
        $mapping = $this->mapping($instance->id);
        if ($mapping === null) {
            throw ValidationException::withMessages([
                'instance' => __('pterodactyl::messages.errors.not_found'),
            ]);
        }

        return $mapping;
    }

    /**
     * @param  callable(PterodactylServer): void  $callback
     */
    private function mutate(ServiceInstanceInfo $instance, callable $callback): void
    {
        $mapping = $this->requireMapping($instance);

        try {
            $callback($mapping);
        } catch (PterodactylProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => __($exception->errorKey),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $egg
     * @return array<string, mixed>
     */
    private function createPayload(
        ServiceInstanceInfo $instance,
        array $settings,
        array $egg,
        string $externalId,
        int $locationId,
        int $eggId,
    ): array {
        $userId = (int) $this->settings->get('pterodactyl', 'user_id', 0);
        $dockerImage = trim((string) ($settings['docker_image'] ?? ''));
        if ($dockerImage === '') {
            $dockerImage = (string) ($egg['docker_image'] ?? '');
        }
        $startup = trim((string) ($settings['startup'] ?? ''));
        if ($startup === '') {
            $startup = (string) ($egg['startup'] ?? '');
        }

        return [
            'external_id' => $externalId,
            'name' => mb_substr($instance->label !== '' ? $instance->label : $externalId, 0, 191),
            'user' => $userId,
            'egg' => $eggId,
            'docker_image' => $dockerImage,
            'startup' => $startup,
            'environment' => $this->environment($settings, $egg),
            'limits' => [
                'memory' => $this->intSetting($settings, 'memory') ?? 512,
                'swap' => $this->intSetting($settings, 'swap') ?? 0,
                'disk' => $this->intSetting($settings, 'disk') ?? 1024,
                'io' => $this->intSetting($settings, 'io') ?? 500,
                'cpu' => $this->intSetting($settings, 'cpu') ?? 100,
            ],
            'feature_limits' => [
                'databases' => $this->intSetting($settings, 'databases') ?? 0,
                'allocations' => $this->intSetting($settings, 'allocations') ?? 1,
                'backups' => $this->intSetting($settings, 'backups') ?? 0,
            ],
            'deploy' => [
                'locations' => [$locationId],
                'dedicated_ip' => $this->boolSetting($settings, 'dedicated_ip'),
                'port_range' => [],
            ],
            'start_on_completion' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $egg
     * @return array<string, string>
     */
    private function environment(array $settings, array $egg): array
    {
        $environment = [];
        $variables = $egg['relationships']['variables']['data'] ?? [];
        if (is_array($variables)) {
            foreach ($variables as $variable) {
                $attributes = is_array($variable) ? ($variable['attributes'] ?? $variable) : [];
                if (! is_array($attributes)) {
                    continue;
                }
                $key = (string) ($attributes['env_variable'] ?? '');
                if ($key === '') {
                    continue;
                }
                $environment[$key] = (string) ($attributes['default_value'] ?? '');
            }
        }

        $raw = (string) ($settings['environment'] ?? '');
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '') {
                $environment[$key] = trim($value);
            }
        }

        return $environment;
    }

    /**
     * @return array<string, mixed>
     */
    private function providerSettings(ServiceInstanceInfo $instance): array
    {
        $settings = $instance->meta['provider_settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function intSetting(array $settings, string $key): ?int
    {
        if (! isset($settings[$key]) || $settings[$key] === '') {
            return null;
        }

        return (int) $settings[$key];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function boolSetting(array $settings, string $key): bool
    {
        return filter_var($settings[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function externalId(int $instanceId): string
    {
        return 'agovena-'.$instanceId;
    }

    private function apiFor(ServiceInstanceInfo $instance): PterodactylApi
    {
        return $this->api->withConnection($this->connectionSettings($instance));
    }

    /** @return array<string, mixed> */
    private function connectionSettings(ServiceInstanceInfo $instance): array
    {
        $settings = $instance->meta['server_settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    private function connectionSetting(?ServiceInstanceInfo $instance, string $key, mixed $default = null): mixed
    {
        if ($instance !== null) {
            $settings = $this->connectionSettings($instance);
            if (array_key_exists($key, $settings)) {
                return $settings[$key];
            }
        }

        return $this->settings->get('pterodactyl', $key, $default);
    }

    private function panelUrl(?ServiceInstanceInfo $instance = null): string
    {
        return trim((string) $this->connectionSetting($instance, 'panel_url', ''));
    }

    private function hasClientKey(?ServiceInstanceInfo $instance = null): bool
    {
        return trim((string) $this->connectionSetting($instance, 'client_api_key', '')) !== '';
    }

    private function assertConfigured(ServiceInstanceInfo $instance): void
    {
        if ($this->panelUrl($instance) === '' || trim((string) $this->connectionSetting($instance, 'application_api_key', '')) === '') {
            throw ValidationException::withMessages([
                'instance' => __('pterodactyl::messages.errors.not_configured'),
            ]);
        }

        if ((int) $this->connectionSetting($instance, 'user_id', 0) < 1) {
            throw ValidationException::withMessages([
                'instance' => __('pterodactyl::messages.health.missing_user'),
            ]);
        }
    }
}
