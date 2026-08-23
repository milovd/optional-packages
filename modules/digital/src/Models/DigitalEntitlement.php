<?php

declare(strict_types=1);

namespace Agovena\Modules\Digital\Models;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $order_item_id
 * @property int|null $product_id
 * @property int $digital_asset_id
 * @property int|null $customer_id
 * @property string $customer_email
 * @property string $token
 * @property int|null $download_limit
 * @property int $download_count
 * @property Carbon $granted_at
 * @property Carbon|null $revoked_at
 */
final class DigitalEntitlement extends Model
{
    protected $table = 'digital_entitlements';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'product_id',
        'digital_asset_id',
        'customer_id',
        'customer_email',
        'token',
        'download_limit',
        'download_count',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'download_limit' => 'integer',
            'download_count' => 'integer',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function remainingDownloads(): ?int
    {
        if ($this->download_limit === null) {
            return null;
        }

        return max(0, $this->download_limit - $this->download_count);
    }

    public function canDownload(): bool
    {
        if ($this->isRevoked()) {
            return false;
        }

        if ($this->download_limit === null) {
            return true;
        }

        return $this->download_count < $this->download_limit;
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'digital_asset_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
