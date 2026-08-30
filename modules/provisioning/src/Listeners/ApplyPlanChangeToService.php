<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Listeners;

use Agovena\Modules\Provisioning\CapacityReservationService;
use Agovena\Modules\Provisioning\EloquentProvisionedServiceResolver;
use Agovena\Modules\Provisioning\Enums\ServiceInstanceStatus;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use Agovena\Modules\Provisioning\ProvisioningOrchestrator;
use Agovena\Modules\Provisioning\ProvisioningService;
use App\Agovena\Provisioning\Contracts\ChecksProvisioningStock;
use App\Agovena\Provisioning\Contracts\ProvidesProvisioningCapacityRequirements;
use App\Agovena\Provisioning\Contracts\ProvisionerLifecycle;
use App\Agovena\Provisioning\ProvisionerRegistry;
use App\Agovena\Provisioning\ServiceInstanceInfo;
use App\Events\PlanChangeApplied;
use App\Models\Product;
use App\Models\ProvisioningServer;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ApplyPlanChangeToService
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly ProvisionerRegistry $provisioners,
        private readonly CapacityReservationService $reservations,
    ) {}

    public function handle(PlanChangeApplied $event): void
    {
        $subscriptionId = $event->request->subscription_id;
        if ($subscriptionId === null) {
            return;
        }

        $to = Product::query()->with('capabilities')->find($event->request->to_product_id);
        if ($to === null) {
            throw ValidationException::withMessages([
                'plan' => __('provisioning::errors.provider_failed'),
            ]);
        }

        $capability = $to->capability('provisionable');
        if ($capability === null) {
            throw ValidationException::withMessages([
                'plan' => __('provisioning::errors.provider_failed'),
            ]);
        }
        if ($capability->hasCorruptConfig()) {
            throw ValidationException::withMessages([
                'plan' => __('provisioning::errors.provider_failed'),
            ]);
        }
        $config = $capability->runtimeConfig() ?? [];
        $providerSettings = is_array($config['provider_settings'] ?? null) ? $config['provider_settings'] : [];
        $rawServerId = $config['server_id'] ?? null;
        $serverId = is_int($rawServerId) && $rawServerId > 0
            ? $rawServerId
            : (is_string($rawServerId) && preg_match('/^[1-9][0-9]*$/D', $rawServerId) === 1
                ? filter_var($rawServerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : null);
        if ($rawServerId !== null && ! is_int($serverId)) {
            throw ValidationException::withMessages([
                'plan' => __('provisioning::errors.provider_failed'),
            ]);
        }
        $providerKey = isset($config['provider_key']) && is_string($config['provider_key']) && $config['provider_key'] !== ''
            ? $config['provider_key']
            : null;
        $server = $serverId !== null
            ? ProvisioningServer::query()
                ->where('is_active', true)
                ->where('provider_key', $providerKey)
                ->find($serverId)
            : null;
        $serverSettingsSnapshot = $server !== null && is_array($server->settings)
            ? $server->settings
            : null;
        $provider = $providerKey !== null ? $this->provisioners->get($providerKey) : null;
        if ($providerKey === null || $provider === null) {
            throw ValidationException::withMessages([
                'plan' => __('provisioning::errors.provider_failed'),
            ]);
        }
        if ($serverId !== null && $server === null) {
            throw ValidationException::withMessages([
                'plan' => __('provisioning::errors.provider_failed'),
            ]);
        }
        if (! $provider instanceof ProvisionerLifecycle
            && $provider->id() !== 'manual'
        ) {
            throw ValidationException::withMessages([
                'plan' => __('provisioning::errors.provider_failed'),
            ]);
        }
        $capacityKey = $provider instanceof ChecksProvisioningStock
            ? $provider->capacityKeyForSettings($providerSettings, $serverId, $serverSettingsSnapshot)
            : '';
        if ($provider instanceof ChecksProvisioningStock && $capacityKey === '') {
            throw ValidationException::withMessages([
                'plan' => __('provisioning::errors.provider_failed'),
            ]);
        }
        $requirements = $provider instanceof ProvidesProvisioningCapacityRequirements
            ? $provider->capacityRequirements($providerSettings, $serverSettingsSnapshot)
            : [];
        $providerSettingsSnapshot = $this->provisioning->providerSettingsSnapshot($provider, $providerSettings);


        $instances = ServiceInstance::query()
            ->where('subscription_id', $subscriptionId)
            ->where('status', '!=', ServiceInstanceStatus::Terminated->value)
            ->lockForUpdate()
            ->get();

        $orchestrator = app(ProvisioningOrchestrator::class);
        $snapshots = [];
        $changed = [];

        try {
            foreach ($instances as $instance) {
                $previousState = [
                    'status' => $instance->status,
                    'product_id' => $instance->product_id,
                    'provider_key' => $instance->provider_key,
                    'provisioning_server_id' => $instance->provisioning_server_id,
                    'external_ref' => $instance->external_ref,
                    'server_settings_snapshot' => $instance->server_settings_snapshot,
                    'meta' => $instance->meta,
                    'provisioning_at' => $instance->provisioning_at,
                    'activated_at' => $instance->activated_at,
                    'suspended_at' => $instance->suspended_at,
                    'terminated_at' => $instance->terminated_at,
                    'failed_at' => $instance->failed_at,
                    'failure_message' => $instance->failure_message,
                ];
                $meta = is_array($instance->meta) ? $instance->meta : [];
                $previousInfo = EloquentProvisionedServiceResolver::info($instance);
                $previousProviderSettings = $previousInfo->providerSettings;
                $previousServerSettings = $previousInfo->serverSettings;
                if ($previousState['provider_key'] !== $providerKey) {
                    throw ValidationException::withMessages([
                        'plan' => __('provisioning::errors.provider_failed'),
                    ]);
                }
                $previousCapacityKey = is_string($meta['provisioning_capacity_key'] ?? null)
                    ? $meta['provisioning_capacity_key']
                    : null;
                $previousRequirements = is_array($meta['provisioning_capacity_requirements'] ?? null)
                    ? $meta['provisioning_capacity_requirements']
                    : null;
                $snapshots[$instance->id] = [
                    'state' => $previousState,
                    'previous_capacity_key' => $previousCapacityKey,
                    'previous_requirements' => $previousRequirements,
                    'target_capacity_key' => $capacityKey !== '' ? $capacityKey : null,
                    'target_requirements' => $requirements,
                    'target_product_id' => $to->id,
                    'target_provider_key' => $providerKey,
                    'previous_provider_settings' => $previousProviderSettings,
                    'previous_server_settings' => $previousServerSettings,
                ];
                $meta['plan_change'] = [
                    'from_product_id' => $event->request->from_product_id,
                    'to_product_id' => $to->id,
                    'applied_at' => now()->toIso8601String(),
                ];
                $meta['provider_settings'] = $providerSettingsSnapshot;
                $meta['provisioning_capacity_key'] = $capacityKey !== '' ? $capacityKey : null;
                $meta['provisioning_capacity_requirements'] = $requirements;
                $instance->product_id = $to->id;
                $instance->provisioning_server_id = $serverId;
                $instance->server_settings_snapshot = $serverSettingsSnapshot;
                $instance->provider_settings_snapshot = $providerSettings;
                $instance->provider_key = $providerKey;
                $instance->failure_message = null;
                $instance->meta = $meta;
                $instance->save();

                $changed[] = $instance->id;

                if ($provider instanceof ProvisionerLifecycle) {
                    $orchestrator->changePlan($instance->fresh() ?? $instance, [
                        'id' => (string) $to->id,
                        'product_id' => $to->id,
                        'provider_key' => $providerKey,
                        'provider_settings' => $providerSettings,
                        'server_id' => $serverId,
                        'server_settings' => $serverSettingsSnapshot,
                        'capacity_key' => $capacityKey !== '' ? $capacityKey : null,
                        'requirements' => $requirements,
                        'previous_capacity_key' => $previousCapacityKey,
                        'previous_requirements' => $previousRequirements,
                        'previous_product_id' => $previousState['product_id'],
                        'previous_provider_key' => $previousState['provider_key'],
                        'previous_provider_settings' => $previousProviderSettings,
                        'previous_server_settings' => $previousServerSettings,
                    ]);
                    $event->registerCompensation(function () use (
                        $provider,
                        $previousInfo,
                        $previousState,
                        $previousProviderSettings,
                        $previousServerSettings,
                    ): void {
                        $rollbackMeta = $previousInfo->meta;
                        if ($previousProviderSettings !== null) {
                            $rollbackMeta['provider_settings'] = $previousProviderSettings;
                        }
                        if ($previousServerSettings !== null) {
                            $rollbackMeta['server_settings'] = $previousServerSettings;
                        }
                        $rollbackInfo = new ServiceInstanceInfo(
                            id: $previousInfo->id,
                            label: $previousInfo->label,
                            status: $previousInfo->status,
                            providerKey: $previousInfo->providerKey,
                            externalRef: $previousInfo->externalRef,
                            meta: $rollbackMeta,
                            serverSettings: $previousServerSettings,
                        );
                        $provider->changePlan($rollbackInfo, [
                            'id' => (string) ($previousState['product_id'] ?? ''),
                            'provider_settings' => $previousProviderSettings ?? [],
                            'server_settings' => $previousServerSettings,
                        ]);
                        $provider->syncStatus($rollbackInfo);
                    });
                } elseif ($provider->id() === 'manual') {
                    if ($previousCapacityKey !== null
                        && is_numeric($previousState['product_id'])
                        && is_string($previousState['provider_key'])
                    ) {
                        $this->reservations->release(
                            orderId: (int) $instance->order_id,
                            productId: $previousState['product_id'],
                            providerKey: $previousState['provider_key'],
                            capacityKey: $previousCapacityKey,
                            requirementsFingerprint: $previousRequirements !== null
                                ? $this->reservations->requirementsFingerprint($previousRequirements)
                                : null,
                            orderItemId: $instance->order_item_id,
                        );
                    }
                }
            }
        } catch (Throwable $exception) {
            foreach ($changed as $instanceId) {
                $snapshot = $snapshots[$instanceId] ?? null;
                if (! is_array($snapshot)) {
                    continue;
                }
                $current = ServiceInstance::query()->find($instanceId);
                if ($current !== null && $this->reservationChanged($snapshot)) {
                    $this->reservations->releaseForInstance(
                        $current,
                        $snapshot['target_capacity_key'],
                        $snapshot['target_requirements'],
                    );
                    $this->reservations->restoreForInstance(
                        $current,
                        $snapshot['previous_capacity_key'],
                        $snapshot['previous_requirements'],
                        $snapshot['state']['product_id'],
                        $snapshot['state']['provider_key'],
                    );
                }
            }
            foreach ($snapshots as $instanceId => $snapshot) {
                $this->restoreState((int) $instanceId, $snapshot['state']);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function reservationChanged(array $snapshot): bool
    {
        return $snapshot['previous_requirements'] === null
            || $snapshot['previous_capacity_key'] !== $snapshot['target_capacity_key']
            || $snapshot['previous_requirements'] != $snapshot['target_requirements']
            || $snapshot['state']['product_id'] !== $snapshot['target_product_id']
            || $snapshot['state']['provider_key'] !== $snapshot['target_provider_key'];
    }

    /** @param array<string, mixed> $state */
    private function restoreState(int $instanceId, array $state): void
    {
        $instance = ServiceInstance::query()->find($instanceId);
        if ($instance === null) {
            return;
        }
        foreach ($state as $attribute => $value) {
            $instance->{$attribute} = $value;
        }
        $instance->save();
    }
}
