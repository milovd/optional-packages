<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Http\Livewire\Customer;

use Agovena\Modules\Subscriptions\Models\Subscription;
use Agovena\Modules\Subscriptions\SubscriptionService;
use App\Agovena\PlanChanges\PlanChangeCatalog;
use App\Agovena\PlanChanges\RequestPlanChange;
use App\Agovena\Theme\ThemeManager;
use App\Models\Customer;
use App\Models\Product;
use Livewire\Component;

final class SubscriptionsIndex extends Component
{
    public function requestPlanChange(
        int $subscriptionId,
        int $targetProductId,
        RequestPlanChange $request,
    ): void {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        $subscription = Subscription::query()
            ->whereKey($subscriptionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();
        $from = $subscription->product()->firstOrFail();
        $to = Product::query()->active()->findOrFail($targetProductId);
        $change = $request->handle($customer, $from, $to, $subscription->id);

        session()->flash(
            'status',
            $change->order_id !== null
                ? __('subscriptions::customer.plan_change_order_created')
                : __('subscriptions::customer.plan_change_requested'),
        );
    }

    public function cancel(int $id, SubscriptionService $subscriptions): void
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();

        $subscription = Subscription::query()
            ->whereKey($id)
            ->where(function ($q) use ($customer): void {
                $q->where('customer_id', $customer->id)
                    ->orWhere('customer_email', $customer->email);
            })
            ->firstOrFail();

        $subscriptions->cancel($subscription, atPeriodEnd: true);
        session()->flash('status', __('subscriptions::customer.cancelled'));
    }

    public function render(ThemeManager $themes)
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();

        $subscriptions = Subscription::query()
            ->with('product')
            ->where(function ($q) use ($customer): void {
                $q->where('customer_id', $customer->id)
                    ->orWhere('customer_email', $customer->email);
            })
            ->orderByDesc('id')
            ->get();

        $theme = $themes->active();
        $catalog = app(PlanChangeCatalog::class);
        $planTargets = [];
        foreach ($subscriptions as $subscription) {
            $planTargets[$subscription->id] = $subscription->product !== null
                ? $catalog->targets($subscription->product)
                : collect();
        }

        return view($theme->view('account.subscriptions'), [
            'theme' => $theme,
            'subscriptions' => $subscriptions,
            'planTargets' => $planTargets,
            'accountSection' => 'subscriptions',
        ])->layout($theme->view('layouts.storefront'), [
            'title' => __('subscriptions::customer.title'),
            'theme' => $theme,
        ]);
    }
}
