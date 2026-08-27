<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Support;

use Agovena\Modules\Provisioning\Models\ServiceInstance;
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

abstract class AbstractServerProvisioner implements ConfiguresProvisionedProducts, ConfiguresProvisioningServers, Provisioner, ProvisionerActions, ProvisionerLifecycle, ProvisionerPanel
{
    public function __construct(
        protected readonly ExtensionSettingsRepository $settings,
        protected readonly ServerApi $api,
    ) {}

    abstract public function id(): string;

    abstract public function label(): string;

    /** @return list<ExtensionSettingDefinition> */
    abstract public function serverSettings(): array;

    /** @return list<ExtensionSettingDefinition> */
    abstract public function productSettings(): array;

    /** @param array<string, mixed> $settings */
    public function testServer(array $settings): HealthResult
    {
        try {
            $this->api->withConnection($settings)->connectionTest();

            return HealthResult::ok($this->message('health.connected'));
        } catch (ServerProviderException $exception) {
            return HealthResult::fail($this->message($exception->errorKey));
        }
    }

    public function provision(ServiceInstanceInfo $instance): void
    {
        $this->assertConfigured($instance);
        $api = $this->apiFor($instance);
        $externalId = $this->externalId($instance->id);

        if ($this->mappingId($instance) !== null) {
            return;
        }

        try {
            $existing = $api->findServerByExternalId($externalId);
            if ($existing !== null) {
                $this->storeMapping($instance->id, $existing, $externalId);

                return;
            }

            $created = $api->createServer($this->buildCreatePayload($instance, $this->providerSettings($instance), $externalId));
            $this->storeMapping($instance->id, $created, $externalId);
        } catch (ServerProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => $this->message($exception->errorKey),
            ]);
        }
    }

    public function poll(ServiceInstanceInfo $instance): ServiceInstanceInfo
    {
        return $this->syncStatus($instance);
    }

    public function activate(ServiceInstanceInfo $instance): void
    {
        unset($instance);
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        $this->mutate($instance, function (string $externalId, ServerApi $api): void {
            $api->suspend($externalId);
        });
    }

    public function unsuspend(ServiceInstanceInfo $instance): void
    {
        $this->mutate($instance, function (string $externalId, ServerApi $api): void {
            $api->unsuspend($externalId);
        });
    }

    public function terminate(ServiceInstanceInfo $instance): void
    {
        $externalId = $this->mappingId($instance);
        if ($externalId === null) {
            return;
        }

        try {
            $this->apiFor($instance)->terminate($externalId);
        } catch (ServerProviderException $exception) {
            if ($exception->status !== 404) {
                throw ValidationException::withMessages([
                    'instance' => $this->message($exception->errorKey),
                ]);
            }
        }

        $this->forgetMapping($instance->id);
    }

    public function changePlan(ServiceInstanceInfo $instance, string $plan): void
    {
        $externalId = $this->requireMapping($instance);

        try {
            $this->apiFor($instance)->changePlan($externalId, $this->buildPlanPayload($instance, $plan));
        } catch (ServerProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => $this->message($exception->errorKey),
            ]);
        }
    }

    public function syncStatus(ServiceInstanceInfo $instance): ServiceInstanceInfo
    {
        $externalId = $this->mappingId($instance);
        if ($externalId === null) {
            return $instance;
        }

        try {
            $server = $this->apiFor($instance)->getServer($externalId);
        } catch (ServerProviderException $exception) {
            if ($exception->status === 404) {
                return $this->withStatus($instance, 'terminated', $externalId);
            }

            throw ValidationException::withMessages([
                'instance' => $this->message($exception->errorKey),
            ]);
        }

        $providerId = $this->storeMapping($instance->id, $server, $externalId);

        return $this->withStatus($instance, $this->mapLifecycleStatus($server), $providerId);
    }

    /** @return list<ProvisionerAction> */
    public function actions(ServiceInstanceInfo $instance): array
    {
        if ($instance->status !== 'active' || $this->mappingId($instance) === null || ! $this->supportsPowerActions()) {
            return [];
        }

        return [
            new ProvisionerAction('start', $this->message('actions.start')),
            new ProvisionerAction('stop', $this->message('actions.stop'), dangerous: true),
            new ProvisionerAction('restart', $this->message('actions.restart'), dangerous: true),
        ];
    }

    public function runAction(ServiceInstanceInfo $instance, string $actionId): void
    {
        if (! in_array($actionId, ['start', 'stop', 'restart'], true) || ! $this->supportsPowerActions()) {
            throw ValidationException::withMessages([
                'action' => $this->message('errors.action_unavailable'),
            ]);
        }

        $externalId = $this->requireMapping($instance);

        try {
            $this->apiFor($instance)->action($externalId, $actionId);
        } catch (ServerProviderException $exception) {
            throw ValidationException::withMessages([
                'action' => $this->message($exception->errorKey),
            ]);
        }
    }

    public function panel(ServiceInstanceInfo $instance): ?ProvisionerPanelData
    {
        $externalId = $this->mappingId($instance);
        if ($externalId === null) {
            return null;
        }

        $status = 'unknown';
        try {
            $status = $this->mapDisplayStatus($this->apiFor($instance)->getServer($externalId));
        } catch (ServerProviderException) {
            // The detail panel remains useful when a provider is temporarily unavailable.
        }

        $fields = [
            ['label' => $this->message('panel.status'), 'value' => $this->message('status.'.$status)],
            ['label' => $this->message('panel.external_ref'), 'value' => $externalId],
        ];
        $managementUrl = $this->managementUrl($instance, $externalId);
        if ($managementUrl !== null) {
            $fields[] = ['label' => $this->message('panel.management_url'), 'value' => $managementUrl];
        }

        return new ProvisionerPanelData($this->message('panel.title'), $fields);
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

        $settings = $this->repositoryConnectionSettings();
        foreach ($this->requiredConnectionKeys() as $key) {
            if (trim((string) ($settings[$key] ?? '')) === '') {
                return HealthResult::fail($this->message('health.not_configured'));
            }
        }

        return $this->testServer($settings);
    }

    /** @param array<string, mixed> $providerSettings @return array<string, mixed> */
    abstract protected function buildCreatePayload(ServiceInstanceInfo $instance, array $providerSettings, string $externalId): array;

    /** @return list<string> */
    protected function requiredConnectionKeys(): array
    {
        return ['api_url', 'api_token'];
    }

    protected function supportsPowerActions(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $server */
    protected function mapLifecycleStatus(array $server): string
    {
        return match (strtolower((string) ($server['status'] ?? ''))) {
            'provisioning', 'installing', 'building', 'pending' => 'provisioning',
            'suspended', 'suspend' => 'suspended',
            'terminated', 'deleted', 'destroyed' => 'terminated',
            'failed', 'error' => 'failed',
            default => 'active',
        };
    }

    /** @param array<string, mixed> $server */
    protected function mapDisplayStatus(array $server): string
    {
        return strtolower((string) ($server['status'] ?? 'unknown')) ?: 'unknown';
    }

    protected function buildPlanPayload(ServiceInstanceInfo $instance, string $plan): array
    {
        unset($instance);

        return ['plan' => $plan];
    }

    protected function managementUrl(ServiceInstanceInfo $instance, string $externalId): ?string
    {
        $base = trim((string) $this->connectionSetting($instance, 'api_url', ''));
        if ($base === '') {
            return null;
        }

        return rtrim($base, '/').'/servers/'.rawurlencode($externalId);
    }

    protected function message(string $key): string
    {
        return __(''.$this->id().'::messages.'.$key);
    }

    private function assertConfigured(ServiceInstanceInfo $instance): void
    {
        foreach ($this->requiredConnectionKeys() as $key) {
            if (trim((string) $this->connectionSetting($instance, $key, '')) === '') {
                throw ValidationException::withMessages([
                    'instance' => $this->message('errors.not_configured'),
                ]);
            }
        }
    }

    private function mutate(ServiceInstanceInfo $instance, callable $callback): void
    {
        $externalId = $this->requireMapping($instance);

        try {
            $callback($externalId, $this->apiFor($instance));
        } catch (ServerProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => $this->message($exception->errorKey),
            ]);
        }
    }

    private function requireMapping(ServiceInstanceInfo $instance): string
    {
        $externalId = $this->mappingId($instance);
        if ($externalId === null) {
            throw ValidationException::withMessages([
                'instance' => $this->message('errors.not_provisioned'),
            ]);
        }

        return $externalId;
    }

    private function apiFor(ServiceInstanceInfo $instance): ServerApi
    {
        $settings = $this->connectionSettings($instance);
        foreach ($this->requiredConnectionKeys() as $key) {
            if (trim((string) ($settings[$key] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'instance' => $this->message('errors.not_configured'),
                ]);
            }
        }

        return $this->api->withConnection($settings);
    }

    /** @return array<string, mixed> */
    private function providerSettings(ServiceInstanceInfo $instance): array
    {
        $settings = $instance->meta['provider_settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /** @return array<string, mixed> */
    private function connectionSettings(ServiceInstanceInfo $instance): array
    {
        $settings = $instance->meta['server_settings'] ?? [];

        if (($instance->meta['server_settings_required'] ?? false) === true) {
            return is_array($settings) ? $settings : [];
        }

        return is_array($settings) && $settings !== [] ? $settings : $this->repositoryConnectionSettings();
    }

    /** @return array<string, mixed> */
    private function repositoryConnectionSettings(): array
    {
        $settings = [];
        foreach ($this->serverSettings() as $definition) {
            $settings[$definition->key] = $this->settings->get($this->id(), $definition->key, $definition->default);
        }

        return $settings;
    }

    private function connectionSetting(ServiceInstanceInfo $instance, string $key, mixed $default = null): mixed
    {
        $settings = $this->connectionSettings($instance);

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    private function externalId(int $instanceId): string
    {
        return 'agovena-'.$this->id().'-'.$instanceId;
    }

    private function mappingId(ServiceInstanceInfo $instance): ?string
    {
        if ($instance->externalRef !== null && trim($instance->externalRef) !== '') {
            return trim($instance->externalRef);
        }

        $model = ServiceInstance::query()->find($instance->id);
        $externalRef = $model?->external_ref;

        return is_string($externalRef) && trim($externalRef) !== '' ? trim($externalRef) : null;
    }

    /** @param array<string, mixed> $server */
    private function storeMapping(int $instanceId, array $server, string $externalId): string
    {
        $model = ServiceInstance::query()->find($instanceId);
        if ($model === null) {
            return $externalId;
        }

        $providerId = $this->providerId($server)
            ?? (is_string($model->external_ref) && trim($model->external_ref) !== '' ? trim($model->external_ref) : $externalId);
        $meta = is_array($model->meta) ? $model->meta : [];
        $meta['provider_mapping'] = array_intersect_key($server, array_flip([
            'id', 'external_id', 'uuid', 'identifier', 'name', 'status', 'management_url',
        ]));
        $model->forceFill([
            'provider_key' => $this->id(),
            'external_ref' => $providerId,
            'meta' => $meta,
        ])->save();

        return $providerId;
    }

    private function forgetMapping(int $instanceId): void
    {
        ServiceInstance::query()->whereKey($instanceId)->update([
            'external_ref' => null,
            'terminated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $server */
    private function providerId(array $server): ?string
    {
        foreach (['id', 'external_id', 'uuid', 'identifier', 'server_id', 'resource_id'] as $key) {
            if (isset($server[$key]) && trim((string) $server[$key]) !== '') {
                return (string) $server[$key];
            }
        }

        return null;
    }

    private function withStatus(ServiceInstanceInfo $instance, string $status, string $externalId): ServiceInstanceInfo
    {
        return new ServiceInstanceInfo(
            id: $instance->id,
            label: $instance->label,
            status: $status,
            providerKey: $this->id(),
            externalRef: $externalId,
            meta: $instance->meta,
        );
    }
}
