<?php

declare(strict_types=1);

namespace Agovena\Extensions\Proxmox;

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

final class ProxmoxProvisioner implements ConfiguresProvisionedProducts, ConfiguresProvisioningServers, Provisioner, ProvisionerActions, ProvisionerLifecycle, ProvisionerPanel
{
    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
        private readonly ProxmoxApi $api,
    ) {}

    public function id(): string
    {
        return 'proxmox';
    }

    public function label(): string
    {
        return __('proxmox::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'proxmox::messages.settings.api_url', required: true, help: 'proxmox::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('token_user', 'proxmox::messages.settings.token_user', required: true, help: 'proxmox::messages.settings.token_user_help'),
            new ExtensionSettingDefinition('token_id', 'proxmox::messages.settings.token_id', required: true, help: 'proxmox::messages.settings.token_id_help'),
            new ExtensionSettingDefinition('token_secret', 'proxmox::messages.settings.token_secret', secret: true, required: true, help: 'proxmox::messages.settings.token_secret_help'),
            new ExtensionSettingDefinition('node', 'proxmox::messages.settings.node', required: true, help: 'proxmox::messages.settings.node_help'),
            new ExtensionSettingDefinition('storage', 'proxmox::messages.settings.storage', required: true, help: 'proxmox::messages.settings.storage_help'),
            new ExtensionSettingDefinition('verify_tls', 'proxmox::messages.settings.verify_tls', type: 'boolean', default: true, help: 'proxmox::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'proxmox::messages.settings.timeout', default: '30', help: 'proxmox::messages.settings.timeout_help'),
        ];
    }

    public function testServer(array $settings): HealthResult
    {
        try {
            $this->api->withConnection($settings)->connectionTest();

            return HealthResult::ok('proxmox');
        } catch (ProxmoxProviderException $exception) {
            return HealthResult::fail($exception->errorKey);
        }
    }

    /** @return list<ExtensionSettingDefinition> */
    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('template_vmid', 'proxmox::messages.product.template_vmid', required: true, help: 'proxmox::messages.product.template_vmid_help'),
            new ExtensionSettingDefinition('cores', 'proxmox::messages.product.cores', default: '1'),
            new ExtensionSettingDefinition('memory', 'proxmox::messages.product.memory', default: '1024', help: 'proxmox::messages.product.memory_help'),
            new ExtensionSettingDefinition('disk', 'proxmox::messages.product.disk', default: '20', help: 'proxmox::messages.product.disk_help'),
            new ExtensionSettingDefinition('sockets', 'proxmox::messages.product.sockets', default: '1'),
            new ExtensionSettingDefinition('cpu_type', 'proxmox::messages.product.cpu_type', default: 'host'),
            new ExtensionSettingDefinition('bridge', 'proxmox::messages.product.bridge', default: 'vmbr0'),
            new ExtensionSettingDefinition('autostart', 'proxmox::messages.product.autostart', type: 'boolean', default: true, help: 'proxmox::messages.product.autostart_help'),
        ];
    }

    public function provision(ServiceInstanceInfo $instance): void
    {
        $this->assertConfigured($instance);
        $api = $this->apiFor($instance);
        if ($this->mapping($instance->id) !== null) {
            return;
        }

        $settings = $this->providerSettings($instance);
        $templateVmid = $this->intSetting($settings, 'template_vmid');
        if ($templateVmid === null || $templateVmid < 100) {
            throw ValidationException::withMessages([
                'instance' => __('proxmox::messages.errors.invalid_mapping'),
            ]);
        }

        $node = $this->node($instance);
        $storage = $this->storage($instance);
        $hostname = $this->hostname($instance);
        $externalId = $this->externalId($instance->id);

        try {
            $vmid = max($api->nextVmId(), 100);
            $api->cloneVm($node, $templateVmid, [
                'newid' => $vmid,
                'name' => $hostname,
                'full' => 1,
                'storage' => $storage,
                'target' => $node,
            ]);

            $api->updateConfig($node, $vmid, array_filter([
                'cores' => $this->intSetting($settings, 'cores') ?? 1,
                'sockets' => $this->intSetting($settings, 'sockets') ?? 1,
                'memory' => $this->intSetting($settings, 'memory') ?? 1024,
                'cpu' => (string) ($settings['cpu_type'] ?? 'host'),
                'autostart' => $this->boolSetting($settings, 'autostart') ? 1 : 0,
            ]));

            if ($this->boolSetting($settings, 'autostart')) {
                $api->start($node, $vmid);
            }

            $this->storeMapping($instance->id, $vmid, $node, $hostname, $externalId);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ProxmoxProviderException $exception) {
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
        // Power state is observed via syncStatus.
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        $mapping = $this->requireMapping($instance);
        $api = $this->apiFor($instance);

        try {
            $api->stop($mapping->node, $mapping->vmid);
            $api->updateConfig($mapping->node, $mapping->vmid, ['autostart' => 0]);
            $mapping->power_status = 'stopped';
            $mapping->save();
        } catch (ProxmoxProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => __($exception->errorKey),
            ]);
        }
    }

    public function unsuspend(ServiceInstanceInfo $instance): void
    {
        $mapping = $this->requireMapping($instance);
        $api = $this->apiFor($instance);

        try {
            $api->updateConfig($mapping->node, $mapping->vmid, ['autostart' => 1]);
            $api->start($mapping->node, $mapping->vmid);
            $mapping->power_status = 'running';
            $mapping->save();
        } catch (ProxmoxProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => __($exception->errorKey),
            ]);
        }
    }

    public function terminate(ServiceInstanceInfo $instance): void
    {
        $mapping = $this->mapping($instance->id);
        if ($mapping === null) {
            return;
        }

        $api = $this->apiFor($instance);
        try {
            $api->deleteVm($mapping->node, $mapping->vmid);
        } catch (ProxmoxProviderException $exception) {
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
            $api->updateConfig($mapping->node, $mapping->vmid, array_filter([
                'cores' => $this->intSetting($settings, 'cores') ?? 1,
                'sockets' => $this->intSetting($settings, 'sockets') ?? 1,
                'memory' => $this->intSetting($settings, 'memory') ?? 1024,
                'cpu' => (string) ($settings['cpu_type'] ?? 'host'),
            ]));
        } catch (ProxmoxProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => __($exception->errorKey),
            ]);
        }
    }

    public function syncStatus(ServiceInstanceInfo $instance): ServiceInstanceInfo
    {
        $mapping = $this->mapping($instance->id);
        if ($mapping === null) {
            return $instance;
        }

        $api = $this->apiFor($instance);
        try {
            $config = $api->findVmConfig($mapping->node, $mapping->vmid);
            if ($config === null) {
                return new ServiceInstanceInfo(
                    id: $instance->id,
                    label: $instance->label,
                    status: 'terminated',
                    providerKey: $this->id(),
                    externalRef: $instance->externalRef,
                    meta: $instance->meta,
                );
            }

            $status = $api->currentStatus($mapping->node, $mapping->vmid);
        } catch (ProxmoxProviderException $exception) {
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

        $mapping->power_status = (string) ($status['status'] ?? $mapping->power_status);
        $mapping->save();

        $suspended = $mapping->power_status === 'stopped' && ! $this->boolSetting($this->providerSettings($instance), 'autostart');

        return new ServiceInstanceInfo(
            id: $instance->id,
            label: $instance->label,
            status: ProxmoxStatusMapper::lifecycleStatus($status, $suspended),
            providerKey: $this->id(),
            externalRef: (string) $mapping->vmid,
            meta: $instance->meta,
        );
    }

    public function actions(ServiceInstanceInfo $instance): array
    {
        if ($this->mapping($instance->id) === null || $instance->status !== 'active') {
            return [];
        }

        return [
            new ProvisionerAction('start', __('proxmox::messages.actions.start')),
            new ProvisionerAction('stop', __('proxmox::messages.actions.stop'), dangerous: true),
            new ProvisionerAction('reboot', __('proxmox::messages.actions.reboot'), dangerous: true),
        ];
    }

    public function runAction(ServiceInstanceInfo $instance, string $actionId): void
    {
        if (! in_array($actionId, ['start', 'stop', 'reboot'], true)) {
            throw ValidationException::withMessages([
                'action' => __('proxmox::messages.errors.action_unavailable'),
            ]);
        }

        $mapping = $this->requireMapping($instance);
        $api = $this->apiFor($instance);

        try {
            if ($actionId === 'start') {
                $api->start($mapping->node, $mapping->vmid);
            } elseif ($actionId === 'stop') {
                $api->stop($mapping->node, $mapping->vmid);
            } else {
                $api->stop($mapping->node, $mapping->vmid);
                $api->start($mapping->node, $mapping->vmid);
            }
        } catch (ProxmoxProviderException $exception) {
            throw ValidationException::withMessages([
                'action' => __($exception->errorKey),
            ]);
        }
    }

    public function panel(ServiceInstanceInfo $instance): ?ProvisionerPanelData
    {
        $mapping = $this->mapping($instance->id);
        if ($mapping === null) {
            return null;
        }

        $statusKey = 'unknown';
        try {
            $status = $this->apiFor($instance)->currentStatus($mapping->node, $mapping->vmid);
            $statusKey = ProxmoxStatusMapper::displayStatus($status);
        } catch (ProxmoxProviderException) {
            $statusKey = 'unknown';
        }

        return new ProvisionerPanelData(__('proxmox::messages.panel.title'), [
            ['label' => __('proxmox::messages.panel.status'), 'value' => __('proxmox::messages.status.'.$statusKey)],
            ['label' => __('proxmox::messages.panel.vmid'), 'value' => (string) $mapping->vmid],
            ['label' => __('proxmox::messages.panel.node'), 'value' => $mapping->node],
            ['label' => __('proxmox::messages.panel.hostname'), 'value' => $mapping->hostname],
        ]);
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

        $url = trim((string) $this->settings->get('proxmox', 'api_url', ''));
        if ($url === '') {
            return HealthResult::fail(__('proxmox::messages.health.missing_url'));
        }

        try {
            ProxmoxApiUrl::normalize($url);
        } catch (ProxmoxProviderException) {
            return HealthResult::fail(__('proxmox::messages.health.invalid_url'));
        }

        if (trim((string) $this->settings->get('proxmox', 'token_secret', '')) === '') {
            return HealthResult::fail(__('proxmox::messages.health.missing_token'));
        }

        try {
            $this->api->connectionTest();
        } catch (ProxmoxProviderException $exception) {
            $key = $exception->errorKey;
            if ($key === 'proxmox::messages.errors.unauthorized') {
                return HealthResult::fail(__('proxmox::messages.health.unauthorized'));
            }
            if ($key === 'proxmox::messages.errors.timeout') {
                return HealthResult::fail(__('proxmox::messages.health.unreachable'));
            }

            return HealthResult::fail(__('proxmox::messages.health.unreachable'));
        }

        return HealthResult::ok(__('proxmox::messages.health.ok', ['url' => $url]));
    }

    private function storeMapping(int $instanceId, int $vmid, string $node, string $hostname, string $externalId): ProxmoxVm
    {
        return ProxmoxVm::query()->updateOrCreate(
            ['service_instance_id' => $instanceId],
            [
                'vmid' => $vmid,
                'node' => $node,
                'hostname' => $hostname,
                'external_id' => $externalId,
                'power_status' => 'stopped',
            ],
        );
    }

    private function mapping(int $instanceId): ?ProxmoxVm
    {
        return ProxmoxVm::query()->where('service_instance_id', $instanceId)->first();
    }

    private function requireMapping(ServiceInstanceInfo $instance): ProxmoxVm
    {
        $mapping = $this->mapping($instance->id);
        if ($mapping === null) {
            throw ValidationException::withMessages([
                'instance' => __('proxmox::messages.errors.not_found'),
            ]);
        }

        return $mapping;
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

        return is_array($settings) ? $settings : [];
    }

    private function apiFor(ServiceInstanceInfo $instance): ProxmoxApi
    {
        return $this->api->withConnection($this->connectionSettings($instance));
    }

    private function connectionSetting(?ServiceInstanceInfo $instance, string $key, mixed $default = null): mixed
    {
        if ($instance !== null) {
            $settings = $this->connectionSettings($instance);
            if (array_key_exists($key, $settings)) {
                return $settings[$key];
            }
        }

        return $this->settings->get('proxmox', $key, $default);
    }

    private function node(?ServiceInstanceInfo $instance = null): string
    {
        return trim((string) $this->connectionSetting($instance, 'node', ''));
    }

    private function storage(?ServiceInstanceInfo $instance = null): string
    {
        return trim((string) $this->connectionSetting($instance, 'storage', ''));
    }

    private function hostname(ServiceInstanceInfo $instance): string
    {
        $label = trim($instance->label);

        return mb_substr($label !== '' ? $label : $this->externalId($instance->id), 0, 63);
    }

    private function externalId(int $instanceId): string
    {
        return 'agovena-'.$instanceId;
    }

    /** @param array<string, mixed> $settings */
    private function intSetting(array $settings, string $key): ?int
    {
        if (! isset($settings[$key]) || $settings[$key] === '') {
            return null;
        }

        return (int) $settings[$key];
    }

    /** @param array<string, mixed> $settings */
    private function boolSetting(array $settings, string $key): bool
    {
        return filter_var($settings[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function assertConfigured(ServiceInstanceInfo $instance): void
    {
        if ($this->node($instance) === '' || $this->storage($instance) === '') {
            throw ValidationException::withMessages([
                'instance' => __('proxmox::messages.errors.not_configured'),
            ]);
        }

        if (trim((string) $this->connectionSetting($instance, 'token_secret', '')) === '') {
            throw ValidationException::withMessages([
                'instance' => __('proxmox::messages.health.missing_token'),
            ]);
        }
    }
}
