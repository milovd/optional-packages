<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Models;

use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $return_request_id
 * @property int $order_item_id
 * @property int $quantity
 * @property int $restocked_quantity
 * @property-read ReturnRequest $returnRequest
 * @property-read OrderItem|null $orderItem
 */
final class ReturnRequestItem extends Model
{
    protected $table = 'return_request_items';

    protected $fillable = [
        'return_request_id',
        'order_item_id',
        'quantity',
        'restocked_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'restocked_quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<ReturnRequest, $this> */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function restockableQuantity(): int
    {
        return max(0, $this->quantity - $this->restocked_quantity);
    }
}
