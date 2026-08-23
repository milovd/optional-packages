<?php

declare(strict_types=1);

namespace Agovena\Modules\Inventory;

use Agovena\Modules\Inventory\Models\InventoryStock;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryService
{
    public function quantityFor(Product $product): int
    {
        $stock = InventoryStock::query()->where('product_id', $product->id)->first();
        if (! $stock instanceof InventoryStock) {
            return 0;
        }

        return $stock->quantity;
    }

    public function ensureStockRow(Product $product): InventoryStock
    {
        return InventoryStock::query()->firstOrCreate(
            ['product_id' => $product->id],
            [
                'quantity' => 0,
                'track_stock' => true,
                'allow_oversell' => false,
            ],
        );
    }

    public function setQuantity(Product $product, int $quantity, bool $trackStock = true, bool $allowOversell = false): InventoryStock
    {
        $stock = $this->ensureStockRow($product);
        $stock->quantity = max(0, $quantity);
        $stock->track_stock = $trackStock;
        $stock->allow_oversell = $allowOversell;
        $stock->save();

        return $stock;
    }

    public function assertAvailable(Product $product, int $quantity): void
    {
        if (! $product->hasCapability('inventory')) {
            return;
        }

        $stock = InventoryStock::query()->where('product_id', $product->id)->first();
        if ($stock === null) {
            throw ValidationException::withMessages([
                'product' => __('inventory::errors.not_configured'),
            ]);
        }

        if (! $stock->isAvailable($quantity)) {
            throw ValidationException::withMessages([
                'product' => __('inventory::errors.insufficient_stock'),
            ]);
        }
    }

    public function decrement(Product $product, int $quantity): void
    {
        if (! $product->hasCapability('inventory') || $quantity < 1) {
            return;
        }

        DB::transaction(function () use ($product, $quantity): void {
            /** @var InventoryStock|null $stock */
            $stock = InventoryStock::query()->where('product_id', $product->id)->lockForUpdate()->first();
            if ($stock === null || ! $stock->track_stock) {
                return;
            }

            if (! $stock->allow_oversell && $stock->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'product' => __('inventory::errors.insufficient_stock'),
                ]);
            }

            $stock->quantity = max(0, $stock->quantity - $quantity);
            $stock->save();
        });
    }

    public function increment(Product $product, int $quantity): void
    {
        if (! $product->hasCapability('inventory') || $quantity < 1) {
            return;
        }

        DB::transaction(function () use ($product, $quantity): void {
            /** @var InventoryStock|null $stock */
            $stock = InventoryStock::query()->where('product_id', $product->id)->lockForUpdate()->first();
            if ($stock === null || ! $stock->track_stock) {
                return;
            }

            $stock->quantity += $quantity;
            $stock->save();
        });
    }
}
