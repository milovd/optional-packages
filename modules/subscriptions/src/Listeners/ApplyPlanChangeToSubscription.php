<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Listeners;

use Agovena\Modules\Subscriptions\Enums\SubscriptionStatus;
use Agovena\Modules\Subscriptions\Models\Subscription;
use App\Events\PlanChangeApplied;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

final class ApplyPlanChangeToSubscription
{
    public function handle(PlanChangeApplied $event): void
    {
        $subscriptionId = $event->request->subscription_id;
        if ($subscriptionId === null) {
            return;
        }

        $subscription = Subscription::query()->whereKey($subscriptionId)->lockForUpdate()->first();
        if ($subscription === null) {
            throw ValidationException::withMessages(['plan' => __('subscriptions::errors.not_found')]);
        }

        if ((int) $subscription->customer_id !== (int) $event->request->customer_id
            || (int) $subscription->product_id !== (int) $event->request->from_product_id
        ) {
            throw ValidationException::withMessages(['plan' => __('subscriptions::errors.conflict')]);
        }

        if ($subscription->status !== SubscriptionStatus::Active) {
            throw ValidationException::withMessages(['plan' => __('subscriptions::errors.conflict')]);
        }

        $to = Product::query()->active()->find($event->request->to_product_id);
        if ($to === null) {
            throw ValidationException::withMessages(['plan' => __('subscriptions::errors.not_found')]);
        }

        $subscription->product_id = $to->id;
        $subscription->price_amount = $to->price_amount;
        $subscription->currency = $to->currency;
        $subscription->save();
    }
}
