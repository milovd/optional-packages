<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use Agovena\Modules\Provisioning\Models\ServiceInstance;
use App\Agovena\Provisioning\Contracts\ProvisionerLifecycle;
use App\Agovena\Provisioning\ProvisionerRegistry;
use App\Agovena\Provisioning\ServiceInstanceInfo;
use App\Models\ProductPlanChangeRequest;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use UnitEnum;

final class PlanChangeCompensationRecovery
{
    public function __construct(
        private readonly PlanChangeCompensationJournal $journals,
        private readonly ProvisionerRegistry $provisioners,
        private readonly ServiceInstanceRuntimeSecretStore $serviceRuntimeSecrets,
        private readonly ProvisioningInstanceMutex $instanceMutex,
    ) {}

    public function recover(int $limit = 100): int
    {
        $recovered = 0;
        $handled = 0;
        $limit = max(1, min($limit, 1000));
        foreach ($this->journals->entries() as $entry) {
            if ($handled >= $limit) {
                break;
            }

            $claimToken = null;
            try {
                $claimToken = $this->journals->claim($entry['path']);
                if ($claimToken === null) {
                    continue;
                }
            } catch (Throwable $exception) {
                Log::error('Plan-change compensation recovery lock failed.', [
                    'exception' => $exception::class,
                ]);

                continue;
            }

            try {
                if (($entry['invalid'] ?? false) === true) {
                    $this->journals->markManualReview($entry['path'], 'invalid_encrypted_payload', $claimToken);
                    $handled++;

                    continue;
                }

                $payload = $entry['payload'];
                $request = ProductPlanChangeRequest::query()->find((int) ($payload['request_id'] ?? 0));
                if ($request === null) {
                    $this->journals->markManualReview($entry['path'], 'request_missing', $claimToken);
                    $handled++;

                    continue;
                }

                if ($request->status === 'applied') {
                    $this->journals->forget($entry['path'], $claimToken);
                    $recovered++;
                    $handled++;

                    continue;
                }

                $maturity = $this->isMature($payload);
                if ($maturity === null) {
                    $this->journals->markManualReview($entry['path'], 'invalid_service_state', $claimToken);
                    $handled++;

                    continue;
                }
                if (! $maturity) {
                    continue;
                }

                $providerKey = $payload['provider_key'] ?? null;
                if (! is_string($providerKey) || $providerKey === '') {
                    $this->journals->markManualReview($entry['path'], 'provider_invalid', $claimToken);
                    $handled++;

                    continue;
                }
                $provider = $this->provisioners->get($providerKey);
                if (! $provider instanceof ProvisionerLifecycle) {
                    throw new RuntimeException('Plan-change compensation provider is unavailable.');
                }

                $instanceId = $payload['instance_id'] ?? null;
                if (! is_int($instanceId)) {
                    $this->journals->markManualReview($entry['path'], 'instance_invalid', $claimToken);
                    $handled++;

                    continue;
                }
                $instance = ServiceInstance::query()->find($instanceId);
                if ($instance === null) {
                    $this->journals->markManualReview($entry['path'], 'instance_missing', $claimToken);
                    $handled++;

                    continue;
                }

                $info = $this->info($payload['previous_info'] ?? null);
                $journalPath = $entry['path'];
                if (! is_string($claimToken) || ! $this->journals->isClaimOwner($journalPath, $claimToken)) {
                    throw new RuntimeException('Plan-change compensation journal claim was lost.');
                }
                $ownerToken = $claimToken;
                $recoveryResult = $this->instanceMutex->run($instance, function (ServiceInstance $current) use ($provider, $info, $payload, $journalPath, $ownerToken): string {
                    return DB::transaction(function () use ($current, $provider, $info, $payload, $journalPath, $ownerToken): string {
                        if (! $this->journals->isClaimOwner($journalPath, $ownerToken)) {
                            throw new RuntimeException('Plan-change compensation journal claim was lost.');
                        }

                        $guardResult = $this->recoveryGuard($current, $payload);
                        if ($guardResult !== null) {
                            return $guardResult;
                        }

                        $provider->changePlan($info, [
                            'id' => (string) (($payload['previous_state']['product_id'] ?? '')),
                            'provider_settings' => $info->providerSettings ?? [],
                            'server_settings' => $info->serverSettings,
                        ]);
                        $synced = $provider->syncStatus($info);
                        if (! in_array($synced->status, ['active', 'suspended'], true)) {
                            throw new RuntimeException('Plan-change compensation provider did not synchronize successfully.');
                        }

                        $this->restoreState($payload);

                        return 'recovered';
                    });
                });
                if ($recoveryResult === 'recovered') {
                    $this->journals->forget($journalPath, $ownerToken);
                    $recovered++;
                } elseif ($recoveryResult === 'request_applied') {
                    $this->journals->forget($journalPath, $ownerToken);
                } else {
                    $this->journals->markManualReview($journalPath, $recoveryResult, $ownerToken);
                }
                $handled++;
            } catch (InvalidArgumentException $exception) {
                Log::error('Plan-change compensation journal contains invalid state.', [
                    'exception' => $exception::class,
                ]);
                try {
                    $this->journals->markManualReview($entry['path'], 'invalid_service_state', $claimToken);
                } catch (Throwable $manualReviewException) {
                    Log::error('Unable to mark invalid plan-change compensation journal for manual review.', [
                        'exception' => $manualReviewException::class,
                    ]);
                }
                $handled++;
            } catch (Throwable $exception) {
                Log::error('Plan-change compensation recovery failed.', [
                    'exception' => $exception::class,
                ]);
            } finally {
                if ($claimToken !== null) {
                    $this->journals->release($entry['path'], $claimToken);
                }
            }
        }

        return $recovered;
    }

