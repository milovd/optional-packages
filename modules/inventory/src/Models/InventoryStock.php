<?php

declare(strict_types=1);

namespace Agovena\Modules\Inventory\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int $quantity
 * @property bool $track_stock
 * @property bool $allow_oversell
 */
final class InventoryStock extends Model
{
    protected $table = 'inventory_stocks';

    protected $fillable = [
        'product_id',
        'quantity',
        'track_stock',
        'allow_oversell',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'track_stock' => 'boolean',
            'allow_oversell' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isAvailable(int $quantity): bool
    {
        if (! $this->track_stock || $this->allow_oversell) {
            return true;
        }

        return $this->quantity >= $quantity;
    }
}
