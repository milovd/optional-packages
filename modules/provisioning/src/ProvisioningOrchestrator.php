<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use Agovena\Modules\Provisioning\Enums\ServiceInstanceStatus;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use App\Agovena\Cart\PricedCartLine;
use App\Agovena\Provisioning\Contracts\ChecksProvisioningStock;
use App\Agovena\Provisioning\Contracts\ChecksProvisioningStockVector;
use App\Agovena\Provisioning\Contracts\ProvidesProvisioningCapacityRequirements;
use App\Agovena\Provisioning\Contracts\ProvisionerLifecycle;
use App\Agovena\Provisioning\ProvisionerRegistry;
use App\Agovena\Provisioning\ProvisioningStockContext;
use App\Agovena\Provisioning\ServiceInstanceInfo;
use App\Models\Product;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies generic ProvisionerLifecycle operations, then updates Agovena state
 * only after the provider call succeeds.
 */
final class ProvisioningOrchestrator
{
    public function __construct(
        private readonly ProvisionerRegistry $provisioners,
        private readonly ProvisioningService $provisioning,
        private readonly CapacityReservationService $reservations,
    ) {}

    public function provision(ServiceInstance $instance): ServiceInstance
    {
        return $this->withInstanceMutex($instance, function (ServiceInstance $current): ServiceInstance {
            $deferredException = null;
            $result = $this->provisionLocked($current, $deferredException);

            if ($deferredException !== null) {
                throw $deferredException;
            }

            return $result;
        });
    }

    private function provisionLocked(ServiceInstance $instance, ?Throwable &$deferredException): ServiceInstance
    {
        $provisioner = $this->lifecycle($instance);
        if ($provisioner === null) {
            if ($instance->provider_key === 'manual') {
                return $instance;
            }

            return $this->handleUnavailableProvider($instance);
        }

        if (! in_array($instance->status, [ServiceInstanceStatus::Pending, ServiceInstanceStatus::Failed, ServiceInstanceStatus::ManualReview, ServiceInstanceStatus::Provisioning], true)) {
            return $instance;
        }

        if (in_array($instance->status, [ServiceInstanceStatus::Pending, ServiceInstanceStatus::Failed, ServiceInstanceStatus::ManualReview], true)) {
            $instance = $this->provisioning->markProvisioning($instance);
        }

        $info = null;
        $providerCallStarted = false;
        try {
            $info = EloquentProvisionedServiceResolver::info($instance);
            $this->revalidateCapacity($instance, $info, $provisioner);
            $providerCallStarted = true;
            $provisioner->provision($info);
            $updated = $provisioner->poll($info);
            if (in_array($updated->status, ['active', 'suspended'], true)) {
                $this->reservations->commitForInstance($instance->fresh() ?? $instance);
            }
        } catch (ValidationException $exception) {
            $message = $exception->errors()['instance'][0] ?? __('provisioning::errors.provider_failed');
            $reconciliation = $this->reconcileFailedProvisioning($instance, $info, $provisioner, $providerCallStarted);

            if ($reconciliation['status'] === 'present' && $reconciliation['info'] !== null) {
                if ($reconciliation['info']->status !== ServiceInstanceStatus::ManualReview->value) {
                    $this->reservations->commitForInstance($instance->fresh() ?? $instance);
                }

                return $this->applyPolled($instance, $reconciliation['info']);
            }
            if ($reconciliation['status'] === 'unknown') {
                return $this->provisioning->markManualReview($instance, $message);
            }

            return $this->provisioning->fail($instance, $message);
        } catch (Throwable $exception) {
            report($exception);
            $reconciliation = $this->reconcileFailedProvisioning($instance, $info, $provisioner, $providerCallStarted);

            if ($reconciliation['status'] === 'present' && $reconciliation['info'] !== null) {
                if ($reconciliation['info']->status !== ServiceInstanceStatus::ManualReview->value) {
                    $this->reservations->commitForInstance($instance->fresh() ?? $instance);
                }

                return $this->applyPolled($instance, $reconciliation['info']);
            }
            if ($reconciliation['status'] === 'unknown') {
                if ($this->hasReservationIdentity($instance)) {
                    return $this->provisioning->markManualReview($instance, __('provisioning::errors.provider_failed'));
                }

                $this->provisioning->fail($instance, __('provisioning::errors.provider_failed'));
                $deferredException = $exception;

                return $instance->fresh() ?? $instance;
            }

            $this->provisioning->fail($instance, __('provisioning::errors.provider_failed'));
            $deferredException = $exception;

            return $instance->fresh() ?? $instance;
        }

        return $this->applyPolled($instance, $updated);
    }

