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
        unset($instance);
        $this->assertUnsupported();
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

    /** @return array<string, mixed> */
    private function repositoryConnectionSettings(): array
    {
        $settings = [];
        foreach ($this->serverSettings() as $definition) {
            $settings[$definition->key] = $this->settings->get($this->id(), $definition->key, $definition->default);
        }

        return $settings;
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
}
