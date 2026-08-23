<?php

declare(strict_types=1);

namespace Agovena\Modules\Inventory;

use App\Agovena\Catalog\Contracts\ProductStock;
use App\Models\Product;

final class CatalogProductStock implements ProductStock
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function quantityFor(Product $product): int
    {
        return $this->inventory->quantityFor($product);
    }

    public function setQuantity(Product $product, int $quantity): void
    {
        $this->inventory->setQuantity($product, $quantity);
    }
}
