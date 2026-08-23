<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Listeners;

use Agovena\Modules\Provisioning\Enums\ServiceInstanceStatus;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use Agovena\Modules\Provisioning\ProvisioningService;
use Agovena\Modules\Subscriptions\Events\SubscriptionEnded;

/**
 * Subscription end is an explicit lifecycle action, not a refund.
 * Active instances are suspended; staff decide whether to terminate the provider later.
 */
final class SuspendServicesWhenSubscriptionEnded
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
    ) {}

    public function handle(SubscriptionEnded $event): void
    {
        $instances = ServiceInstance::query()
            ->where('subscription_id', $event->subscription->id)
            ->where('status', ServiceInstanceStatus::Active)
            ->get();

        foreach ($instances as $instance) {
            $this->provisioning->suspend($instance);
        }
    }
}
