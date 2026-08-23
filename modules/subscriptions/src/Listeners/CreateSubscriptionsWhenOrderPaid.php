<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Listeners;

use Agovena\Modules\Subscriptions\SubscriptionService;
use App\Events\OrderPaid;

final class CreateSubscriptionsWhenOrderPaid
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $this->subscriptions->createFromPaidOrder($event->order);
        $this->subscriptions->applyPaidRenewal($event->order);
    }
}
