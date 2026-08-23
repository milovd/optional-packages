<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Models;

use Agovena\Modules\Provisioning\Enums\ServiceInstanceStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $number
 * @property int|null $order_id
 * @property int|null $order_item_id
 * @property int|null $product_id
 * @property int|null $customer_id
 * @property string $customer_email
 * @property string|null $customer_name
 * @property int|null $subscription_id
 * @property ServiceInstanceStatus $status
 * @property string|null $provider_key
 * @property int|null $provisioning_server_id
 * @property string|null $external_ref
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface|null $provisioning_at
 * @property CarbonInterface|null $activated_at
 * @property CarbonInterface|null $suspended_at
 * @property CarbonInterface|null $terminated_at
 * @property CarbonInterface|null $failed_at
 * @property string|null $failure_message
 */
final class ServiceInstance extends Model
{
    protected $table = 'service_instances';

    protected $fillable = [
        'number',
        'order_id',
        'order_item_id',
        'product_id',
        'customer_id',
        'customer_email',
        'customer_name',
        'subscription_id',
        'status',
        'provider_key',
        'provisioning_server_id',
        'external_ref',
        'meta',
        'provisioning_at',
        'activated_at',
        'suspended_at',
        'terminated_at',
        'failed_at',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceInstanceStatus::class,
            'meta' => 'array',
            'provisioning_at' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function canActivate(): bool
    {
        return in_array($this->status, [
            ServiceInstanceStatus::Pending,
            ServiceInstanceStatus::Provisioning,
            ServiceInstanceStatus::Suspended,
            ServiceInstanceStatus::Failed,
        ], true);
    }

    public function canSuspend(): bool
    {
        return $this->status === ServiceInstanceStatus::Active;
    }

    public function canTerminate(): bool
    {
        return ! in_array($this->status, [ServiceInstanceStatus::Terminated], true);
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
