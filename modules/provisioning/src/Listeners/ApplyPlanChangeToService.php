<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Listeners;

use Agovena\Modules\Provisioning\CapacityReservationService;
use Agovena\Modules\Provisioning\EloquentProvisionedServiceResolver;
use Agovena\Modules\Provisioning\Enums\ServiceInstanceStatus;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use Agovena\Modules\Provisioning\PlanChangeCompensationJournal;
use Agovena\Modules\Provisioning\ProvisioningOrchestrator;
use Agovena\Modules\Provisioning\ProvisioningService;
use Agovena\Modules\Provisioning\ServiceInstanceRuntimeSecretStore;
use App\Agovena\Provisioning\Contracts\ChecksProvisioningStock;
use App\Agovena\Provisioning\Contracts\ProvidesProvisioningCapacityRequirements;
use App\Agovena\Provisioning\Contracts\ProvisionerLifecycle;
use App\Agovena\Provisioning\ProvisionerRegistry;
use App\Agovena\Provisioning\ServiceInstanceInfo;
use App\Events\PlanChangeApplied;
use App\Models\Product;
use App\Models\ProvisioningServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ApplyPlanChangeToService
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly ProvisionerRegistry $provisioners,
        private readonly CapacityReservationService $reservations,
        private readonly PlanChangeCompensationJournal $compensations,
        private readonly ServiceInstanceRuntimeSecretStore $serviceRuntimeSecrets,
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
        $serverSettings = $server !== null && is_array($server->settings)
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
            ? $provider->capacityKeyForSettings($providerSettings, $serverId, $serverSettings)
            : '';
        if ($provider instanceof ChecksProvisioningStock && $capacityKey === '') {
            throw ValidationException::withMessages([
                'plan' => __('provisioning::errors.provider_failed'),
            ]);
        }
        $requirements = $provider instanceof ProvidesProvisioningCapacityRequirements
            ? $provider->capacityRequirements($providerSettings, $serverSettings)
            : [];
        $providerSettingsSnapshot = $this->provisioning->providerSettingsSnapshot($provider, $providerSettings);

        $orchestrator = app(ProvisioningOrchestrator::class);
        $orchestrator->assertSharedInstanceLockDriver();
        $locks = [];
        $lockedInstanceIds = [];
        $snapshots = [];
        $changed = [];

        try {
            do {
                $instances = ServiceInstance::query()
                    ->where('subscription_id', $subscriptionId)
                    ->where('status', '!=', ServiceInstanceStatus::Terminated->value)
                    ->lockForUpdate()
                    ->get();
                $newInstances = $instances->reject(
                    fn (ServiceInstance $instance): bool => in_array($instance->id, $lockedInstanceIds, true),
                );
                foreach ($newInstances as $instance) {
                    $lock = Cache::lock('agovena:provisioning:instance:'.$instance->id, 900);
                    $lock->block(10);
                    $locks[] = $lock;
                    $lockedInstanceIds[] = $instance->id;
                }
            } while ($newInstances->isNotEmpty());

            foreach ($instances as $instance) {
                $previousState = [
                    'status' => $instance->status,
                    'product_id' => $instance->product_id,
                    'provider_key' => $instance->provider_key,
                    'provisioning_server_id' => $instance->provisioning_server_id,
                    'external_ref' => $instance->external_ref,
                    'server_settings_snapshot' => null,
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
                $this->serviceRuntimeSecrets->put($instance->id, $previousServerSettings, $previousProviderSettings);
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
                $instance->server_settings_snapshot = null;
                $instance->provider_settings_snapshot = null;
                $instance->provider_key = $providerKey;
                $instance->failure_message = null;
                $instance->meta = $meta;
                $instance->save();
                $this->serviceRuntimeSecrets->put($instance->id, $serverSettings, $providerSettings);

                $changed[] = $instance->id;

                if ($provider instanceof ProvisionerLifecycle) {
                    $journalPath = $this->compensations->prepare(
                        requestId: $event->request->id,
                        instanceId: $instance->id,
                        providerKey: $providerKey,
                        previousState: $previousState,
                        previousInfo: $previousInfo,
                        appliedState: [
                            'status' => $instance->status,
                            'product_id' => $instance->product_id,
                            'provider_key' => $instance->provider_key,
                            'provisioning_server_id' => $instance->provisioning_server_id,
                            'external_ref' => $instance->external_ref,
                            'server_settings_snapshot' => null,
                            'meta' => $instance->meta,
                            'provisioning_at' => $instance->provisioning_at,
                            'activated_at' => $instance->activated_at,
                            'suspended_at' => $instance->suspended_at,
                            'terminated_at' => $instance->terminated_at,
                            'failed_at' => $instance->failed_at,
                            'failure_message' => $instance->failure_message,
                        ],
                    );
                    $orchestrator->changePlan($instance->fresh() ?? $instance, [
                        'id' => (string) $to->id,
                        'product_id' => $to->id,
                        'provider_key' => $providerKey,
                        'provider_settings' => $providerSettings,
                        'server_id' => $serverId,
                        'server_settings' => $serverSettings,
                        'capacity_key' => $capacityKey !== '' ? $capacityKey : null,
                        'requirements' => $requirements,
                        'previous_capacity_key' => $previousCapacityKey,
                        'previous_requirements' => $previousRequirements,
                        'previous_product_id' => $previousState['product_id'],
                        'previous_provider_key' => $previousState['provider_key'],
                        'previous_provider_settings' => $previousProviderSettings,
                        'previous_server_settings' => $previousServerSettings,
                    ], instanceMutexHeld: true);
                    $event->registerCompensation(function () use (
                        $provider,
                        $previousInfo,
                        $previousState,
                        $previousProviderSettings,
                        $previousServerSettings,
                        $journalPath,
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
                            providerSettings: $previousProviderSettings,
                        );
                        $provider->changePlan($rollbackInfo, [
                            'id' => (string) ($previousState['product_id'] ?? ''),
                            'provider_settings' => $previousProviderSettings ?? [],
                            'server_settings' => $previousServerSettings,
                        ]);
                        $provider->syncStatus($rollbackInfo);
                        $this->compensations->forget($journalPath);
                    });
                    $this->compensations->markApplied($journalPath);
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
                $this->serviceRuntimeSecrets->put(
                    (int) $instanceId,
                    $snapshot['previous_server_settings'],
                    $snapshot['previous_provider_settings'],
                );
                $this->restoreState((int) $instanceId, $snapshot['state']);
            }
            throw $exception;
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
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
