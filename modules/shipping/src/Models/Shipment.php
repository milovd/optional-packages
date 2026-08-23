<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Models;

use Agovena\Modules\Shipping\Enums\ShipmentStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $order_id
 * @property ShipmentStatus $status
 * @property int|null $shipping_method_id
 * @property string|null $shipping_method_label
 * @property int $shipping_amount
 * @property string $currency
 * @property string|null $carrier_name
 * @property string|null $carrier_id
 * @property string|null $external_ref
 * @property string|null $tracking_number
 * @property string|null $tracking_url
 * @property string|null $label_path
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property string|null $notes
 * @property-read Collection<int, ShipmentItem> $items
 * @property-read Order $order
 */
final class Shipment extends Model
{
    protected $table = 'shipments';

    protected $fillable = [
        'order_id',
        'status',
        'shipping_method_id',
        'shipping_method_label',
        'shipping_amount',
        'currency',
        'carrier_name',
        'carrier_id',
        'external_ref',
        'tracking_number',
        'tracking_url',
        'label_path',
        'shipped_at',
        'delivered_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'shipping_amount' => 'integer',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ShippingMethod, $this> */
    public function method(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    /** @return HasMany<ShipmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }
}
