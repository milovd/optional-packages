<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use Agovena\Modules\Provisioning\Enums\ServiceInstanceStatus;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use App\Agovena\Provisioning\Contracts\ProvisionerLifecycle;
use App\Agovena\Provisioning\ProvisionerRegistry;
use App\Agovena\Provisioning\ServiceInstanceInfo;
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
    ) {}

    public function provision(ServiceInstance $instance): ServiceInstance
    {
        $provisioner = $this->lifecycle($instance);
        if ($provisioner === null) {
            return $instance;
        }

        if (in_array($instance->status, [ServiceInstanceStatus::Pending, ServiceInstanceStatus::Failed], true)) {
            $instance = $this->provisioning->markProvisioning($instance);
        }

        try {
            $info = EloquentProvisionedServiceResolver::info($instance);
            $provisioner->provision($info);
            $updated = $provisioner->poll($info);
        } catch (ValidationException $exception) {
            $message = $exception->errors()['instance'][0] ?? __('provisioning::errors.provider_failed');

            return $this->provisioning->fail($instance, $message);
        } catch (Throwable $exception) {
            report($exception);
            $this->provisioning->fail($instance, __('provisioning::errors.provider_failed'));
            throw $exception;
        }

        return $this->applyPolled($instance, $updated);
    }

    public function sync(ServiceInstance $instance): ServiceInstance
    {
        $provisioner = $this->lifecycle($instance);
        if ($provisioner === null) {
            return $instance;
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
        $provisioner = $this->lifecycle($instance);
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
        $provisioner = $this->lifecycle($instance);
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

        return $this->provisioning->terminate($instance);
    }

    public function changePlan(ServiceInstance $instance, string $plan): ServiceInstance
    {
        $provisioner = $this->lifecycle($instance);
        if ($provisioner === null) {
            return $instance;
        }

        try {
            $provisioner->changePlan(EloquentProvisionedServiceResolver::info($instance), $plan);
            $updated = $provisioner->syncStatus(EloquentProvisionedServiceResolver::info($instance->fresh() ?? $instance));
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
        $externalRef = $this->preservedExternalReference($instance, $updated);
        $instance = $this->provisioning->updateTracking(
            $instance,
            $updated->providerKey,
            $externalRef,
        );

        $meta = $instance->meta ?? [];
        if ($updated->meta !== []) {
            $instance->meta = array_merge($meta, $updated->meta);
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
