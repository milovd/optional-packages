<?php

declare(strict_types=1);

namespace Agovena\Modules\Inventory\Listeners;

use Agovena\Modules\Inventory\InventoryService;
use App\Events\OrderCreated;
use App\Models\Product;

final class ReserveStockWhenOrderCreated
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function handle(OrderCreated $event): void
    {
        $order = $event->order->loadMissing('items');

        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $product = Product::query()->with('capabilities')->find($item->product_id);
            if ($product === null || ! $product->hasCapability('inventory')) {
                continue;
            }

            $this->inventory->reserve(
                $product,
                (int) $order->id,
                (int) $item->id,
                (int) $item->quantity,
            );
        }
    }
}
