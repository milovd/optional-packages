<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping;

use Agovena\Modules\Shipping\Enums\ReturnRequestStatus;
use Agovena\Modules\Shipping\Models\ReturnRequest;
use Agovena\Modules\Shipping\Models\ReturnRequestItem;
use App\Agovena\Catalog\Contracts\ProductStock;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Physical returns are deliberately isolated from money movement: approving, receiving,
 * or restocking a return never records a refund or issues a credit note. Staff perform
 * those on the order/invoice as separate, explicit actions.
 */
final class ReturnRequestService
{
    public const CAPABILITY = 'shippable';

    /**
     * Lines a customer may return. Falls back to every order line when nothing on the
     * order carries the shippable capability, so returns stay usable without it.
     *
     * @return Collection<int, OrderItem>
     */
    public function eligibleItems(Order $order): Collection
    {
        $items = $order->loadMissing('items')->items;

        $shippable = $items->filter(function (OrderItem $item): bool {
            if ($item->product_id === null) {
                return false;
            }

            $product = Product::query()->with('capabilities')->find($item->product_id);

            return $product !== null && $product->hasCapability(self::CAPABILITY);
        })->values();

        return $shippable->isNotEmpty() ? $shippable : $items->values();
    }

    /**
     * Quantity of an order line already covered by returns that have not been rejected
     * or cancelled.
     */
    public function returnedQuantity(int $orderItemId, ?int $ignoreRequestId = null): int
    {
        $openStatuses = array_map(
            static fn (ReturnRequestStatus $status): string => $status->value,
            array_filter(
                ReturnRequestStatus::cases(),
                static fn (ReturnRequestStatus $status): bool => $status->isOpen(),
            ),
        );

        return (int) ReturnRequestItem::query()
            ->where('order_item_id', $orderItemId)
            ->whereHas('returnRequest', function ($query) use ($openStatuses, $ignoreRequestId): void {
                $query->whereIn('status', $openStatuses);
                if ($ignoreRequestId !== null) {
                    $query->whereKeyNot($ignoreRequestId);
                }
            })
            ->sum('quantity');
    }

    public function returnableQuantity(OrderItem $item): int
    {
        return max(0, (int) $item->quantity - $this->returnedQuantity((int) $item->id));
    }

    /**
     * @param  list<array{order_item_id: int, quantity: int}>  $lines
     */
    public function requestFromCustomer(
        Order $order,
        array $lines,
        ?string $reason = null,
        ?Customer $customer = null,
    ): ReturnRequest {
        if ($customer !== null && (int) $order->customer_id !== (int) $customer->id) {
            throw ValidationException::withMessages([
                'order' => __('shipping::returns.errors.not_your_order'),
            ]);
        }

        if ($order->status !== OrderStatus::Paid) {
            throw ValidationException::withMessages([
                'order' => __('shipping::returns.errors.order_not_paid'),
            ]);
        }

        $eligible = $this->eligibleItems($order)->keyBy('id');

        $requested = [];
        foreach ($lines as $line) {
            $itemId = (int) $line['order_item_id'];
            $quantity = (int) $line['quantity'];

            if ($quantity < 1) {
                continue;
            }

            /** @var OrderItem|null $item */
            $item = $eligible->get($itemId);
            if ($item === null) {
                throw ValidationException::withMessages([
                    'items' => __('shipping::returns.errors.item_not_returnable'),
                ]);
            }

            if ($quantity > $this->returnableQuantity($item)) {
                throw ValidationException::withMessages([
                    'items' => __('shipping::returns.errors.quantity_exceeds_purchased'),
                ]);
            }

            $requested[$itemId] = ($requested[$itemId] ?? 0) + $quantity;
        }

        if ($requested === []) {
            throw ValidationException::withMessages([
                'items' => __('shipping::returns.errors.no_items'),
            ]);
        }

        $customerId = $customer !== null ? $customer->id : $order->customer_id;

        return DB::transaction(function () use ($order, $customerId, $reason, $requested): ReturnRequest {
            $request = ReturnRequest::query()->create([
                'order_id' => $order->id,
                'customer_id' => $customerId,
                'customer_email' => (string) $order->customer_email,
                'status' => ReturnRequestStatus::Requested,
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'requested_at' => now(),
            ]);

            foreach ($requested as $itemId => $quantity) {
                ReturnRequestItem::query()->create([
                    'return_request_id' => $request->id,
                    'order_item_id' => $itemId,
                    'quantity' => $quantity,
                ]);
            }

            return $request->load('items');
        });
    }

