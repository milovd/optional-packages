<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Listeners;

use Agovena\Modules\Shipping\ShipmentService;
use App\Events\OrderCreated;
use App\Models\Product;

final class CreateShipmentWhenOrderCreated
{
    public function __construct(
        private readonly ShipmentService $shipments,
    ) {}

    public function handle(OrderCreated $event): void
    {
        $order = $event->order->loadMissing('items');
        $hasShippable = false;

        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                continue;
            }
            $product = Product::query()->with('capabilities')->find($item->product_id);
            if ($product !== null && $product->hasCapability('shippable')) {
                $hasShippable = true;
                break;
            }
        }

        if (! $hasShippable) {
            return;
        }

        $this->shipments->createPendingForOrder($order, $event->shippingMethodId);
    }
}
