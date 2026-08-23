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
            ->where(function ($query) use ($customer): void {
                $query->where('customer_id', $customer->id)
                    ->orWhere(function ($query) use ($customer): void {
                        $query->whereNull('customer_id')->where('customer_email', $customer->email);
                    });
            })
            ->first();

        return $instance instanceof ServiceInstance ? self::info($instance) : null;
    }

    public static function info(ServiceInstance $instance): ServiceInstanceInfo
    {
        $meta = $instance->meta ?? [];
        if ($instance->provisioning_server_id !== null) {
            $server = ProvisioningServer::query()->find($instance->provisioning_server_id);
            if ($server !== null && $server->is_active && $server->provider_key === $instance->provider_key) {
                $meta['server_settings'] = $server->settings;
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
