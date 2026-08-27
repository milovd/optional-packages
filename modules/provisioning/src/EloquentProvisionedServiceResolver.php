<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use Agovena\Modules\Provisioning\Models\ServiceInstance;
use App\Agovena\Provisioning\Contracts\ResolvesProvisionedServices;
use App\Agovena\Provisioning\ServiceInstanceInfo;
use App\Models\Customer;
use App\Models\ProvisioningServer;

final class EloquentProvisionedServiceResolver implements ResolvesProvisionedServices
{
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
        $meta = $instance->meta ?? [];
        if ($instance->provisioning_server_id !== null) {
            $meta['server_settings_required'] = true;
            $server = ProvisioningServer::query()
                ->where('is_active', true)
                ->find($instance->provisioning_server_id);
            if ($server !== null && $server->is_active && $server->provider_key === $instance->provider_key) {
                $meta['server_settings'] = $server->settings;
                unset($meta['server_settings_unavailable']);
            } else {
                $meta['server_settings'] = [];
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
        );
    }
}
