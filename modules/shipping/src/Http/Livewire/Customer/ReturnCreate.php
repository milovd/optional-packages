<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Http\Livewire\Customer;

use Agovena\Modules\Shipping\ReturnRequestService;
use App\Agovena\Theme\ThemeManager;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class ReturnCreate extends Component
{
    public Order $order;

    public string $reason = '';

    public function mount(Order $order): void
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();

        abort_unless((int) $order->customer_id === (int) $customer->id, 404);

        $this->order = $order->load(['items.product.images']);
    }

    public function submit(ReturnRequestService $returns): void
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();

        $lines = $returns->eligibleItems($this->order)
            ->map(function (OrderItem $item) use ($returns): ?array {
                $qty = $returns->returnableQuantity($item);

                return $qty > 0
                    ? ['order_item_id' => (int) $item->id, 'quantity' => $qty]
                    : null;
            })
            ->filter()
            ->values()
            ->all();

        try {
            $returns->requestFromCustomer($this->order, $lines, $this->reason, $customer);
        } catch (ValidationException $e) {
            $this->addError('form', collect($e->errors())->flatten()->first() ?? $e->getMessage());

            return;
        }

        session()->flash('status', __('shipping::returns.customer_submitted'));

        $this->redirectRoute('customer.returns');
    }

    public function render(ThemeManager $themes, ReturnRequestService $returns)
    {
        $lines = $returns->eligibleItems($this->order)
            ->map(static fn (OrderItem $item): array => [
                'item' => $item,
                'returnable' => $returns->returnableQuantity($item),
            ])
            ->filter(static fn (array $line): bool => $line['returnable'] > 0)
            ->values();

        $theme = $themes->active();

        return view($theme->view('account.returns.create'), [
            'theme' => $theme,
            'order' => $this->order,
            'lines' => $lines,
            'accountSection' => 'returns',
        ])->layout($theme->view('layouts.storefront'), [
            'title' => __('shipping::returns.customer_create_title', ['number' => $this->order->number]),
            'theme' => $theme,
        ]);
    }
}