    /** @return array{status: 'absent'|'present'|'unknown', info: ServiceInstanceInfo|null} */
    private function reconcileFailedProvisioning(
        ServiceInstance $instance,
        ?ServiceInstanceInfo $info,
        ProvisionerLifecycle $provisioner,
        bool $providerCallStarted,
    ): array {
        if (! $providerCallStarted || $info === null) {
            $this->reservations->releaseForInstance($instance);

            return ['status' => 'absent', 'info' => null];
        }

        try {
            $updated = $provisioner->syncStatus($info);
        } catch (Throwable) {
            return ['status' => 'unknown', 'info' => null];
        }

        $hasExternalResource = is_array($updated->meta['provider_mapping'] ?? null)
            && $updated->meta['provider_mapping'] !== [];
        if ($hasExternalResource) {
            return ['status' => 'present', 'info' => $updated];
        }

        if ($updated->status === ServiceInstanceStatus::Terminated->value
            || ($updated->meta['provider_reconciliation'] ?? null) === 'absent'
        ) {
            $this->reservations->releaseForInstance($instance);

            return ['status' => 'absent', 'info' => null];
        }

        return ['status' => 'unknown', 'info' => null];
    }

    public function sync(ServiceInstance $instance): ServiceInstance
    {
        return $this->withInstanceMutex($instance, fn (ServiceInstance $current): ServiceInstance => $this->syncLocked($current));
    }

