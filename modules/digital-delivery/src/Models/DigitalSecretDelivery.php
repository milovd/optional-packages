<?php

declare(strict_types=1);

namespace Agovena\Modules\DigitalDelivery\Models;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * One delivered unit of digital value for a customer. The plaintext is only ever
 * reachable through plainValue(); lists and serialization use `value_hint`.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $order_item_id
 * @property int|null $product_id
 * @property int|null $digital_secret_item_id
 * @property int|null $customer_id
 * @property string $customer_email
 * @property string $source
 * @property string $status
 * @property string|null $value_ciphertext
 * @property string|null $value_hint
 * @property string|null $provider_id
 * @property string|null $provider_ref
 * @property Carbon|null $granted_at
 * @property Carbon|null $revoked_at
 */
final class DigitalSecretDelivery extends Model
{
    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_PENDING_MANUAL = 'pending_manual';

    public const STATUS_REVOKED = 'revoked';

    public const SOURCE_POOL = 'pool';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_PROVIDER = 'provider';

    protected $table = 'digital_secret_deliveries';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'product_id',
        'digital_secret_item_id',
        'customer_id',
        'customer_email',
        'source',
        'status',
        'value_hint',
        'provider_id',
        'provider_ref',
        'granted_at',
        'revoked_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'value_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function setPlainValue(string $plain): void
    {
        $normalized = DigitalSecretItem::normalize($plain);
        $this->attributes['value_ciphertext'] = Crypt::encryptString($normalized);
        $this->value_hint = DigitalSecretItem::maskedHint($normalized);
    }

    public function plainValue(): ?string
    {
        $ciphertext = $this->attributes['value_ciphertext'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        return Crypt::decryptString($ciphertext);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null || $this->status === self::STATUS_REVOKED;
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED && ! $this->isRevoked();
    }

    public function isPendingManual(): bool
    {
        return $this->status === self::STATUS_PENDING_MANUAL;
    }

    /**
     * A customer may only read a secret they own on a delivered, non-revoked row.
     */
    public function isReadableBy(Customer $customer): bool
    {
        if (! $this->isDelivered()) {
            return false;
        }

        if ($this->customer_id !== null && $this->customer_id === $customer->id) {
            return true;
        }

        return $this->customer_id === null
            && $this->customer_email !== ''
            && strcasecmp($this->customer_email, (string) $customer->email) === 0;
    }

    /** @return BelongsTo<DigitalSecretItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(DigitalSecretItem::class, 'digital_secret_item_id');
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