    /** @param array<string, mixed> $payload */
    private function recoveryGuard(ServiceInstance $current, array $payload): ?string
    {
        $request = ProductPlanChangeRequest::query()
            ->whereKey((int) ($payload['request_id'] ?? 0))
            ->lockForUpdate()
            ->first();
        if ($request === null) {
            return 'request_missing';
        }
        if ($request->status === 'applied') {
            return 'request_applied';
        }

        $locked = ServiceInstance::query()->lockForUpdate()->find($current->id);
        if ($locked === null) {
            return 'instance_missing';
        }
        $expected = $payload['applied_state'] ?? null;
        if (! is_array($expected)) {
            return 'applied_state_missing';
        }
        $actual = [
            'status' => $locked->status,
            'product_id' => $locked->product_id,
            'provider_key' => $locked->provider_key,
            'provisioning_server_id' => $locked->provisioning_server_id,
            'external_ref' => $locked->external_ref,
            'meta' => $locked->meta,
        ];
        foreach (array_keys($actual) as $key) {
            if ($this->normalizeForComparison($actual[$key]) != $this->normalizeForComparison($expected[$key] ?? null)) {
                return 'target_state_superseded';
            }
        }

        return null;
    }

    private function normalizeForComparison(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeForComparison($item);
            }

            return $normalized;
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function isMature(array $payload): ?bool
    {
        $timestamp = $payload['updated_at'] ?? $payload['created_at'] ?? null;
        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp)->lte(now()->subMinutes(5));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed>|null $payload */
    private function info(?array $payload): ServiceInstanceInfo
    {
        if (! is_array($payload)
            || ! is_int($payload['id'] ?? null)
            || ! is_string($payload['label'] ?? null)
            || ! is_string($payload['status'] ?? null)
            || ! is_array($payload['meta'] ?? null)
        ) {
            throw new InvalidArgumentException('Invalid plan-change compensation service state.');
        }

        return new ServiceInstanceInfo(
            id: $payload['id'],
            label: $payload['label'],
            status: $payload['status'],
            providerKey: is_string($payload['provider_key'] ?? null) ? $payload['provider_key'] : null,
            externalRef: is_string($payload['external_ref'] ?? null) ? $payload['external_ref'] : null,
            meta: $payload['meta'],
            serverSettings: is_array($payload['server_settings'] ?? null) ? $payload['server_settings'] : null,
            providerSettings: is_array($payload['provider_settings'] ?? null) ? $payload['provider_settings'] : null,
        );
    }

    /** @param array<string, mixed> $payload */
    private function restoreState(array $payload): void
    {
        $state = $payload['previous_state'] ?? null;
        $instanceId = $payload['instance_id'] ?? null;
        if (! is_array($state) || ! is_int($instanceId)) {
            throw new InvalidArgumentException('Invalid plan-change compensation database state.');
        }
        $previousInfo = $payload['previous_info'] ?? null;
        if (! is_array($previousInfo)) {
            throw new InvalidArgumentException('Invalid plan-change compensation service settings.');
        }

        $this->serviceRuntimeSecrets->put(
            $instanceId,
            is_array($previousInfo['server_settings'] ?? null) ? $previousInfo['server_settings'] : null,
            is_array($previousInfo['provider_settings'] ?? null) ? $previousInfo['provider_settings'] : null,
        );

        DB::transaction(function () use ($instanceId, $state): void {
            $instance = ServiceInstance::query()->lockForUpdate()->find($instanceId);
            if ($instance === null) {
                throw new RuntimeException('Plan-change compensation service no longer exists.');
            }

            foreach ([
                'status',
                'product_id',
                'provider_key',
                'provisioning_server_id',
                'external_ref',
                'meta',
                'provisioning_at',
                'activated_at',
                'suspended_at',
                'terminated_at',
                'failed_at',
                'failure_message',
            ] as $attribute) {
                if (array_key_exists($attribute, $state)) {
                    $instance->{$attribute} = $state[$attribute];
                }
            }
            $instance->server_settings_snapshot = null;
            $instance->provider_settings_snapshot = null;
            $instance->save();
        });
    }
}
