<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Models;

use Agovena\Modules\Domains\Enums\DomainRegistrationStatus;
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
 * @property string|null $customer_email
 * @property string|null $customer_name
 * @property int $unit_index
 * @property string|null $domain_name
 * @property DomainRegistrationStatus $status
 * @property string|null $provider_key
 * @property string|null $registrar_key
 * @property string|null $dns_provider_key
 * @property string|null $provider_reference
 * @property bool $auto_renew
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface|null $registered_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface|null $cancelled_at
 * @property string|null $failure_message
 */
final class DomainRegistration extends Model
{
    protected $table = 'domain_registrations';

    protected $fillable = [
        'number',
        'order_id',
        'order_item_id',
        'product_id',
        'customer_id',
        'customer_email',
        'customer_name',
        'unit_index',
        'domain_name',
        'status',
        'provider_key',
        'registrar_key',
        'dns_provider_key',
        'provider_reference',
        'auto_renew',
        'meta',
        'registered_at',
        'expires_at',
        'failed_at',
        'cancelled_at',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => DomainRegistrationStatus::class,
            'auto_renew' => 'boolean',
            'meta' => 'array',
            'registered_at' => 'datetime',
            'expires_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
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
