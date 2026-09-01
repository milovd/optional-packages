<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use Agovena\Modules\Provisioning\Models\ServiceInstance;
use App\Agovena\Provisioning\Contracts\ResolvesProvisionedServices;
use App\Agovena\Provisioning\ServiceInstanceInfo;
use App\Agovena\Security\SensitiveDataRedactor;
use App\Models\Customer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class EloquentProvisionedServiceResolver implements ResolvesProvisionedServices
{
    /** @param array<string, mixed> $meta @return array<string, mixed> */
    public static function sanitizePersistedMeta(array $meta): array
    {
        return SensitiveDataRedactor::redact($meta);
    }

    public function resolveForCustomer(Customer $customer, int $instanceId): ?ServiceInstanceInfo
    {
        $instance = ServiceInstance::query()
            ->whereKey($instanceId)
            ->where('customer_id', $customer->id)
            ->first();

        return $instance instanceof ServiceInstance ? self::info($instance) : null;
    }

    public static function info(ServiceInstance $instance): ServiceInstanceInfo
    {
        $persistedMeta = is_array($instance->meta) ? $instance->meta : [];
        $meta = self::sanitizePersistedMeta($persistedMeta);
        $serverSettings = null;
        $runtimeSettings = Schema::hasTable('service_instance_runtime_secrets')
            ? app(ServiceInstanceRuntimeSecretStore::class)->get($instance->id)
            : null;
        try {
            $providerSnapshot = $runtimeSettings['provider_settings'] ?? $instance->provider_settings_snapshot;
            $serverSnapshot = $runtimeSettings['server_settings'] ?? $instance->server_settings_snapshot;
        } catch (Throwable) {
            $providerSnapshot = null;
            $serverSnapshot = null;
        }
        $providerSettings = is_array($providerSnapshot) ? $providerSnapshot : null;
        $encryptedProviderSettings = $persistedMeta['provider_settings_encrypted'] ?? null;
        if ($providerSettings === null && is_string($encryptedProviderSettings) && $encryptedProviderSettings !== '') {
            try {
                $providerSettings = json_decode(Crypt::decryptString($encryptedProviderSettings), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($providerSettings)) {
                    $providerSettings = null;
                }
            } catch (Throwable) {
                $providerSettings = null;
            }
        }
        if ($instance->provisioning_server_id !== null) {
            $meta['server_settings_required'] = true;
            if (is_array($serverSnapshot) && $serverSnapshot !== []) {
                $serverSettings = $serverSnapshot;
                unset($meta['server_settings_unavailable']);
            } else {
                $meta['server_settings_unavailable'] = true;
            }
        }

        return new ServiceInstanceInfo(
            id: $instance->id,
            label: (string) ($instance->meta['label'] ?? $instance->number),
            status: $instance->status->value,
            providerKey: $instance->provider_key,
            externalRef: $instance->external_ref,
            meta: $meta,
            serverSettings: $serverSettings,
            providerSettings: $providerSettings,
        );
    }

}
