<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Listeners;

use Agovena\Modules\Subscriptions\Enums\SubscriptionStatus;
use Agovena\Modules\Subscriptions\Models\Subscription;
use App\Events\PlanChangeApplied;
use App\Models\Product;

final class ApplyPlanChangeToSubscription
{
    public function handle(PlanChangeApplied $event): void
    {
        $subscriptionId = $event->request->subscription_id;
        if ($subscriptionId === null) {
            return;
        }

        $subscription = Subscription::query()->whereKey($subscriptionId)->first();
        if ($subscription === null) {
            return;
        }

        $to = Product::query()->find($event->request->to_product_id);
        if ($to === null) {
            return;
        }

        if (in_array($subscription->status, [SubscriptionStatus::Ended, SubscriptionStatus::Cancelled], true)) {
            return;
        }

        $subscription->product_id = $to->id;
        $subscription->price_amount = $to->price_amount;
        $subscription->currency = $to->currency;
        $subscription->save();
    }
}
