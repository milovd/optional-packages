<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Http\Livewire\Customer;

use Agovena\Modules\Shipping\Models\ReturnRequest;
use Agovena\Modules\Shipping\ReturnRequestService;
use App\Agovena\Theme\ThemeManager;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use Livewire\Component;

final class ReturnsIndex extends Component
{
    public function cancel(int $returnRequestId, ReturnRequestService $returns): void
    {
        $customer = authenticated_customer();

        $request = ReturnRequest::query()
            ->where('customer_id', $customer->id)
            ->findOrFail($returnRequestId);

        $returns->cancel($request);
        session()->flash('status', __('shipping::returns.customer_cancelled'));
    }

    public function render(ThemeManager $themes, ReturnRequestService $returns)
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();

        $requests = ReturnRequest::query()
            ->with(['order.items.product.images', 'items.orderItem.product.images'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get();

        $eligibleOrders = Order::query()
            ->with(['items.product.images'])
            ->where('customer_id', $customer->id)
            ->where('status', OrderStatus::Paid)
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->filter(fn (Order $order): bool => $returns->eligibleItems($order)
                ->contains(fn ($item): bool => $returns->returnableQuantity($item) > 0))
            ->values();

        $theme = $themes->active();

        return view($theme->view('account.returns.index'), [
            'theme' => $theme,
            'requests' => $requests,
            'eligibleOrders' => $eligibleOrders,
            'accountSection' => 'returns',
        ])->layout($theme->view('layouts.storefront'), [
            'title' => __('shipping::returns.customer_title'),
            'theme' => $theme,
        ]);
    }
}
