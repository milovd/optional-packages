<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Http\Livewire\Admin;

use Agovena\Modules\Subscriptions\DescribesSubscriptionBilling;
use Agovena\Modules\Subscriptions\Models\Subscription;
use Agovena\Modules\Subscriptions\SubscriptionService;
use App\Agovena\Admin\AdminRegistrar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

final class SubscriptionShow extends Component
{
    use AuthorizesRequests;

    public Subscription $subscription;

    public function mount(Subscription $subscription): void
    {
        $this->authorize('subscriptions.view');
        $this->subscription = $subscription->load(['product', 'order', 'renewals.order']);
    }

    public function cancelAtPeriodEnd(SubscriptionService $subscriptions): void
    {
        $this->authorize('subscriptions.manage');
        $this->subscription = $subscriptions->cancel($this->subscription, atPeriodEnd: true);
        session()->flash('status', __('subscriptions::admin.cancelled_period_end'));
    }

    public function cancelNow(SubscriptionService $subscriptions): void
    {
        $this->authorize('subscriptions.manage');
        $this->subscription = $subscriptions->cancel($this->subscription, atPeriodEnd: false);
        session()->flash('status', __('subscriptions::admin.cancelled_now'));
    }

    public function resume(SubscriptionService $subscriptions): void
    {
        $this->authorize('subscriptions.manage');
        $this->subscription = $subscriptions->resume($this->subscription);
        session()->flash('status', __('subscriptions::admin.resumed'));
    }

    public function markPastDue(SubscriptionService $subscriptions): void
    {
        $this->authorize('subscriptions.manage');
        $this->subscription = $subscriptions->markPastDue($this->subscription);
        session()->flash('status', __('subscriptions::admin.marked_past_due'));
    }

    public function createRenewal(SubscriptionService $subscriptions): void
    {
        $this->authorize('subscriptions.manage');
        $order = $subscriptions->createRenewalOrder($this->subscription);
        $subscriptions->processRenewalCharge($this->subscription, $order, true);
        $this->subscription = $this->subscription->fresh(['product', 'order', 'renewals.order']) ?? $this->subscription;
        session()->flash('status', __('subscriptions::admin.renewal_created', ['number' => $order->number]));
    }

    public function render(AdminRegistrar $admin, DescribesSubscriptionBilling $billing)
    {
        return view('livewire.admin.subscriptions.show', [
            'subscription' => $this->subscription,
            'billing' => $billing->describe($this->subscription),
        ])->layout('layouts.admin', [
            'title' => __('subscriptions::admin.show_title', ['number' => $this->subscription->number]),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
