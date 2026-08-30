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
        $this->assertUnsupported();
    }
    public function poll(ServiceInstanceInfo $instance): ServiceInstanceInfo
    {
        return $this->syncStatus($instance);
    }

    public function activate(ServiceInstanceInfo $instance): void
    {
        unset($instance);
        $this->assertUnsupported();
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        unset($instance);
        $this->assertUnsupported();
    }

    public function unsuspend(ServiceInstanceInfo $instance): void
    {
        unset($instance);
        $this->assertUnsupported();
    }

    public function terminate(ServiceInstanceInfo $instance): void
    {
        unset($instance);
        $this->assertUnsupported();
    }

    public function changePlan(ServiceInstanceInfo $instance, string|array $plan): void
    {
        unset($instance, $plan);
        $this->assertUnsupported();
    }

    public function syncStatus(ServiceInstanceInfo $instance): ServiceInstanceInfo
    {
        $this->assertUnsupported();

        return $instance;
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
        unset($instance, $actionId);
        $this->assertUnsupported();
    }

    public function panel(ServiceInstanceInfo $instance): ?ProvisionerPanelData
    {
        unset($instance);
        $this->assertUnsupported();
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
        return false;
    }

    /** @param array<string, mixed> $server */
    protected function mapLifecycleStatus(array $server): string
    {
        return match (strtolower((string) ($server['status'] ?? ''))) {
            'provisioning', 'installing', 'building', 'pending' => 'provisioning',
            'suspended', 'suspend' => 'suspended',
            'terminated', 'deleted', 'destroyed' => 'terminated',
            'failed', 'error' => 'failed',
            default => 'manual_review',
        };
    }

    /** @param array<string, mixed> $server */
    protected function mapDisplayStatus(array $server): string
    {
        return strtolower((string) ($server['status'] ?? 'unknown')) ?: 'unknown';
    }

    /** @param string|array<string, mixed> $plan */
    protected function buildPlanPayload(ServiceInstanceInfo $instance, string|array $plan): array
    {
        unset($instance);

        if (! is_array($plan)) {
            return ['plan' => $plan];
        }

        $payload = ['plan' => (string) ($plan['id'] ?? '')];
        foreach (['provider_settings', 'server_settings', 'server_id', 'target_settings', 'requirements', 'capacity_key'] as $key) {
            if (array_key_exists($key, $plan)) {
                $payload[$key] = $plan[$key];
            }
        }

        return $payload;
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

    private function assertUnsupported(): never
    {
        throw ValidationException::withMessages([
            'instance' => __('provisioning::errors.unsupported'),
        ]);
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

    /** @param array<string, mixed>|null $settings */
    private function apiForConnection(?array $settings): ServerApi
    {
        $connection = $settings ?? $this->repositoryConnectionSettings();
        foreach ($this->requiredConnectionKeys() as $key) {
            if (trim((string) ($connection[$key] ?? '')) === '') {
                throw new ServerProviderException('errors.not_configured');
            }
        }

        return $this->api->withConnection($connection);
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
        $settings = $instance->providerSettings ?? [];

        return is_array($settings) ? $settings : [];
    }

    /** @return array<string, mixed> */
    private function connectionSettings(ServiceInstanceInfo $instance): array
    {
        $settings = $instance->serverSettings ?? [];

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
        $model = ServiceInstance::query()->find($instance->id);
        $meta = is_array($model?->meta) ? $model->meta : $instance->meta;
        $mapping = $meta['provider_mapping'] ?? null;
        if (! is_array($mapping)) {
            return null;
        }

        $providerId = $mapping['provider_id'] ?? null;

        return is_scalar($providerId) && trim((string) $providerId) !== '' ? trim((string) $providerId) : null;
    }

    /** @param array<string, mixed> $server */
    private function storeMapping(int $instanceId, array $server, string $externalId): string
    {
        $model = ServiceInstance::query()->find($instanceId);
        if ($model === null) {
            return $externalId;
        }

        $providerId = $this->providerId($server, $externalId);
        if ($providerId === null) {
            throw ValidationException::withMessages([
                'instance' => $this->message('errors.not_provisioned'),
            ]);
        }
        $meta = is_array($model->meta) ? $model->meta : [];
        if (is_string($model->external_ref) && trim($model->external_ref) !== '') {
            $meta['source_external_ref'] ??= trim($model->external_ref);
        }
        $meta['provider_mapping'] = ['provider_id' => $providerId] + array_intersect_key($server, array_flip([
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
        $model = ServiceInstance::query()->find($instanceId);
        if ($model === null) {
            return;
        }
        $meta = is_array($model->meta) ? $model->meta : [];
        unset($meta['provider_mapping']);
        $model->forceFill(['external_ref' => null, 'meta' => $meta, 'terminated_at' => now()])->save();
    }

    /** @param array<string, mixed> $server */
    private function providerId(array $server, string $externalId): ?string
    {
        foreach (['id', 'uuid', 'identifier', 'server_id', 'resource_id'] as $key) {
            if (isset($server[$key]) && trim((string) $server[$key]) !== '') {
                return (string) $server[$key];
            }
        }

        if (isset($server['external_id']) && trim((string) $server['external_id']) !== '' && (string) $server['external_id'] !== $externalId) {
            return (string) $server['external_id'];
        }

        return null;
    }

    /** @param array<string, mixed> $meta */
    private function withStatus(ServiceInstanceInfo $instance, string $status, string $externalId, array $meta = []): ServiceInstanceInfo
    {
        return new ServiceInstanceInfo(
            id: $instance->id,
            label: $instance->label,
            status: $status,
            providerKey: $this->id(),
            externalRef: $externalId,
            meta: array_merge($instance->meta, $meta),
            serverSettings: $instance->serverSettings,
            providerSettings: $instance->providerSettings,
        );
    }
}
