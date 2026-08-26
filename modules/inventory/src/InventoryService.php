<?php

declare(strict_types=1);

namespace Agovena\Modules\Inventory;

use Agovena\Modules\Inventory\Models\InventoryReservation;
use Agovena\Modules\Inventory\Models\InventoryStock;
use App\Models\Order;
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

    public function reserve(Product $product, int $orderId, int $orderItemId, int $quantity): InventoryReservation
    {
        if (! $product->hasCapability('inventory') || $quantity < 1) {
            throw ValidationException::withMessages([
                'product' => __('inventory::errors.not_configured'),
            ]);
        }

        return DB::transaction(function () use ($product, $orderId, $orderItemId, $quantity): InventoryReservation {
            $existing = InventoryReservation::query()->where('order_item_id', $orderItemId)->lockForUpdate()->first();
            if ($existing instanceof InventoryReservation) {
                return $existing;
            }

            $stock = InventoryStock::query()->where('product_id', $product->id)->lockForUpdate()->first();
            if ($stock === null || ! $stock->isAvailable($quantity)) {
                throw ValidationException::withMessages([
                    'product' => __('inventory::errors.insufficient_stock'),
                ]);
            }

            $stock->quantity -= $quantity;
            $stock->save();

            return InventoryReservation::query()->create([
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'status' => 'reserved',
                'reserved_at' => now(),
            ]);
        });
    }

    public function releaseForOrder(Order $order): int
    {
        return DB::transaction(function () use ($order): int {
            $released = 0;
            $reservations = InventoryReservation::query()
                ->where('order_id', $order->id)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $stock = InventoryStock::query()->where('product_id', $reservation->product_id)->lockForUpdate()->first();
                if ($stock !== null && $stock->track_stock) {
                    $stock->quantity += $reservation->quantity;
                    $stock->save();
                }

                $reservation->forceFill([
                    'status' => 'released',
                    'released_at' => now(),
                ])->save();
                $released++;
            }

            return $released;
        });
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