    public function approve(ReturnRequest $request, ?int $actorId = null): ReturnRequest
    {
        $this->assertTransition($request, ReturnRequestStatus::Approved);

        $request->status = ReturnRequestStatus::Approved;
        $request->approved_at = now();
        $request->approved_by = $actorId;
        $request->save();

        return $request->fresh(['items']) ?? $request;
    }

    public function reject(ReturnRequest $request, string $reason, ?int $actorId = null): ReturnRequest
    {
        $this->assertTransition($request, ReturnRequestStatus::Rejected);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'staff_notes' => __('shipping::returns.errors.reject_reason_required'),
            ]);
        }

        $request->status = ReturnRequestStatus::Rejected;
        $request->rejected_at = now();
        $request->rejected_by = $actorId;
        $request->staff_notes = trim($reason);
        $request->save();

        return $request->fresh(['items']) ?? $request;
    }

    public function markReceived(ReturnRequest $request, ?int $actorId = null): ReturnRequest
    {
        $this->assertTransition($request, ReturnRequestStatus::Received);

        $request->status = ReturnRequestStatus::Received;
        $request->received_at = now();
        $request->received_by = $actorId;
        $request->save();

        return $request->fresh(['items']) ?? $request;
    }

    public function complete(ReturnRequest $request, ?int $actorId = null): ReturnRequest
    {
        $this->assertTransition($request, ReturnRequestStatus::Completed);

        $request->status = ReturnRequestStatus::Completed;
        $request->completed_at = now();
        $request->completed_by = $actorId;
        $request->save();

        return $request->fresh(['items']) ?? $request;
    }

    public function cancel(ReturnRequest $request): ReturnRequest
    {
        $this->assertTransition($request, ReturnRequestStatus::Cancelled);

        $request->status = ReturnRequestStatus::Cancelled;
        $request->cancelled_at = now();
        $request->save();

        return $request->fresh(['items']) ?? $request;
    }

    /**
     * Inventory is optional. Without the Inventory Module the return still completes,
     * staff just adjust stock elsewhere.
     */
    public function inventoryAvailable(): bool
    {
        return app()->bound(ProductStock::class);
    }

    /**
     * @param  array<int, int>  $quantities  return_request_items.id => quantity to restock
     * @return int units actually returned to stock
     */
    public function restock(ReturnRequest $request, array $quantities): int
    {
        if (! $request->status->allowsRestock()) {
            throw ValidationException::withMessages([
                'status' => __('shipping::returns.errors.restock_requires_received'),
            ]);
        }

        if (! $this->inventoryAvailable()) {
            return 0;
        }

        $stock = app(ProductStock::class);

        return DB::transaction(function () use ($request, $quantities, $stock): int {
            $restocked = 0;

            foreach ($request->items()->with('orderItem')->get() as $item) {
                $quantity = (int) ($quantities[$item->id] ?? 0);
                if ($quantity < 1) {
                    continue;
                }

                if ($quantity > $item->restockableQuantity()) {
                    throw ValidationException::withMessages([
                        'restock' => __('shipping::returns.errors.restock_exceeds_returned'),
                    ]);
                }

                $productId = $item->orderItem?->product_id;
                if ($productId === null) {
                    continue;
                }

                $product = Product::query()->with('capabilities')->find($productId);
                if ($product === null) {
                    continue;
                }

                $stock->setQuantity($product, $stock->quantityFor($product) + $quantity);

                $item->restocked_quantity += $quantity;
                $item->save();

                $restocked += $quantity;
            }

            if ($restocked > 0) {
                $request->restocked_at = now();
                $request->save();
            }

            return $restocked;
        });
    }

    private function assertTransition(ReturnRequest $request, ReturnRequestStatus $next): void
    {
        if (! $request->status->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => __('shipping::returns.errors.invalid_transition', [
                    'from' => $request->status->value,
                    'to' => $next->value,
                ]),
            ]);
        }
    }
}