    private function syncLocked(ServiceInstance $instance): ServiceInstance
    {
        $provisioner = $this->lifecycle($instance);
        if ($provisioner === null) {
            if ($instance->provider_key === 'manual') {
                return $instance;
            }

            return $this->handleUnavailableProvider($instance);
        }

        try {
            $updated = $provisioner->syncStatus(EloquentProvisionedServiceResolver::info($instance));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.provider_failed'),
            ]);
        }

        return $this->applyPolled($instance, $updated);
    }

    public function suspend(ServiceInstance $instance): ServiceInstance
    {
        return $this->withInstanceMutex($instance, fn (ServiceInstance $current): ServiceInstance => $this->suspendLocked($current));
    }

    private function suspendLocked(ServiceInstance $instance): ServiceInstance
    {
        $provisioner = $this->lifecycle($instance);
        if ($provisioner === null && $instance->provider_key !== 'manual') {
            return $this->handleUnavailableProvider($instance);
        }
        if ($provisioner !== null) {
            try {
                $provisioner->suspend(EloquentProvisionedServiceResolver::info($instance));
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages([
                    'instance' => __('provisioning::errors.provider_failed'),
                ]);
            }
        }

        return $this->provisioning->suspend($instance);
    }

    public function unsuspend(ServiceInstance $instance): ServiceInstance
    {
        return $this->withInstanceMutex($instance, fn (ServiceInstance $current): ServiceInstance => $this->unsuspendLocked($current));
    }

    private function unsuspendLocked(ServiceInstance $instance): ServiceInstance
    {
        $provisioner = $this->lifecycle($instance);
        if ($provisioner === null && $instance->provider_key !== 'manual') {
            return $this->handleUnavailableProvider($instance);
        }
        if ($provisioner !== null) {
            try {
                $provisioner->unsuspend(EloquentProvisionedServiceResolver::info($instance));
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages([
                    'instance' => __('provisioning::errors.provider_failed'),
                ]);
            }
        }

        return $this->provisioning->unsuspend($instance);
    }

    public function terminate(ServiceInstance $instance): ServiceInstance
    {
        return $this->withInstanceMutex($instance, fn (ServiceInstance $current): ServiceInstance => $this->terminateLocked($current));
    }

    private function terminateLocked(ServiceInstance $instance): ServiceInstance
    {
        $provisioner = $this->lifecycle($instance);
        if ($provisioner !== null) {
            try {
                $provisioner->terminate(EloquentProvisionedServiceResolver::info($instance));
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages([
                    'instance' => __('provisioning::errors.provider_failed'),
                ]);
            }
        }

        $this->reservations->releaseForInstance($instance);

        return $this->provisioning->terminate($instance);
    }

    /** @param string|array<string, mixed> $plan */
    public function changePlan(ServiceInstance $instance, string|array $plan): ServiceInstance
    {
        $target = $this->normalizePlan($instance, $plan);

        return $this->withInstanceMutex($instance, fn (ServiceInstance $current): ServiceInstance => $this->changePlanLocked($current, $target));
    }

    /** @param array<string, mixed> $plan */
    private function changePlanLocked(ServiceInstance $instance, array $plan): ServiceInstance
    {
        $provisioner = $this->lifecycle($instance);
        if ($provisioner === null) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.provider_failed'),
            ]);
        }

        $capacityReserved = false;
        $reservationCommitted = false;
        $reservationReleasedForFailure = false;
        $capacityKey = is_string($plan['capacity_key'] ?? null) ? $plan['capacity_key'] : null;
        $requirements = is_array($plan['requirements'] ?? null) ? $plan['requirements'] : [];
        $previousCapacityKey = is_string($plan['previous_capacity_key'] ?? null) ? $plan['previous_capacity_key'] : null;
        $previousRequirements = is_array($plan['previous_requirements'] ?? null) ? $plan['previous_requirements'] : null;
        $previousProductId = is_numeric($plan['previous_product_id'] ?? null) ? (int) $plan['previous_product_id'] : null;
        $previousProviderKey = is_string($plan['previous_provider_key'] ?? null) ? $plan['previous_provider_key'] : null;
        $previousProviderSettings = is_array($plan['previous_provider_settings'] ?? null)
            ? $plan['previous_provider_settings']
            : $instance->provider_settings_snapshot;
        $previousServerSettings = is_array($plan['previous_server_settings'] ?? null)
            ? $plan['previous_server_settings']
            : (is_array($instance->server_settings_snapshot) ? $instance->server_settings_snapshot : null);
        $providerMutationAttempted = false;
        try {
            $info = EloquentProvisionedServiceResolver::info($instance);
            if ($provisioner instanceof ChecksProvisioningStock) {
                [$capacityKey, $requirements, $capacityReserved] = $this->revalidatePlanCapacity(
                    $instance,
                    $info,
                    $provisioner,
                    $plan,
                    $previousCapacityKey,
                    $previousRequirements,
                    $previousProductId,
                    $previousProviderKey,
                );
            }
            $providerMutationAttempted = true;
            $provisioner->changePlan($info, $plan);
            $syncInstance = $instance->fresh() ?? $instance;
            $syncMeta = is_array($syncInstance->meta) ? $syncInstance->meta : [];
            if (is_array($plan['provider_settings'] ?? null)) {
                $syncInstance->provider_settings_snapshot = $plan['provider_settings'];
                $syncMeta['provider_settings'] = EloquentProvisionedServiceResolver::sanitizePersistedMeta($plan['provider_settings']);
            }
            if (is_array($plan['server_settings'] ?? null)) {
                $syncInstance->server_settings_snapshot = $plan['server_settings'];
            }
            $syncInstance->meta = $syncMeta;
            $updated = $provisioner->syncStatus(EloquentProvisionedServiceResolver::info($syncInstance));
            if (! in_array($updated->status, ['active', 'suspended'], true)) {
                if (in_array($updated->status, [ServiceInstanceStatus::Failed->value, ServiceInstanceStatus::Terminated->value], true)) {
                    $this->reservations->releaseForInstance($instance, $capacityKey, $requirements);
                    $reservationReleasedForFailure = true;
                }

                throw ValidationException::withMessages([
                    'instance' => __('provisioning::errors.provider_failed'),
                ]);
            }
            if ($capacityReserved) {
                $this->reservations->commitForInstance(
                    $instance->fresh() ?? $instance,
                    $capacityKey,
                    $requirements,
                    $previousCapacityKey,
                    $previousRequirements,
                    $previousProductId,
                    $previousProviderKey,
                );
                $reservationCommitted = true;
            }
            $result = $this->applyPolled($instance, $updated);
        } catch (ValidationException $exception) {
            $compensationSucceeded = $this->compensateProviderPlanChange(
                $instance,
                $info ?? EloquentProvisionedServiceResolver::info($instance),
                $provisioner,
                $providerMutationAttempted,
                $previousProviderKey,
                $previousProviderSettings,
                $previousServerSettings,
                $previousProductId,
            );
            if ($compensationSucceeded && ($reservationCommitted || $reservationReleasedForFailure)) {
                $this->reservations->restoreForInstance(
                    $instance,
                    $previousCapacityKey,
                    $previousRequirements,
                    $previousProductId,
                    $previousProviderKey,
                );
            } elseif ($compensationSucceeded && $capacityReserved) {
                $this->reservations->releaseForInstance($instance, $capacityKey, $requirements);
            }
            throw $exception;
        } catch (Throwable $exception) {
            $compensationSucceeded = $this->compensateProviderPlanChange(
                $instance,
                $info ?? EloquentProvisionedServiceResolver::info($instance),
                $provisioner,
                $providerMutationAttempted,
                $previousProviderKey,
                $previousProviderSettings,
                $previousServerSettings,
                $previousProductId,
            );
            if ($compensationSucceeded && ($reservationCommitted || $reservationReleasedForFailure)) {
                $this->reservations->restoreForInstance(
                    $instance,
                    $previousCapacityKey,
                    $previousRequirements,
                    $previousProductId,
                    $previousProviderKey,
                );
            } elseif ($compensationSucceeded && $capacityReserved) {
                $this->reservations->releaseForInstance($instance, $capacityKey, $requirements);
            }
            report($exception);
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.provider_failed'),
            ]);
        }

        return $result;
    }

    private function compensateProviderPlanChange(
        ServiceInstance $instance,
        ServiceInstanceInfo $info,
        ProvisionerLifecycle $provisioner,
        bool $providerMutationAttempted,
        ?string $previousProviderKey,
        ?array $previousProviderSettings,
        ?array $previousServerSettings,
        ?int $previousProductId,
    ): bool {
        if (! $providerMutationAttempted || $previousProviderSettings === null
            || ($previousProviderKey !== null && $previousProviderKey !== $provisioner->id())
        ) {
            return true;
        }

        $rollbackMeta = $info->meta;
        $rollbackMeta['provider_settings'] = EloquentProvisionedServiceResolver::sanitizePersistedMeta($previousProviderSettings);
        if ($previousServerSettings !== null) {
            $rollbackMeta['server_settings'] = $previousServerSettings;
        }
        $rollbackInfo = new ServiceInstanceInfo(
            id: $info->id,
            label: $info->label,
            status: $info->status,
            providerKey: $previousProviderKey ?? $info->providerKey,
            externalRef: $info->externalRef,
            meta: $rollbackMeta,
            serverSettings: $previousServerSettings,
            providerSettings: $previousProviderSettings,
        );

        try {
            $provisioner->changePlan($rollbackInfo, [
                'id' => (string) ($previousProductId ?? $instance->product_id),
                'provider_settings' => $previousProviderSettings,
                'server_settings' => $previousServerSettings,
            ]);
            $provisioner->syncStatus($rollbackInfo);
            return true;
        } catch (Throwable $rollbackException) {
            report($rollbackException);
            $instance->meta = EloquentProvisionedServiceResolver::sanitizePersistedMeta([
                ...(is_array($instance->meta) ? $instance->meta : []),
                'provisioning_recovery' => [
                    'state' => 'manual_review',
                    'reason' => 'plan_change_compensation_failed',
                    'provider_key' => $previousProviderKey ?? $instance->provider_key,
                    'product_id' => $previousProductId ?? $instance->product_id,
                ],
            ]);
            $this->provisioning->markManualReview(
                $instance,
                __('provisioning::errors.provider_failed'),
            );
            return false;
        }
    }

    /** @param string|array<string, mixed> $plan @return array<string, mixed> */
    private function normalizePlan(ServiceInstance $instance, string|array $plan): array
    {
        $meta = is_array($instance->meta) ? $instance->meta : [];
        $settings = $instance->providerSettings ?? $instance->provider_settings_snapshot ?? [];
        $requirements = is_array($meta['provisioning_capacity_requirements'] ?? null)
            ? $meta['provisioning_capacity_requirements']
            : [];
        $capacityKey = is_string($meta['provisioning_capacity_key'] ?? null)
            ? $meta['provisioning_capacity_key']
            : null;

        if (is_string($plan)) {
            return [
                'id' => $plan,
                'provider_key' => $instance->provider_key,
                'provider_settings' => $settings,
                'server_id' => $instance->provisioning_server_id,
                'capacity_key' => $capacityKey,
                'requirements' => $requirements,
                'previous_capacity_key' => null,
                'previous_requirements' => null,
                'previous_product_id' => null,
                'previous_provider_key' => null,
            ];
        }

        $normalized = $plan;
        $normalized['id'] = is_scalar($plan['id'] ?? null) ? (string) $plan['id'] : '';
        $normalized['provider_key'] = is_string($plan['provider_key'] ?? null) ? $plan['provider_key'] : $instance->provider_key;
        $normalized['provider_settings'] = is_array($plan['provider_settings'] ?? null) ? $plan['provider_settings'] : $settings;
        $serverId = $instance->provisioning_server_id;
        if (array_key_exists('server_id', $plan)) {
            $rawServerId = $plan['server_id'];
            $serverId = is_int($rawServerId) && $rawServerId > 0
                ? $rawServerId
                : (is_string($rawServerId) && preg_match('/^[1-9][0-9]*$/D', $rawServerId) === 1
                    ? filter_var($rawServerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : null);
            if (! is_int($serverId) || $serverId < 1) {
                throw ValidationException::withMessages([
                    'instance' => __('provisioning::errors.provider_failed'),
                ]);
            }
        }
        $normalized['server_id'] = $serverId;
        $normalized['capacity_key'] = null;
        $normalized['requirements'] = [];
        $normalized['previous_product_id'] = is_numeric($plan['previous_product_id'] ?? null) ? (int) $plan['previous_product_id'] : null;
        $normalized['previous_provider_key'] = is_string($plan['previous_provider_key'] ?? null) ? $plan['previous_provider_key'] : null;

        return $normalized;
    }

    private function withInstanceMutex(ServiceInstance $instance, Closure $callback): ServiceInstance
    {
        $lock = Cache::lock('agovena:provisioning:instance:'.$instance->id, 900);
        $lock->block(10);

        try {
            $current = DB::transaction(fn (): ?ServiceInstance => ServiceInstance::query()->lockForUpdate()->find($instance->id));

            return $current instanceof ServiceInstance ? $callback($current) : $instance;
        } finally {
            optional($lock)->release();
        }
    }

    private function revalidateCapacity(
        ServiceInstance $instance,
        ServiceInstanceInfo $info,
        ProvisionerLifecycle $provisioner,
    ): void {
        if (! $provisioner instanceof ChecksProvisioningStock) {
            return;
        }

        $capacityKey = is_array($instance->meta)
            ? $instance->meta['provisioning_capacity_key'] ?? null
            : null;
        $serverSettingsRequired = ($info->meta['server_settings_required'] ?? false) === true;
        $serverSettings = is_array($info->serverSettings)
            ? $info->serverSettings
            : null;
        if ($serverSettingsRequired && (($info->meta['server_settings_unavailable'] ?? false) === true || $serverSettings === null || $serverSettings === [])) {
            $this->reservations->releaseForInstance($instance);

            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.provider_failed'),
            ]);
        }
        if (! is_string($capacityKey) || $capacityKey === '' || $instance->order_id === null || $instance->product_id === null || $instance->provider_key === null) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.provider_failed'),
            ]);
        }

        $product = Product::query()->find($instance->product_id);
        if ($product === null) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.provider_failed'),
            ]);
        }

        $money = $product->money();
        $line = new PricedCartLine(
            productId: $product->id,
            label: $product->name,
            quantity: 1,
            unitPrice: $money,
            lineTotal: $money,
            lineKey: 'service-instance:'.$instance->id,
        );
        $providerSettings = is_array($info->providerSettings)
            ? $info->providerSettings
            : [];
        $serverSettings = is_array($info->serverSettings)
            ? $info->serverSettings
            : null;
        $context = new ProvisioningStockContext(
            product: $product,
            line: $line,
            providerKey: $instance->provider_key,
            providerSettings: $providerSettings,
            serverSettings: $serverSettings,
            serverId: $instance->provisioning_server_id,
            quantityOverride: 1,
            serverSettingsRequired: $serverSettingsRequired,
        );
        $requirements = $provisioner instanceof ProvidesProvisioningCapacityRequirements
            ? $provisioner->capacityRequirements($providerSettings, $serverSettings)
            : [];

        $vectorCapable = $provisioner instanceof ChecksProvisioningStockVector;
        $this->reservations->revalidateAndReserveForInstance(
            $instance,
            function (int $reservedQuantity, array $reservedRequirements) use ($provisioner, $context, $vectorCapable): void {
                if ($vectorCapable) {
                    $provisioner->assertStockVector($context, $reservedRequirements);
                } else {
                    $provisioner->assertStock($context, $reservedQuantity);
                }
            },
            $requirements,
            $vectorCapable,
        );
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{0: string, 1: array<string, int|string>, 2: bool}
     */
    private function revalidatePlanCapacity(
        ServiceInstance $instance,
        ServiceInstanceInfo $info,
        ProvisionerLifecycle $provisioner,
        array $plan,
        ?string $previousCapacityKey,
        ?array $previousRequirements,
        ?int $previousProductId,
        ?string $previousProviderKey,
    ): array {
        $serverSettingsRequired = ($info->meta['server_settings_required'] ?? false) === true;
        $serverSettings = is_array($plan['server_settings'] ?? null)
            ? $plan['server_settings']
            : $info->serverSettings;
        if ($serverSettingsRequired && (($info->meta['server_settings_unavailable'] ?? false) === true || $serverSettings === null || $serverSettings === [])) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.provider_failed'),
            ]);
        }

        $providerSettings = is_array($plan['provider_settings'] ?? null) ? $plan['provider_settings'] : [];
        $capacityKey = $provisioner->capacityKeyForSettings(
                $providerSettings,
                is_int($plan['server_id'] ?? null) ? $plan['server_id'] : $instance->provisioning_server_id,
                $serverSettings,
            ) ?: '';
        if ($capacityKey === '' || $instance->order_id === null || $instance->product_id === null || $instance->provider_key === null) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.provider_failed'),
            ]);
        }

        $product = Product::query()->find($instance->product_id);
        if ($product === null) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.provider_failed'),
            ]);
        }

        $requirements = $provisioner instanceof ProvidesProvisioningCapacityRequirements
                ? $provisioner->capacityRequirements($providerSettings, $serverSettings)
                : [];
        $money = $product->money();
        $context = new ProvisioningStockContext(
            product: $product,
            line: new PricedCartLine(
                productId: $product->id,
                label: $product->name,
                quantity: 1,
                unitPrice: $money,
                lineTotal: $money,
                lineKey: 'service-instance:'.$instance->id,
            ),
            providerKey: $instance->provider_key,
            providerSettings: $providerSettings,
            serverSettings: $serverSettings,
            serverId: is_int($plan['server_id'] ?? null) ? $plan['server_id'] : $instance->provisioning_server_id,
            quantityOverride: 1,
            serverSettingsRequired: $serverSettingsRequired,
        );
        $vectorCapable = $provisioner instanceof ChecksProvisioningStockVector;
        $capacityReservationNeedsRelease = $this->reservations->revalidateAndReserveForInstance(
            $instance,
            function (int $reservedQuantity, array $reservedRequirements) use ($provisioner, $context, $vectorCapable): void {
                if ($vectorCapable) {
                    $provisioner->assertStockVector($context, $reservedRequirements);
                } else {
                    $provisioner->assertStock($context, $reservedQuantity);
                }
            },
            $requirements,
            $vectorCapable,
            $capacityKey,
            $previousCapacityKey,
            $previousRequirements,
            $previousProductId,
            $previousProviderKey,
        );

        return [$capacityKey, $requirements, $capacityReservationNeedsRelease];
    }

    private function hasReservationIdentity(ServiceInstance $instance): bool
    {
        $capacityKey = is_array($instance->meta)
            ? $instance->meta['provisioning_capacity_key'] ?? null
            : null;

        return $instance->order_id !== null
            && $instance->product_id !== null
            && $instance->provider_key !== null
            && is_string($capacityKey)
            && $capacityKey !== '';
    }

    private function handleUnavailableProvider(ServiceInstance $instance): ServiceInstance
    {
        if (in_array($instance->status, [
            ServiceInstanceStatus::Active,
            ServiceInstanceStatus::Suspended,
            ServiceInstanceStatus::Failed,
            ServiceInstanceStatus::Provisioning,
        ], true)) {
            return $this->provisioning->markManualReview(
                $instance,
                __('provisioning::errors.provider_failed'),
            );
        }

        $this->reservations->releaseForInstance($instance);

        return $this->provisioning->fail($instance, __('provisioning::errors.provider_failed'));
    }

    private function lifecycle(ServiceInstance $instance): ?ProvisionerLifecycle
    {
        if ($instance->provider_key === null || $instance->provider_key === '') {
            return null;
        }

        $provisioner = $this->provisioners->get($instance->provider_key);

        return $provisioner instanceof ProvisionerLifecycle ? $provisioner : null;
    }

    private function applyPolled(ServiceInstance $instance, ServiceInstanceInfo $updated): ServiceInstance
    {
        if (in_array($updated->status, [
            ServiceInstanceStatus::Terminated->value,
            ServiceInstanceStatus::Failed->value,
        ], true)) {
            $this->reservations->releaseForInstance($instance);
        }

        if (($updated->meta['provider_reconciliation'] ?? null) === 'absent'
            && $updated->status !== ServiceInstanceStatus::Terminated->value
        ) {
            $this->reservations->releaseForInstance($instance);
            if (in_array($instance->status, [
                ServiceInstanceStatus::Active,
                ServiceInstanceStatus::Suspended,
                ServiceInstanceStatus::Failed,
                ServiceInstanceStatus::Provisioning,
            ], true)) {
                return $this->provisioning->markManualReview(
                    $instance,
                    __('provisioning::errors.provider_failed'),
                );
            }

            return $this->provisioning->fail($instance, __('provisioning::errors.provider_failed'));
        }

        $instance->meta = EloquentProvisionedServiceResolver::sanitizePersistedMeta(
            is_array($instance->meta) ? $instance->meta : [],
        );
        $instance->save();
        $externalRef = $this->preservedExternalReference($instance, $updated);
        $instance = $this->provisioning->updateTracking(
            $instance,
            $updated->providerKey,
            $externalRef,
        );

        $meta = $instance->meta ?? [];
        if ($updated->meta !== []) {
            $updatedMeta = $updated->meta;
            if (array_key_exists('provider_settings_encrypted', $updatedMeta)) {
                $updatedMeta['provider_settings'] = $meta['provider_settings'] ?? [];
            }
            $instance->meta = EloquentProvisionedServiceResolver::sanitizePersistedMeta(
                array_merge($meta, $updatedMeta),
            );
            $instance->save();
        }

        return match ($updated->status) {
            'active' => $instance->status === ServiceInstanceStatus::Active
                ? $instance
                : ($instance->canActivate()
                    ? $this->provisioning->activate($instance, $externalRef)
                    : $instance),
            'suspended' => $instance->status === ServiceInstanceStatus::Suspended
                ? $instance
                : ($instance->canSuspend()
                    ? $this->provisioning->suspend($instance)
                    : $instance),
            'failed' => $instance->status === ServiceInstanceStatus::Failed
                ? $instance
                : $this->provisioning->fail($instance, __('provisioning::errors.provider_failed')),
            'terminated' => $instance->status === ServiceInstanceStatus::Terminated
                ? $instance
                : ($instance->canTerminate()
                    ? $this->provisioning->terminate($instance)
                    : $instance),
            'manual_review' => $this->provisioning->markManualReview(
                $instance,
                __('provisioning::errors.provider_failed'),
            ),
            default => $instance,
        };
    }

    private function preservedExternalReference(ServiceInstance $instance, ServiceInstanceInfo $updated): ?string
    {
        if ($updated->externalRef === null || trim($updated->externalRef) === '') {
            return null;
        }

        $current = is_string($instance->external_ref) ? trim($instance->external_ref) : '';
        $synthetic = 'agovena-'.$updated->providerKey.'-'.$instance->id;
        if ($current !== '' && $updated->externalRef === $synthetic && $current !== $synthetic) {
            return $current;
        }

        return $updated->externalRef;
    }
}
