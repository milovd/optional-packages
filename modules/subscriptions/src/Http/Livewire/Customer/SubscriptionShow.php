<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Http\Livewire\Customer;

use Agovena\Modules\Subscriptions\DescribesSubscriptionBilling;
use Agovena\Modules\Subscriptions\Models\Subscription;
use Agovena\Modules\Subscriptions\SubscriptionService;
use App\Agovena\PlanChanges\CancelPlanChangeRequest;
use App\Agovena\PlanChanges\PlanChangeCatalog;
use App\Agovena\PlanChanges\RequestPlanChange;
use App\Agovena\Theme\ThemeManager;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductPlanChangeRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

final class SubscriptionShow extends Component
{
    public Subscription $subscription;

    public function mount(Subscription $subscription): void
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        abort_unless($this->owns($subscription, $customer), 404);
        $this->subscription = $subscription->load(['product', 'renewals.order.payment']);
    }

    public function requestPlanChange(int $targetProductId, RequestPlanChange $request): void
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        $from = $this->subscription->product()->firstOrFail();
        $to = Product::query()->active()->findOrFail($targetProductId);
        $change = $request->handle($customer, $from, $to, $this->subscription->id);
        $this->refreshSubscription();

        session()->flash(
            'status',
            $change->order_id !== null
                ? __('subscriptions::customer.plan_change_order_created')
                : __('subscriptions::customer.plan_change_requested'),
        );
    }

    public function cancelPlanChange(int $requestId, CancelPlanChangeRequest $cancel): void
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        $change = ProductPlanChangeRequest::query()
            ->whereKey($requestId)
            ->where('customer_id', $customer->id)
            ->where('subscription_id', $this->subscription->id)
            ->firstOrFail();
        $cancel->handle($change);
        $this->refreshSubscription();
        session()->flash('status', __('subscriptions::customer.plan_change_cancelled'));
    }

    public function cancel(SubscriptionService $subscriptions): void
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        abort_unless($this->owns($this->subscription, $customer), 404);
        $subscriptions->cancel($this->subscription, atPeriodEnd: true);
        $this->refreshSubscription();
        session()->flash('status', __('subscriptions::customer.cancelled'));
    }

    public function resume(SubscriptionService $subscriptions): void
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        abort_unless($this->owns($this->subscription, $customer), 404);
        $subscriptions->resume($this->subscription);
        $this->refreshSubscription();
        session()->flash('status', __('subscriptions::customer.resumed'));
    }

    public function render(ThemeManager $themes)
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        $theme = $themes->active();
        $catalog = app(PlanChangeCatalog::class);
        $targets = $this->subscription->product !== null
            ? $catalog->targets($this->subscription->product)
            : collect();
        $pendingChange = ProductPlanChangeRequest::query()
            ->with(['order.payment', 'planChange', 'toProduct'])
            ->where('subscription_id', $this->subscription->id)
            ->where('customer_id', $customer->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        $serviceInstances = collect();
        if (Schema::hasTable('service_instances')) {
            $serviceInstances = DB::table('service_instances')
                ->where('subscription_id', $this->subscription->id)
                ->orderByDesc('id')
                ->get(['id', 'number', 'status', 'product_id']);
        }

        return view($theme->view('account.subscriptions.show'), [
            'theme' => $theme,
            'subscription' => $this->subscription,
            'billing' => app(DescribesSubscriptionBilling::class)->describe($this->subscription),
            'planTargets' => $targets,
            'pendingChange' => $pendingChange,
            'serviceInstances' => $serviceInstances,
            'accountSection' => 'subscriptions',
        ])->layout($theme->view('layouts.storefront'), [
            'title' => __('subscriptions::customer.show_title', ['number' => $this->subscription->number]),
            'theme' => $theme,
        ]);
    }

    private function owns(Subscription $subscription, Customer $customer): bool
    {
        if ($subscription->customer_id !== null) {
            return (int) $subscription->customer_id === (int) $customer->id;
        }

        return $subscription->customer_email === $customer->email;
    }

    private function refreshSubscription(): void
    {
        $this->subscription = $this->subscription->fresh(['product', 'renewals.order.payment']) ?? $this->subscription;
    }
}
