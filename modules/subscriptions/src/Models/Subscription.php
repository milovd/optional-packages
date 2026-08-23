<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Models;

use Agovena\Modules\Subscriptions\Enums\SubscriptionInterval;
use Agovena\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $number
 * @property int|null $customer_id
 * @property string $customer_email
 * @property string|null $customer_name
 * @property int|null $product_id
 * @property int|null $order_id
 * @property int|null $order_item_id
 * @property SubscriptionStatus $status
 * @property SubscriptionInterval $interval
 * @property int $interval_count
 * @property int $price_amount
 * @property string $currency
 * @property int $quantity
 * @property string|null $payment_gateway
 * @property CarbonInterface|null $trial_ends_at
 * @property CarbonInterface|null $current_period_start
 * @property CarbonInterface|null $current_period_end
 * @property CarbonInterface|null $next_billing_at
 * @property bool $cancel_at_period_end
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $ended_at
 */
final class Subscription extends Model
{
    protected $table = 'subscriptions';

    protected $fillable = [
        'number',
        'customer_id',
        'customer_email',
        'customer_name',
        'product_id',
        'order_id',
        'order_item_id',
        'status',
        'interval',
        'interval_count',
        'price_amount',
        'currency',
        'quantity',
        'payment_gateway',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'next_billing_at',
        'cancel_at_period_end',
        'cancelled_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'interval' => SubscriptionInterval::class,
            'interval_count' => 'integer',
            'price_amount' => 'integer',
            'quantity' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_billing_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Pending], true);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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

    /** @return HasMany<SubscriptionRenewal, $this> */
    public function renewals(): HasMany
    {
        return $this->hasMany(SubscriptionRenewal::class);
    }
}
