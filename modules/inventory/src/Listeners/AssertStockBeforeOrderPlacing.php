<?php

declare(strict_types=1);

namespace Agovena\Modules\Inventory\Listeners;

use Agovena\Modules\Inventory\InventoryService;
use App\Events\OrderPlacing;
use App\Models\Product;

final class AssertStockBeforeOrderPlacing
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function handle(OrderPlacing $event): void
    {
        foreach ($event->lines as $line) {
            $product = Product::query()->with('capabilities')->find($line->productId);
            if ($product === null) {
                continue;
            }

            $this->inventory->assertAvailable($product, $line->quantity);
        }
    }
}
