<?php

declare(strict_types=1);

namespace Agovena\Extensions\Proxmox;

use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\HealthResult;
use App\Agovena\Provisioning\Contracts\ChecksProvisioningStock;
use App\Agovena\Provisioning\Contracts\ChecksProvisioningStockVector;
use App\Agovena\Provisioning\Contracts\ConfiguresProvisionedProducts;
use App\Agovena\Provisioning\Contracts\ConfiguresProvisioningServers;
use App\Agovena\Provisioning\Contracts\ProvidesProvisioningCapacityRequirements;
use App\Agovena\Provisioning\Contracts\Provisioner;
use App\Agovena\Provisioning\Contracts\ProvisionerActions;
use App\Agovena\Provisioning\Contracts\ProvisionerLifecycle;
use App\Agovena\Provisioning\Contracts\ProvisionerPanel;
use App\Agovena\Provisioning\ProvisionerAction;
use App\Agovena\Provisioning\ProvisionerPanelData;
use App\Agovena\Provisioning\ProvisioningStockContext;
use App\Agovena\Provisioning\ServiceInstanceInfo;
use App\Models\ProvisioningServer;
use Illuminate\Validation\ValidationException;

final class ProxmoxProvisioner implements ChecksProvisioningStock, ChecksProvisioningStockVector, ConfiguresProvisionedProducts, ConfiguresProvisioningServers, ProvidesProvisioningCapacityRequirements, Provisioner, ProvisionerActions, ProvisionerLifecycle, ProvisionerPanel
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
        if (! $this->hasRequiredServerSettings($settings, ['api_url', 'token_user', 'token_id', 'token_secret', 'node', 'storage'])) {
            return HealthResult::fail('proxmox::messages.errors.invalid_mapping');
        }
        if (! is_string($settings['node'] ?? null) || trim($settings['node']) === ''
            || ! is_string($settings['storage'] ?? null) || trim($settings['storage']) === ''
        ) {
            return HealthResult::fail('proxmox::messages.errors.invalid_mapping');
        }

        try {
            $this->boolSetting($settings, 'verify_tls', true);
            $api = $this->api->withConnection($settings);
            $api->connectionTest();
            $capacity = $api->nodeCapacity(
                trim((string) $settings['node']),
                trim((string) $settings['storage']),
            );
            if ($this->numericCapacity($capacity['memory_free']) === null
                || $this->numericCapacity($capacity['cpu_cores']) === null
                || $this->numericCapacity($capacity['storage_free']) === null
            ) {
                return HealthResult::fail('proxmox::messages.errors.malformed');
            }

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

    public function capacityKey(ProvisioningStockContext $context): string
    {
        return $this->capacityKeyForSettings(
            $context->providerSettings,
            $context->serverId,
            $context->serverSettings,
        );
    }

    public function capacityKeyForSettings(
        array $providerSettings,
        ?int $serverId = null,
        ?array $serverSettings = null,
    ): string {
        if ($serverId !== null && ! is_array($serverSettings)) {
            return '';
        }

        $settings = $serverSettings ?? [];
        if ($serverId === null && $settings === []) {
            $settings = [
                'api_url' => $this->settings->get('proxmox', 'api_url', ''),
                'node' => $this->settings->get('proxmox', 'node', ''),
                'storage' => $this->settings->get('proxmox', 'storage', ''),
                'verify_tls' => $this->settings->get('proxmox', 'verify_tls', true),
            ];
        }
        $apiUrl = trim((string) ($settings['api_url'] ?? ($serverId === null ? $this->settings->get('proxmox', 'api_url', '') : '')));
        $node = trim((string) ($settings['node'] ?? ($serverId === null ? $this->settings->get('proxmox', 'node', '') : '')));
        $storage = trim((string) ($settings['storage'] ?? ($serverId === null ? $this->settings->get('proxmox', 'storage', '') : '')));
        if ($apiUrl === '' || filter_var($apiUrl, FILTER_VALIDATE_URL) === false || $node === '' || $storage === '') {
            return '';
        }

        try {
            $this->boolSetting($settings, 'verify_tls', true);
            $canonicalApiUrl = ProxmoxApiUrl::normalize($apiUrl);
        } catch (ProxmoxProviderException) {
            return '';
        }

        return 'proxmox:endpoint-'.hash('sha256', $canonicalApiUrl).':node-'.$node.':storage-'.$storage;
    }

    public function capacityRequirements(array $providerSettings, ?array $serverSettings = null): array
    {
        return [
            'cores' => $this->intSetting($providerSettings, 'cores') ?? 0,
            'cpu_type' => trim((string) ($providerSettings['cpu_type'] ?? '')),
            'disk' => $this->intSetting($providerSettings, 'disk') ?? 0,
            'memory' => $this->intSetting($providerSettings, 'memory') ?? 0,
            'sockets' => $this->intSetting($providerSettings, 'sockets') ?? 0,
            'storage' => trim((string) (($serverSettings ?? [])['storage'] ?? '')),
        ];
    }

    public function assertStockVector(ProvisioningStockContext $context, array $reservedRequirements): void
    {
        $connection = $context->serverSettings ?? [];
        if (! $context->serverSettingsRequired && $connection === []) {
            $connection = $this->repositoryConnectionSettings();
        }
        $this->boolSetting($connection, 'verify_tls', true);
        if ($context->serverSettingsRequired && ! $this->hasRequiredServerSettings($connection, ['api_url', 'token_user', 'token_id', 'token_secret', 'node', 'storage'])) {
            throw ValidationException::withMessages([
                'cart' => __('proxmox::messages.errors.invalid_mapping'),
            ]);
        }
        $node = trim((string) ($connection['node'] ?? $this->settings->get('proxmox', 'node', '')));
        $storage = trim((string) ($connection['storage'] ?? $this->settings->get('proxmox', 'storage', '')));
        $requirements = $this->capacityRequirements($context->providerSettings, $connection);
        $cores = (int) $requirements['cores'];
        $memory = (int) $requirements['memory'];
        $disk = (int) $requirements['disk'];
        if ($node === '' || $storage === '' || $cores < 1 || $memory < 1 || $disk < 1) {
            throw ValidationException::withMessages([
                'cart' => __('proxmox::messages.errors.invalid_mapping'),
            ]);
        }

        try {
            $capacity = $this->api->withConnection($connection)->nodeCapacity($node, $storage);
        } catch (ProxmoxProviderException $exception) {
            throw ValidationException::withMessages([
                'cart' => __($exception->errorKey),
            ]);
        }
        $memoryFree = $this->numericCapacity($capacity['memory_free']);
        $cpuCores = $this->numericCapacity($capacity['cpu_cores']);
        $storageFree = $this->numericCapacity($capacity['storage_free']);
        if ($memoryFree === null || $cpuCores === null || $storageFree === null) {
            throw ValidationException::withMessages([
                'cart' => __('proxmox::messages.errors.malformed'),
            ]);
        }

        $requiredMemory = (float) ($reservedRequirements['memory'] ?? 0) + ($memory * $context->quantity());
        $requiredCores = (float) ($reservedRequirements['cores'] ?? 0) + ($cores * $context->quantity());
        $requiredDisk = (float) ($reservedRequirements['disk'] ?? 0) + ($disk * $context->quantity());
        if ($memoryFree < $requiredMemory * 1024 * 1024
            || $cpuCores < $requiredCores
            || $storageFree < $requiredDisk * 1024 * 1024 * 1024
        ) {
            throw ValidationException::withMessages([
                'cart' => __('proxmox::messages.errors.out_of_stock'),
            ]);
        }
    }

    public function assertStock(ProvisioningStockContext $context, int $reservedQuantity = 0): void
    {
        $connection = $context->serverSettings ?? [];
        if (! $context->serverSettingsRequired && $connection === []) {
            $connection = $this->repositoryConnectionSettings();
        }
        $this->boolSetting($connection, 'verify_tls', true);
        if ($context->serverSettingsRequired && ! $this->hasRequiredServerSettings($connection, ['api_url', 'token_user', 'token_id', 'token_secret', 'node', 'storage'])) {
            throw ValidationException::withMessages([
                'cart' => __('proxmox::messages.errors.invalid_mapping'),
            ]);
        }

        $node = trim((string) ($connection['node'] ?? $this->settings->get('proxmox', 'node', '')));
        $storage = trim((string) ($connection['storage'] ?? $this->settings->get('proxmox', 'storage', '')));
        $cores = $this->intSetting($context->providerSettings, 'cores') ?? 1;
        $memory = $this->intSetting($context->providerSettings, 'memory') ?? 0;
        $disk = $this->intSetting($context->providerSettings, 'disk') ?? 0;
        if ($node === '' || $storage === '' || $cores < 1 || $memory < 1 || $disk < 1) {
            throw ValidationException::withMessages([
                'cart' => __('proxmox::messages.errors.invalid_mapping'),
            ]);
        }

        try {
            $capacity = $this->api->withConnection($connection)->nodeCapacity($node, $storage);
        } catch (ProxmoxProviderException $exception) {
            throw ValidationException::withMessages([
                'cart' => __($exception->errorKey),
            ]);
        }

        $memoryFree = $this->numericCapacity($capacity['memory_free']);
        $cpuCores = $this->numericCapacity($capacity['cpu_cores']);
        $storageFree = $this->numericCapacity($capacity['storage_free']);
        if ($memoryFree === null || $cpuCores === null || $storageFree === null) {
            throw ValidationException::withMessages([
                'cart' => __('proxmox::messages.errors.malformed'),
            ]);
        }

        $memoryUnits = (int) floor($memoryFree / ($memory * 1024 * 1024));
        $cpuUnits = (int) floor($cpuCores / $cores);
        $diskUnits = (int) floor($storageFree / ($disk * 1024 * 1024 * 1024));
        $available = min($memoryUnits, $cpuUnits, $diskUnits);
        if ($available < max(0, $reservedQuantity) + $context->quantity()) {
            throw ValidationException::withMessages([
                'cart' => __('proxmox::messages.errors.out_of_stock'),
            ]);
        }
    }

    private function numericCapacity(mixed $value): ?float
    {
        if (! (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
            return null;
        }

        $numeric = (float) $value;

        return is_finite($numeric) && $numeric >= 0 ? $numeric : null;
    }

    public function provision(ServiceInstanceInfo $instance): void
    {
        $this->assertConfigured($instance);
        $api = $this->apiFor($instance);
        $settings = $this->providerSettings($instance);
        $mapping = $this->mapping($instance->id);
        if ($mapping !== null) {
            try {
                $this->ensureVmConfiguration($api, $instance, $mapping->node, $mapping->vmid, $settings);
            } catch (ProxmoxProviderException $exception) {
                throw ValidationException::withMessages([
                    'instance' => __($exception->errorKey),
                ]);
            }

            return;
        }

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
            $existing = $api->findVmByName($node, $hostname);
            if ($existing !== null) {
                $existingVmid = $this->canonicalPositiveInteger($existing['vmid'] ?? null);
                if ($existingVmid === null) {
                    throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
                }
                // Only a VM carrying this instance's ownership marker is ever adopted.
                $this->assertOwnedVmConfig($api, $instance->id, $node, $existingVmid);
                $this->storeMapping($instance->id, $existingVmid, $node, $hostname, $externalId);
                $this->ensureVmConfiguration($api, $instance, $node, $existingVmid, $settings);

                return;
            }

            $vmid = max($api->nextVmId(), 100);
            // The ownership marker is part of the clone request, so a VM whose clone task outlived
            // the HTTP request or the poll budget is still recognisable as ours on retry.
            $api->cloneVm($node, $templateVmid, [
                'newid' => $vmid,
                'name' => $hostname,
                'description' => $this->ownershipMarker($instance->id),
                'full' => 1,
                'storage' => $storage,
                'target' => $node,
            ]);
            $this->storeMapping($instance->id, $vmid, $node, $hostname, $externalId);
            $this->ensureVmConfiguration($api, $instance, $node, $vmid, $settings);
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
        $this->runAction($instance, 'start');
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        $mapping = $this->requireMapping($instance);
        $api = $this->apiFor($instance);

        try {
            $this->assertOwnedVmConfig($api, $instance->id, $mapping->node, $mapping->vmid);
            $api->stop($mapping->node, $mapping->vmid);
            $api->updateConfig($mapping->node, $mapping->vmid, ['onboot' => 0]);
            $this->assertPowerState($api, $mapping->node, $mapping->vmid, 'stopped');
            $this->assertConfigValue($api, $mapping->node, $mapping->vmid, 'onboot', 0);
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
            $this->assertOwnedVmConfig($api, $instance->id, $mapping->node, $mapping->vmid);
            $api->updateConfig($mapping->node, $mapping->vmid, ['onboot' => 1]);
            $this->startIfNotRunning($api, $mapping->node, $mapping->vmid);
            $this->assertConfigValue($api, $mapping->node, $mapping->vmid, 'onboot', 1);
            $this->assertPowerState($api, $mapping->node, $mapping->vmid, 'running');
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
        $api = $this->apiFor($instance);

        try {
            if ($mapping === null) {
                $this->terminateUnmappedOwnedVm($api, $instance);

                return;
            }

            $config = $api->findVmConfig($mapping->node, $mapping->vmid);
            if ($config !== null) {
                if (($config['description'] ?? null) !== $this->ownershipMarker($instance->id)) {
                    throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
                }
                $this->deleteAndVerify($api, $mapping->node, $mapping->vmid);
            }
        } catch (ProxmoxProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => __($exception->errorKey),
            ]);
        }

        $mapping->delete();
    }

    /**
     * A clone can succeed while the mapping write never happens (timeout, crash). Such a VM is
     * found by its deterministic name and deleted only when it carries this instance's marker.
     */
    private function terminateUnmappedOwnedVm(ProxmoxApi $api, ServiceInstanceInfo $instance): void
    {
        $node = $this->node($instance);
        if ($node === '') {
            return;
        }

        $existing = $api->findVmByName($node, $this->hostname($instance));
        if ($existing === null) {
            return;
        }
        $vmid = $this->canonicalPositiveInteger($existing['vmid'] ?? null);
        if ($vmid === null) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
        }

        $config = $api->findVmConfig($node, $vmid);
        if ($config === null || ($config['description'] ?? null) !== $this->ownershipMarker($instance->id)) {
            return;
        }

        $this->deleteAndVerify($api, $node, $vmid);
    }

    private function deleteAndVerify(ProxmoxApi $api, string $node, int $vmid): void
    {
        $api->deleteVm($node, $vmid);
        if ($api->findVmConfig($node, $vmid) !== null) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
        }
    }

    /** @param string|array<string, mixed> $plan */
    public function changePlan(ServiceInstanceInfo $instance, string|array $plan): void
    {
        $planId = is_array($plan) ? (string) ($plan['id'] ?? '') : $plan;
        if (trim($planId) === '') {
            throw ValidationException::withMessages([
                'instance' => __('proxmox::messages.errors.provider_failed'),
            ]);
        }
        $mapping = $this->requireMapping($instance);
        $settings = is_array($plan['provider_settings'] ?? null)
            ? $plan['provider_settings']
            : $this->providerSettings($instance);
        $api = $this->apiFor($instance);

        try {
            $this->ensureVmConfiguration($api, $instance, $mapping->node, $mapping->vmid, $settings);
        } catch (ProxmoxProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => __($exception->errorKey),
            ]);
        }
    }

    public function syncStatus(ServiceInstanceInfo $instance): ServiceInstanceInfo
    {
        $api = $this->apiFor($instance);
        $mapping = $this->mapping($instance->id);
        if ($mapping === null) {
            try {
                $existing = $api->findVmByName($this->node($instance), $this->hostname($instance));
                if ($existing === null) {
                    return new ServiceInstanceInfo(
                        id: $instance->id,
                        label: $instance->label,
                        status: $instance->status,
                        providerKey: $instance->providerKey,
                        externalRef: $instance->externalRef,
                        meta: array_merge($instance->meta, ['provider_reconciliation' => 'absent']),
                        serverSettings: $instance->serverSettings,
                        providerSettings: $instance->providerSettings,
                    );
                }
                if (! $this->canonicalPositiveInteger($existing['vmid'] ?? null)) {
                    throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
                }
                $existingNode = trim((string) ($existing['node'] ?? $this->node($instance)));
                if ($existingNode !== $this->node($instance)) {
                    throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
                }
                $existingVmid = $this->canonicalPositiveInteger($existing['vmid']);
                $this->assertOwnedVmConfig($api, $instance->id, $existingNode, $existingVmid);
                $mapping = $this->storeMapping(
                    $instance->id,
                    $existingVmid,
                    $existingNode,
                    $this->hostname($instance),
                    $this->externalId($instance->id),
                );
            } catch (ProxmoxProviderException $exception) {
                throw ValidationException::withMessages([
                    'instance' => __($exception->errorKey),
                ]);
            }
        }

        // Sync is strictly read-only towards Proxmox: it never reconfigures, starts or stops a VM,
        // so a suspended or customer-stopped VM keeps its power state.
        try {
            $config = $api->findVmConfig($mapping->node, $mapping->vmid);
            if ($config === null) {
                $mapping->delete();

                return new ServiceInstanceInfo(
                    id: $instance->id,
                    label: $instance->label,
                    status: 'terminated',
                    providerKey: $this->id(),
                    externalRef: $instance->externalRef,
                    meta: array_merge($instance->meta, ['provider_reconciliation' => 'absent']),
                    serverSettings: $instance->serverSettings,
                    providerSettings: $instance->providerSettings,
                );
            }
            if (($config['description'] ?? null) !== $this->ownershipMarker($instance->id)) {
                throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
            }

            $status = $api->currentStatus($mapping->node, $mapping->vmid);
        } catch (ProxmoxProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => __($exception->errorKey),
            ]);
        }

        $mapping->power_status = (string) ($status['status'] ?? $mapping->power_status);
        $mapping->save();

        $lifecycle = ProxmoxStatusMapper::lifecycleStatus($status, $instance->status === 'suspended');
        if ($lifecycle === 'active'
            && $instance->status !== 'active'
            && ! $this->vmConfigurationMatches($instance, $config, $this->providerSettings($instance))
        ) {
            // The VM exists but was never fully configured (for example a clone that timed out):
            // keep the current lifecycle state so provisioning is retried instead of activated.
            $lifecycle = $instance->status;
        }

        return new ServiceInstanceInfo(
            id: $instance->id,
            label: $instance->label,
            status: $lifecycle,
            providerKey: $this->id(),
            externalRef: (string) $mapping->vmid,
            meta: array_merge($instance->meta, [
                'provider_mapping' => [
                    'node' => (string) $mapping->node,
                    'vmid' => (int) $mapping->vmid,
                ],
            ]),
            serverSettings: $instance->serverSettings,
            providerSettings: $instance->providerSettings,
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
            $this->assertOwnedVmConfig($api, $instance->id, $mapping->node, $mapping->vmid);
            if ($actionId === 'start') {
                $this->startIfNotRunning($api, $mapping->node, $mapping->vmid);
            } elseif ($actionId === 'stop') {
                $api->stop($mapping->node, $mapping->vmid);
            } else {
                $api->stop($mapping->node, $mapping->vmid);
                $api->start($mapping->node, $mapping->vmid);
            }
            $expectedStatus = $actionId === 'stop' ? 'stopped' : 'running';
            $this->assertPowerState($api, $mapping->node, $mapping->vmid, $expectedStatus);
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

        foreach (['token_user', 'token_id', 'node', 'storage'] as $key) {
            if (trim((string) $this->settings->get('proxmox', $key, '')) === '') {
                return HealthResult::fail(__('proxmox::messages.errors.not_configured'));
            }
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

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $currentConfig
     * @return array<string, int|string>
     */
    private function desiredVmConfig(ServiceInstanceInfo $instance, array $settings, array $currentConfig): array
    {
        $networkKey = $this->networkConfigKey($currentConfig);
        if ($networkKey === null) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
        }
        $keepPoweredOff = $instance->status === 'suspended';

        return [
            'cores' => $this->intSetting($settings, 'cores') ?? 1,
            'sockets' => $this->intSetting($settings, 'sockets') ?? 1,
            'memory' => $this->intSetting($settings, 'memory') ?? 1024,
            'cpu' => trim((string) ($settings['cpu_type'] ?? '')) !== '' ? trim((string) $settings['cpu_type']) : 'host',
            $networkKey => $this->networkConfigValue(
                $currentConfig[$networkKey] ?? null,
                trim((string) ($settings['bridge'] ?? '')) !== '' ? trim((string) $settings['bridge']) : 'vmbr0',
            ),
            'onboot' => ! $keepPoweredOff && $this->boolSetting($settings, 'autostart') ? 1 : 0,
            'description' => $this->ownershipMarker($instance->id),
        ];
    }

    /** @param array<string, mixed> $settings */
    private function ensureVmConfiguration(ProxmoxApi $api, ServiceInstanceInfo $instance, string $node, int $vmid, array $settings): void
    {
        if ($this->node($instance) === '' || $this->node($instance) !== $node) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
        }

        $config = $api->findVmConfig($node, $vmid);
        if ($config === null) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.not_found', 404);
        }

        $marker = $this->ownershipMarker($instance->id);
        if (($config['description'] ?? null) !== $marker) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
        }

        $desired = $this->desiredVmConfig($instance, $settings, $config);
        foreach ($desired as $key => $value) {
            if (! $this->sameVmConfigValue($config[$key] ?? null, $value)) {
                $api->updateConfig($node, $vmid, $desired);
                break;
            }
        }

        // Disk growth goes through the dedicated resize endpoint; editing size= in the config
        // only rewrites metadata. Proxmox VE cannot shrink disks, so a smaller target fails closed.
        $diskKey = $this->bootDiskKey($config);
        $currentBytes = $this->diskSizeBytes($config[$diskKey] ?? null);
        $desiredGb = $this->desiredDiskGb($settings);
        $this->assertDiskStorage($config[$diskKey] ?? null, $this->storage($instance));
        if ($currentBytes > $desiredGb * 1024 ** 3) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
        }
        if ($currentBytes < $desiredGb * 1024 ** 3) {
            $api->resizeDisk($node, $vmid, $diskKey, $desiredGb.'G');
        }

        $verified = $api->findVmConfig($node, $vmid);
        if ($verified === null || ! $this->vmConfigurationMatches($instance, $verified, $settings)) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
        }

        if ($instance->status !== 'suspended' && $this->boolSetting($settings, 'autostart')) {
            $this->startIfNotRunning($api, $node, $vmid);
            $this->assertPowerState($api, $node, $vmid, 'running');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $settings
     */
    private function vmConfigurationMatches(ServiceInstanceInfo $instance, array $config, array $settings): bool
    {
        try {
            if (($config['description'] ?? null) !== $this->ownershipMarker($instance->id)) {
                return false;
            }
            foreach ($this->desiredVmConfig($instance, $settings, $config) as $key => $value) {
                if (! $this->sameVmConfigValue($config[$key] ?? null, $value)) {
                    return false;
                }
            }
            $diskKey = $this->bootDiskKey($config);

            return $this->diskSizeBytes($config[$diskKey] ?? null) === $this->desiredDiskGb($settings) * 1024 ** 3;
        } catch (ProxmoxProviderException) {
            return false;
        }
    }

    private function startIfNotRunning(ProxmoxApi $api, string $node, int $vmid): void
    {
        // Proxmox VE fails a start task with "VM <vmid> already running", so retries must skip it.
        if (strtolower((string) ($api->currentStatus($node, $vmid)['status'] ?? '')) !== 'running') {
            $api->start($node, $vmid);
        }
    }

    /** @param array<string, mixed> $settings */
    private function desiredDiskGb(array $settings): int
    {
        $disk = $this->intSetting($settings, 'disk') ?? 20;
        if ($disk < 1) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.invalid_mapping');
        }

        return $disk;
    }

    /**
     * The disk to size is the first disk in the boot order that is not a CD-ROM or cloud-init
     * drive; without a usable boot order the lowest numbered scsi, virtio, sata, ide disk is used.
     *
     * @param  array<string, mixed>  $config
     */
    private function bootDiskKey(array $config): string
    {
        $candidates = [];
        $boot = is_string($config['boot'] ?? null) ? $config['boot'] : '';
        if (preg_match('/(?:\A|,)order=([^,]*)/', $boot, $matches) === 1) {
            $candidates = explode(';', $matches[1]);
        }
        foreach (['scsi', 'virtio', 'sata', 'ide'] as $bus) {
            $keys = array_filter(
                array_keys($config),
                static fn ($key): bool => preg_match('/\A'.$bus.'\d+\z/', (string) $key) === 1,
            );
            usort($keys, static fn (string $left, string $right): int => (int) substr($left, strlen($bus)) <=> (int) substr($right, strlen($bus)));
            array_push($candidates, ...$keys);
        }

        foreach ($candidates as $key) {
            $key = trim((string) $key);
            if (preg_match('/\A(?:scsi|virtio|sata|ide)\d+\z/', $key) !== 1 || ! is_string($config[$key] ?? null)) {
                continue;
            }
            $value = strtolower($config[$key]);
            if (str_contains($value, 'media=cdrom') || str_contains($value, 'cloudinit') || str_starts_with($value, 'none')) {
                continue;
            }

            return $key;
        }

        throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
    }

    private function diskSizeBytes(mixed $value): int
    {
        if (! is_string($value)
            || preg_match('/(?:\A|,)size=(\d+(?:\.\d+)?)([KMGT]?)(?:,|\z)/i', trim($value), $matches) !== 1
        ) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
        }

        $exponent = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4][strtoupper($matches[2])];

        return (int) round((float) $matches[1] * 1024 ** $exponent);
    }

    private function assertDiskStorage(mixed $value, string $storage): void
    {
        $descriptor = is_string($value) ? trim(explode(',', $value, 2)[0]) : '';
        $currentStorage = trim(explode(':', $descriptor, 2)[0]);
        if ($storage === '' || $currentStorage === '' || $currentStorage !== $storage) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
        }
    }

    private function assertPowerState(ProxmoxApi $api, string $node, int $vmid, string $expected): void
    {
        $status = $api->currentStatus($node, $vmid);
        if (strtolower((string) ($status['status'] ?? '')) !== $expected) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
        }
    }

    private function assertConfigValue(ProxmoxApi $api, string $node, int $vmid, string $key, int $expected): void
    {
        $config = $api->findVmConfig($node, $vmid);
        if ($config === null || ! $this->sameVmConfigValue($config[$key] ?? null, $expected)) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
        }
    }

    private function sameVmConfigValue(mixed $actual, mixed $expected): bool
    {
        $actualInteger = $this->canonicalInteger($actual);
        $expectedInteger = $this->canonicalInteger($expected);
        if ($actualInteger !== null && $expectedInteger !== null) {
            return $actualInteger === $expectedInteger;
        }

        return (string) $actual === (string) $expected;
    }

    /** @param array<string, mixed> $config */
    private function networkConfigKey(array $config): ?string
    {
        foreach (array_keys($config) as $key) {
            if (preg_match('/\Anet\d+\z/', (string) $key) === 1) {
                return $key;
            }
        }

        return null;
    }

    private function networkConfigValue(mixed $current, string $bridge): string
    {
        if (! is_string($current) || trim($current) === '' || $bridge === '') {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.invalid_mapping');
        }

        $parts = explode(',', trim($current));
        $hasBridge = false;
        foreach ($parts as $index => $part) {
            if (str_starts_with(strtolower(trim($part)), 'bridge=')) {
                $parts[$index] = 'bridge='.$bridge;
                $hasBridge = true;
            }
        }
        if (! $hasBridge) {
            $parts[] = 'bridge='.$bridge;
        }

        return implode(',', $parts);
    }

    private function ownershipMarker(int $instanceId): string
    {
        return 'agovena-service-instance:'.$instanceId;
    }

    private function assertOwnedVmConfig(ProxmoxApi $api, int $instanceId, string $node, int $vmid): void
    {
        $config = $api->findVmConfig($node, $vmid);
        if ($config === null || ($config['description'] ?? null) !== $this->ownershipMarker($instanceId)) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
        }
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
        return $instance->providerSettings ?? [];
    }

    /** @return array<string, mixed> */
    private function connectionSettings(ServiceInstanceInfo $instance): array
    {
        $settings = $instance->serverSettings ?? [];
        if (($instance->meta['server_settings_required'] ?? false) === true
            && ! $this->hasRequiredServerSettings($settings, ['api_url', 'token_user', 'token_id', 'token_secret', 'node', 'storage'])
        ) {
            throw ValidationException::withMessages([
                'instance' => __('proxmox::messages.errors.not_configured'),
            ]);
        }

        return $settings !== [] ? $settings : $this->repositoryConnectionSettings();
    }

    /** @return array<string, mixed> */
    private function repositoryConnectionSettings(): array
    {
        $settings = [];
        foreach ($this->serverSettings() as $definition) {
            $settings[$definition->key] = $this->settings->get('proxmox', $definition->key, $definition->default);
        }

        return $settings;
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
            if (($instance->meta['server_settings_required'] ?? false) === true) {
                return $default;
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
        return $this->externalId($instance->id);
    }

    private function externalId(int $instanceId): string
    {
        return 'agovena-'.$instanceId;
    }

    /** @param array<string, mixed> $settings */
    private function intSetting(array $settings, string $key): ?int
    {
        if (! array_key_exists($key, $settings) || $settings[$key] === null || $settings[$key] === '') {
            return null;
        }

        return $this->canonicalInteger($settings[$key]);
    }

    /** @param array<string, mixed> $settings @param list<string> $keys */
    private function hasRequiredServerSettings(array $settings, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! is_string($settings[$key] ?? null) || trim($settings[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $settings */
    private function boolSetting(array $settings, string $key, bool $default = false): bool
    {
        $value = $settings[$key] ?? $default;
        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
        }

        return $parsed;
    }

    private function canonicalPositiveInteger(mixed $value): ?int
    {
        return $this->canonicalInteger($value, 1);
    }

    private function canonicalInteger(mixed $value, int $minimum = 0): ?int
    {
        if (is_int($value)) {
            return $value >= $minimum ? $value : null;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return $value >= $minimum && $value <= PHP_INT_MAX ? (int) $value : null;
        }
        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);

        return $integer === false ? null : $integer;
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
