<?php

declare(strict_types=1);

namespace Agovena\Modules\Inventory\Models;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventoryReservation extends Model
{
    protected $fillable = [
        'order_id',
        'order_item_id',
        'product_id',
        'quantity',
        'status',
        'reserved_at',
        'released_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reserved_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
