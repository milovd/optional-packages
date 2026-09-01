<?php

declare(strict_types=1);

namespace Agovena\Extensions\Pterodactyl;

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

final class PterodactylProvisioner implements ChecksProvisioningStock, ChecksProvisioningStockVector, ConfiguresProvisionedProducts, ConfiguresProvisioningServers, ProvidesProvisioningCapacityRequirements, Provisioner, ProvisionerActions, ProvisionerLifecycle, ProvisionerPanel
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
        if (! $this->hasRequiredServerSettings($settings, ['panel_url', 'application_api_key', 'user_id'])) {
            return HealthResult::fail('pterodactyl::messages.errors.invalid_mapping');
        }

        try {
            PterodactylPanelUrl::normalize($settings['panel_url']);
            if ($this->positiveInteger($settings['user_id']) === null) {
                return HealthResult::fail('pterodactyl::messages.errors.invalid_mapping');
            }
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
            new ExtensionSettingDefinition('environment', 'pterodactyl::messages.product.environment', type: 'text', secret: true, help: 'pterodactyl::messages.product.environment_help'),
            new ExtensionSettingDefinition('dedicated_ip', 'pterodactyl::messages.product.dedicated_ip', default: '0', help: 'pterodactyl::messages.product.dedicated_ip_help'),
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
        $locationId = $this->intSetting($providerSettings, 'location_id');
        if ($locationId === null || $locationId < 1) {
            return '';
        }

        $panelUrl = is_array($serverSettings)
            ? trim((string) ($serverSettings['panel_url'] ?? ''))
            : trim((string) $this->settings->get('pterodactyl', 'panel_url', ''));
        if ($panelUrl === '') {
            return '';
        }

        try {
            $panelUrl = PterodactylPanelUrl::normalize($panelUrl);
        } catch (PterodactylProviderException) {
            return '';
        }

        return 'pterodactyl:panel-'.hash('sha256', $panelUrl).':location-'.$locationId;
    }

    public function capacityRequirements(array $providerSettings, ?array $serverSettings = null): array
    {
        unset($serverSettings);

        return [
            'disk' => $this->intSetting($providerSettings, 'disk') ?? 0,
            'memory' => $this->intSetting($providerSettings, 'memory') ?? 0,
        ];
    }

    public function assertStockVector(ProvisioningStockContext $context, array $reservedRequirements): void
    {
        $connection = $context->serverSettings ?? [];
        if ($context->serverSettingsRequired && ! $this->hasRequiredServerSettings($connection, ['panel_url', 'application_api_key', 'user_id'])) {
            throw ValidationException::withMessages([
                'cart' => __('pterodactyl::messages.errors.invalid_mapping'),
            ]);
        }

        $locationId = $this->intSetting($context->providerSettings, 'location_id');
        $nestId = $this->intSetting($context->providerSettings, 'nest_id');
        $eggId = $this->intSetting($context->providerSettings, 'egg_id');
        $requirements = $this->capacityRequirements($context->providerSettings, $context->serverSettings);
        if ($locationId === null || $locationId < 1 || $nestId === null || $nestId < 1 || $eggId === null || $eggId < 1 || $requirements['memory'] < 1 || $requirements['disk'] < 1) {
            throw ValidationException::withMessages([
                'cart' => __('pterodactyl::messages.errors.invalid_mapping'),
            ]);
        }

        $requiredMemory = (int) ($reservedRequirements['memory'] ?? 0) + ((int) $requirements['memory'] * $context->quantity());
        $requiredDisk = (int) ($reservedRequirements['disk'] ?? 0) + ((int) $requirements['disk'] * $context->quantity());
        try {
            $api = $this->api->withConnection($connection);
            $nodes = $api->getDeployableNodes($locationId, (int) $requirements['memory'], (int) $requirements['disk']);
        } catch (PterodactylProviderException $exception) {
            throw ValidationException::withMessages([
                'cart' => __($exception->errorKey),
            ]);
        }
        if (! is_array($nodes) || $nodes === []) {
            throw ValidationException::withMessages([
                'cart' => __('pterodactyl::messages.errors.out_of_stock'),
            ]);
        }
        $availableUnits = 0;
        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_numeric($node['capacity'] ?? null) || (float) $node['capacity'] < 1) {
                throw ValidationException::withMessages([
                    'cart' => __('pterodactyl::messages.errors.malformed'),
                ]);
            }
            $availableUnits += (int) $node['capacity'];
        }
        if ($availableUnits < $context->quantity()
            || ($availableUnits * (int) $requirements['memory']) < $requiredMemory
            || ($availableUnits * (int) $requirements['disk']) < $requiredDisk
        ) {
            throw ValidationException::withMessages([
                'cart' => __('pterodactyl::messages.errors.out_of_stock'),
            ]);
        }
    }

    public function assertStock(ProvisioningStockContext $context, int $reservedQuantity = 0): void
    {
        $connection = $context->serverSettings ?? [];
        if ($context->serverSettingsRequired && ! $this->hasRequiredServerSettings($connection, ['panel_url', 'application_api_key', 'user_id'])) {
            throw ValidationException::withMessages([
                'cart' => __('pterodactyl::messages.errors.invalid_mapping'),
            ]);
        }

        $locationId = $this->intSetting($context->providerSettings, 'location_id');
        $memory = $this->intSetting($context->providerSettings, 'memory');
        $disk = $this->intSetting($context->providerSettings, 'disk');
        $nestId = $this->intSetting($context->providerSettings, 'nest_id');
        $eggId = $this->intSetting($context->providerSettings, 'egg_id');
        if ($locationId === null || $locationId < 1 || $nestId === null || $nestId < 1 || $eggId === null || $eggId < 1 || $memory === null || $memory < 1 || $disk === null || $disk < 1) {
            throw ValidationException::withMessages([
                'cart' => __('pterodactyl::messages.errors.invalid_mapping'),
            ]);
        }

        try {
            $nodes = $this->api->withConnection($context->serverSettings ?? [])->getDeployableNodes($locationId, $memory, $disk);
        } catch (PterodactylProviderException $exception) {
            throw ValidationException::withMessages([
                'cart' => __($exception->errorKey),
            ]);
        }

        $available = 0;
        foreach ($nodes as $node) {
            $capacity = $node['capacity'] ?? null;
            if (! is_int($capacity) && ! (is_string($capacity) && ctype_digit($capacity))) {
                throw ValidationException::withMessages([
                    'cart' => __('pterodactyl::messages.errors.malformed'),
                ]);
            }
            $available += (int) $capacity;
        }

        if ($available < max(0, $reservedQuantity) + $context->quantity()) {
            throw ValidationException::withMessages([
                'cart' => __('pterodactyl::messages.errors.out_of_stock'),
            ]);
        }
    }

    public function provision(ServiceInstanceInfo $instance): void
    {
        $this->assertConfigured($instance);
        $api = $this->apiFor($instance);
        $externalId = $this->externalId($instance->id);

        try {
            $mapping = $this->mapping($instance->id);
            if ($mapping !== null) {
                $this->assertOwnedMapping($api, $instance, $mapping);

                return;
            }

            $existing = $api->findServerByExternalId($externalId);
            if ($existing !== null) {
                $existingId = $this->serverId($existing);
                if ($existingId === null) {
                    throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
                }
                $existingDetails = $this->serverNodeId($existing) === null ? $api->getServer($existingId) : $existing;
                $existingNodeId = $this->serverNodeId($existingDetails);
                if ($existingNodeId === null) {
                    throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
                }
                $mapping = $this->storeMapping($instance->id, $existingDetails, $externalId, $existingNodeId);
                try {
                    $this->assertOwnedMapping($api, $instance, $mapping);
                } catch (PterodactylProviderException $exception) {
                    if ($exception->status === 0) {
                        $mapping->delete();
                    }
                    throw $exception;
                }

                return;
            }

            $settings = $this->settingsWithProductDefaults($this->providerSettings($instance));
            $locationId = $this->intSetting($settings, 'location_id');
            $nestId = $this->intSetting($settings, 'nest_id');
            $eggId = $this->intSetting($settings, 'egg_id');
            $memory = $this->intSetting($settings, 'memory') ?? 512;
            $disk = $this->intSetting($settings, 'disk') ?? 1024;
            if ($locationId === null || $nestId === null || $eggId === null || $memory < 1 || $disk < 1) {
                throw ValidationException::withMessages([
                    'instance' => __('pterodactyl::messages.errors.invalid_mapping'),
                ]);
            }
            $eligibleNodeIds = $this->eligibleNodeIds($api, $locationId, $memory, $disk);
            if ($eligibleNodeIds === []) {
                throw ValidationException::withMessages([
                    'instance' => __('pterodactyl::messages.errors.out_of_stock'),
                ]);
            }

            $egg = $api->getEgg($nestId, $eggId);
            $created = $api->createServer($this->createPayload($instance, $settings, $egg, $externalId, $locationId, $eggId));
            $createdId = $this->serverId($created);
            if ($createdId === null) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
            }
            $createdDetails = $this->serverNodeId($created) === null ? $api->getServer($createdId) : $created;
            $nodeId = $this->serverNodeId($createdDetails);
            if ($nodeId === null || ! in_array($nodeId, $eligibleNodeIds, true)) {
                try {
                    $api->delete($createdId);
                } catch (PterodactylProviderException $cleanupException) {
                    report($cleanupException);
                }
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.out_of_stock');
            }
            $mapping = $this->storeMapping($instance->id, $createdDetails, $externalId, $nodeId);
            $mapping->dedicated_ip = $this->boolSetting($settings, 'dedicated_ip');
            $mapping->save();
            try {
                $this->assertOwnedMapping($api, $instance, $mapping);
            } catch (PterodactylProviderException $exception) {
                if ($exception->status === 0) {
                    $mapping->delete();
                    try {
                        $api->delete($createdId);
                    } catch (PterodactylProviderException $cleanupException) {
                        report($cleanupException);
                    }
                }
                throw $exception;
            }
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
        $this->runAction($instance, 'start');
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        $api = $this->apiFor($instance);
        $this->mutate(
            $instance,
            fn (PterodactylServer $mapping) => $api->suspend($mapping->server_id),
            fn (PterodactylServer $mapping) => $this->assertSuspendedState($api->getServer($mapping->server_id), true),
        );
    }

    public function unsuspend(ServiceInstanceInfo $instance): void
    {
        $api = $this->apiFor($instance);
        $this->mutate(
            $instance,
            fn (PterodactylServer $mapping) => $api->unsuspend($mapping->server_id),
            fn (PterodactylServer $mapping) => $this->assertSuspendedState($api->getServer($mapping->server_id), false),
        );
    }

    public function terminate(ServiceInstanceInfo $instance): void
    {
        $mapping = $this->mapping($instance->id);
        if ($mapping === null) {
            return;
        }

        $api = $this->apiFor($instance);
        try {
            $this->assertOwnedMapping($api, $instance, $mapping);
            $api->delete($mapping->server_id);
            if ($api->findServerByExternalId($mapping->external_id) !== null) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
            }
        } catch (PterodactylProviderException $exception) {
            if ($exception->status !== 404) {
                throw ValidationException::withMessages([
                    'instance' => __($exception->errorKey),
                ]);
            }
        }

        $mapping->delete();
    }

    /** @param string|array<string, mixed> $plan */
    public function changePlan(ServiceInstanceInfo $instance, string|array $plan): void
    {
        $planId = is_array($plan) ? (string) ($plan['id'] ?? '') : $plan;
        if (trim($planId) === '') {
            throw ValidationException::withMessages([
                'instance' => __('pterodactyl::messages.errors.provider_failed'),
            ]);
        }
        $mapping = $this->requireMapping($instance);
        $settings = is_array($plan['provider_settings'] ?? null)
            ? $plan['provider_settings']
            : $this->providerSettings($instance);
        $settings = $this->settingsWithProductDefaults($settings);
        $api = $this->apiFor($instance);

        try {
            $this->assertOwnedMapping($api, $instance, $mapping, $settings, checkResources: false);
            $server = $api->getServer($mapping->server_id);
            $nestId = $this->intSetting($settings, 'nest_id');
            $eggId = $this->intSetting($settings, 'egg_id');
            if ($nestId === null || $eggId === null) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
            }
            $egg = $api->getEgg($nestId, $eggId);
            $dockerImage = $this->optionalString($settings, 'docker_image');
            if ($dockerImage === '') {
                $dockerImage = (string) ($egg['docker_image'] ?? '');
            }
            $startup = $this->optionalString($settings, 'startup');
            if ($startup === '') {
                $startup = (string) ($egg['startup'] ?? '');
            }
            $allocation = $this->positiveInteger($server['allocation'] ?? null);
            if ($allocation === null) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
            }
            $api->updateBuild($mapping->server_id, [
                'allocation' => $allocation,
                'memory' => $this->intSetting($settings, 'memory') ?? 512,
                'swap' => $this->intSetting($settings, 'swap') ?? 0,
                'disk' => $this->intSetting($settings, 'disk') ?? 1024,
                'io' => $this->intSetting($settings, 'io') ?? 500,
                'cpu' => $this->intSetting($settings, 'cpu') ?? 100,
                'dedicated_ip' => $this->boolSetting($settings, 'dedicated_ip'),
                'feature_limits' => [
                    'databases' => $this->intSetting($settings, 'databases') ?? 0,
                    'allocations' => $this->intSetting($settings, 'allocations') ?? 1,
                    'backups' => $this->intSetting($settings, 'backups') ?? 0,
                ],
            ]);
            $api->updateStartup($mapping->server_id, [
                'image' => $dockerImage,
                'startup' => $startup,
                'environment' => $this->environment($settings, $egg),
            ]);
            $updatedServer = $api->getServer($mapping->server_id);
            if ($this->positiveInteger($updatedServer['allocation'] ?? null) !== $allocation) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
            }
            $this->assertProductConfiguration($api, $updatedServer, $settings);
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
            $mappingWasRecovered = false;
            if ($mapping === null) {
                $existing = $api->findServerByExternalId($this->externalId($instance->id));
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
                $existingId = $this->serverId($existing);
                if ($existingId === null) {
                    throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
                }
                $existingDetails = $this->serverNodeId($existing) === null ? $api->getServer($existingId) : $existing;
                $existingNodeId = $this->serverNodeId($existingDetails);
                if ($existingNodeId === null) {
                    throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
                }
                $mapping = $this->storeMapping($instance->id, $existingDetails, $this->externalId($instance->id), $existingNodeId);
                $mappingWasRecovered = true;
            }

            $this->assertOwnedMapping($api, $instance, $mapping);
            $server = $api->getServer($mapping->server_id);
        } catch (PterodactylProviderException $exception) {
            if ($mappingWasRecovered && in_array($exception->status, [0, 404], true)) {
                $mapping?->delete();
            }
            if ($exception->status === 404) {
                $mapping?->delete();

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
            meta: array_merge($instance->meta, [
                'provider_mapping' => ['server_id' => (int) $mapping->server_id],
            ]),
            serverSettings: $instance->serverSettings,
            providerSettings: $instance->providerSettings,
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
            $this->assertOwnedMapping($api, $instance, $mapping);
            $api->power($mapping->identifier, $actionId);
            $client = $api->clientServer($mapping->identifier);
            $currentState = $client['current_state'] ?? $client['currentState'] ?? null;
            $expectedStates = match ($actionId) {
                'start' => ['running', 'starting'],
                'stop' => ['offline', 'stopping'],
                'restart' => ['running', 'starting', 'stopping'],
            };
            if (! is_string($currentState) || ! in_array(strtolower($currentState), $expectedStates, true)) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.power_failed');
            }
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

    /** @return list<int> */
    private function eligibleNodeIds(PterodactylApi $api, int $locationId, int $memory, int $disk): array
    {
        $nodeIds = [];
        foreach ($api->getDeployableNodes($locationId, $memory, $disk) as $node) {
            $value = $node['node_id'] ?? $node['node'] ?? $node['id'] ?? null;
            if (is_array($value)) {
                $value = $value['id'] ?? null;
            }
            $nodeId = is_int($value) || (is_string($value) && ctype_digit($value))
                ? (int) $value
                : null;
            if ($nodeId === null || $nodeId < 1) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
            }
            $nodeIds[] = $nodeId;
        }

        return array_values(array_unique($nodeIds));
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return $value > 0 && $value <= PHP_INT_MAX ? (int) $value : null;
        }
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $integer === false ? null : $integer;
    }

    /** @param array<string, mixed> $server */
    private function serverId(array $server): ?int
    {
        return $this->positiveInteger($server['id'] ?? null);
    }

    /** @param array<string, mixed> $server */
    private function serverNodeId(array $server): ?int
    {
        $value = array_key_exists('node_id', $server) ? $server['node_id'] : ($server['node'] ?? null);
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return $this->positiveInteger($value);
    }

    private function assertOwnedMapping(
        PterodactylApi $api,
        ServiceInstanceInfo $instance,
        PterodactylServer $mapping,
        ?array $expectedSettings = null,
        bool $checkResources = true,
    ): void {
        $server = $api->getServer($mapping->server_id);
        if (! is_string($server['identifier'] ?? null) || trim($server['identifier']) === '') {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
        }
        $serverUuid = $server['uuid'] ?? null;
        if (! is_string($serverUuid) || trim($serverUuid) === ''
            || trim((string) $mapping->identifier) === ''
            || trim((string) $mapping->uuid) === ''
            || trim((string) $mapping->identifier) !== trim($server['identifier'])
            || trim((string) $mapping->uuid) !== trim($serverUuid)
        ) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
        }
        if ($this->serverId($server) !== $mapping->server_id
            || ($server['external_id'] ?? null) !== $this->externalId($instance->id)
        ) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
        }
        $expectedUserId = $this->intSetting(['user_id' => $this->connectionSetting($instance, 'user_id')], 'user_id');
        $actualUserId = $this->serverRelatedId($server, ['user_id', 'user']);
        if ($expectedUserId === null || $expectedUserId < 1 || $actualUserId === null || $actualUserId !== $expectedUserId) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
        }
        $nodeId = $this->serverNodeId($server);
        if ($nodeId === null) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
        }
        if ($mapping->node_id === null) {
            $mapping->forceFill(['node_id' => $nodeId])->save();
        } elseif ((int) $mapping->node_id !== $nodeId) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
        }
        $this->assertProductConfiguration($api, $server, $expectedSettings ?? $this->providerSettings($instance), $checkResources);
    }

    /** @param array<string, mixed> $server @param array<string, mixed> $settings */
    private function assertProductConfiguration(PterodactylApi $api, array $server, array $settings, bool $checkResources = true): void
    {
        $settings = $this->settingsWithProductDefaults($settings);
        $expectedLocation = $this->intSetting($settings, 'location_id');
        $expectedNest = $this->intSetting($settings, 'nest_id');
        $expectedEgg = $this->intSetting($settings, 'egg_id');
        $actualLocation = $this->serverLocationId($server);
        $actualNest = $this->serverRelatedId($server, ['nest_id', 'nest']);
        $actualEgg = $this->serverRelatedId($server, ['egg_id', 'egg']);
        if ($expectedLocation === null || $expectedNest === null || $expectedEgg === null
            || $actualLocation === null || $actualNest === null || $actualEgg === null
            || $actualLocation !== $expectedLocation
            || $actualNest !== $expectedNest
            || $actualEgg !== $expectedEgg
        ) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
        }

        if (! $checkResources) {
            return;
        }
        $limits = is_array($server['limits'] ?? null) ? $server['limits'] : null;
        $featureLimits = is_array($server['feature_limits'] ?? null) ? $server['feature_limits'] : null;
        if ($limits === null || $featureLimits === null) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
        }
        foreach (['memory', 'swap', 'disk', 'io', 'cpu'] as $key) {
            $expected = $this->intSetting($settings, $key);
            if ($expected !== null && $this->intSetting($limits, $key) !== $expected) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
            }
        }
        foreach (['databases', 'allocations', 'backups'] as $key) {
            $expected = $this->intSetting($settings, $key);
            if ($expected !== null && $this->intSetting($featureLimits, $key) !== $expected) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
            }
        }
        $expectedDockerImage = $this->optionalString($settings, 'docker_image');
        $expectedStartup = $this->optionalString($settings, 'startup');
        $egg = $api->getEgg($expectedNest, $expectedEgg);
        $expectedDockerImage = $expectedDockerImage !== ''
            ? $expectedDockerImage
            : trim((string) ($egg['docker_image'] ?? ''));
        $expectedStartup = $expectedStartup !== ''
            ? $expectedStartup
            : trim((string) ($egg['startup'] ?? ''));
        $actualDockerImage = trim((string) ($server['docker_image'] ?? ($server['container']['image'] ?? '')));
        if ($expectedDockerImage === '' || $actualDockerImage === '' || $actualDockerImage !== $expectedDockerImage) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
        }
        $actualStartup = trim((string) ($server['startup'] ?? ($server['container']['startup'] ?? '')));
        if ($expectedStartup === '' || $actualStartup === '' || $actualStartup !== $expectedStartup) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
        }
        $actualEnvironment = $server['environment'] ?? ($server['container']['environment'] ?? null);
        if (! is_array($actualEnvironment) || $actualEnvironment !== $this->environment($settings, $egg)) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
        }
        $actualDedicatedIp = $server['dedicated_ip'] ?? ($server['deploy']['dedicated_ip'] ?? null);
        if ($actualDedicatedIp === null
            || $this->boolSetting(['dedicated_ip' => $actualDedicatedIp], 'dedicated_ip') !== $this->boolSetting($settings, 'dedicated_ip')
        ) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
        }
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function settingsWithProductDefaults(array $settings): array
    {
        $this->assertNoRedactionPlaceholder($settings);
        foreach (['location_id', 'nest_id', 'egg_id', 'memory', 'swap', 'disk', 'io', 'cpu', 'databases', 'allocations', 'backups'] as $key) {
            if (array_key_exists($key, $settings)
                && $settings[$key] !== null
                && $settings[$key] !== ''
                && $this->intSetting($settings, $key) === null
            ) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.invalid_mapping');
            }
        }
        $this->optionalString($settings, 'docker_image');
        $this->optionalString($settings, 'startup');

        $resolved = $settings;
        foreach ($this->productSettings() as $definition) {
            if ((! array_key_exists($definition->key, $resolved)
                    || $resolved[$definition->key] === null
                    || $resolved[$definition->key] === '')
                && $definition->default !== null
            ) {
                $resolved[$definition->key] = $definition->default;
            }
        }

        return $resolved;
    }

    /** @param array<string, mixed> $settings */
    private function optionalString(array $settings, string $key): string
    {
        if (! array_key_exists($key, $settings) || $settings[$key] === null || $settings[$key] === '') {
            return '';
        }
        if (! is_string($settings[$key])) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.invalid_mapping');
        }

        return trim($settings[$key]);
    }

    /** @param array<string, mixed> $settings */
    private function assertNoRedactionPlaceholder(array $settings): void
    {
        foreach ($settings as $value) {
            if (is_array($value)) {
                $this->assertNoRedactionPlaceholder($value);
                continue;
            }
            if ($value === '[REDACTED]') {
                throw ValidationException::withMessages([
                    'instance' => __('pterodactyl::messages.errors.not_configured'),
                ]);
            }
        }
    }

    /** @param array<string, mixed> $server @param list<string> $keys */
    private function serverRelatedId(array $server, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $server)) {
                continue;
            }

            $value = $server[$key];
            if (is_array($value)) {
                $value = $value['id'] ?? null;
            }
            $id = $this->positiveInteger($value);

            return $id;
        }

        return null;
    }

    /** @param array<string, mixed> $server */
    private function serverLocationId(array $server): ?int
    {
        if (array_key_exists('location_id', $server) || array_key_exists('location', $server)) {
            return $this->serverRelatedId($server, ['location_id', 'location']);
        }

        $node = $server['node'] ?? null;
        if (is_array($node)) {
            return $this->serverRelatedId($node, ['location_id', 'location']);
        }

        return null;
    }

    /** @param array<string, mixed> $server */
    private function storeMapping(int $instanceId, array $server, string $externalId, ?int $nodeId = null): PterodactylServer
    {
        return PterodactylServer::query()->updateOrCreate(
            ['service_instance_id' => $instanceId],
            [
                'server_id' => (int) $server['id'],
                'node_id' => $nodeId ?? $this->serverNodeId($server),
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
     * @param  callable(PterodactylServer): void  $verify
     */
    private function mutate(ServiceInstanceInfo $instance, callable $callback, callable $verify): void
    {
        $mapping = $this->requireMapping($instance);
        $api = $this->apiFor($instance);

        try {
            $this->assertOwnedMapping($api, $instance, $mapping);
            $callback($mapping);
            $verify($mapping);
        } catch (PterodactylProviderException $exception) {
            throw ValidationException::withMessages([
                'instance' => __($exception->errorKey),
            ]);
        }
    }

    /** @param array<string, mixed> $server */
    private function assertSuspendedState(array $server, bool $expected): void
    {
        if (! array_key_exists('suspended', $server) || $server['suspended'] !== $expected) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed');
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
        $userId = (int) $this->connectionSetting($instance, 'user_id', 0);
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
            'nest' => $this->intSetting($settings, 'nest_id') ?? 0,
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
        if (! is_array($variables)) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
        }
        foreach ($variables as $variable) {
            $attributes = is_array($variable) ? ($variable['attributes'] ?? $variable) : null;
            if (! is_array($attributes)
                || ! is_string($attributes['env_variable'] ?? null)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $attributes['env_variable']) !== 1
                || ! is_string($attributes['default_value'] ?? null)
            ) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
            }
            $environment[$attributes['env_variable']] = $attributes['default_value'];
        }

        $raw = $this->optionalString($settings, 'environment');
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! str_contains($line, '=')) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.invalid_mapping');
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.invalid_mapping');
            }
            $environment[$key] = trim($value);
        }

        return $environment;
    }

    /**
     * @return array<string, mixed>
     */
    private function providerSettings(ServiceInstanceInfo $instance): array
    {
        return is_array($instance->providerSettings) ? $instance->providerSettings : [];
    }

    private function containsSensitiveSettings(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $nested) {
            $normalizedKey = strtolower((string) $key);
            if ($normalizedKey === 'environment'
                || preg_match('/(?:api[_-]?key|access[_-]?key|token|secret|password|passwd|credential|authorization|private[_-]?key|connection|string|dsn)/', $normalizedKey) === 1
            ) {
                return true;
            }
            if ($this->containsSensitiveSettings($nested)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function intSetting(array $settings, string $key): ?int
    {
        if (! array_key_exists($key, $settings) || $settings[$key] === null || $settings[$key] === '') {
            return null;
        }

        if (is_int($settings[$key])) {
            return $settings[$key] >= 0 ? $settings[$key] : null;
        }
        if (is_float($settings[$key]) && is_finite($settings[$key]) && floor($settings[$key]) === $settings[$key]) {
            return $settings[$key] >= 0 && $settings[$key] <= PHP_INT_MAX ? (int) $settings[$key] : null;
        }

        if (! is_string($settings[$key]) || preg_match('/^(0|[1-9][0-9]*)$/D', trim($settings[$key])) !== 1) {
            return null;
        }

        $value = filter_var($settings[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $value === false ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function boolSetting(array $settings, string $key): bool
    {
        $value = $settings[$key] ?? false;
        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $keys
     */
    private function hasRequiredServerSettings(array $settings, array $keys): bool
    {
        foreach ($keys as $key) {
            if (trim((string) ($settings[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
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
        $settings = $instance->serverSettings ?? [];
        $settings = is_array($settings) ? $settings : [];
        if (($instance->meta['server_settings_required'] ?? false) === true
            && ! $this->hasRequiredServerSettings($settings, ['panel_url', 'application_api_key', 'user_id'])
        ) {
            throw ValidationException::withMessages([
                'instance' => __('pterodactyl::messages.errors.not_configured'),
            ]);
        }

        return $settings;
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
