<?php

declare(strict_types=1);

namespace Agovena\Modules\Inventory\Listeners;

use Agovena\Modules\Inventory\InventoryService;
use App\Events\OrderCancelled;
use App\Models\Product;

final class RestockWhenOrderCancelled
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function handle(OrderCancelled $event): void
    {
        $order = $event->order->loadMissing('items');

        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $product = Product::query()->with('capabilities')->find($item->product_id);
            if ($product === null) {
                continue;
            }

            $this->inventory->increment($product, (int) $item->quantity);
        }
    }
}
