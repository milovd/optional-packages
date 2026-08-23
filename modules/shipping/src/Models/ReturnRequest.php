<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Models;

use Agovena\Modules\Shipping\Enums\ReturnRequestStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $customer_id
 * @property string $customer_email
 * @property ReturnRequestStatus $status
 * @property string|null $reason
 * @property string|null $staff_notes
 * @property Carbon|null $requested_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $received_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $restocked_at
 * @property int|null $approved_by
 * @property int|null $rejected_by
 * @property int|null $received_by
 * @property int|null $completed_by
 * @property-read Collection<int, ReturnRequestItem> $items
 * @property-read Order $order
 * @property-read Customer|null $customer
 */
final class ReturnRequest extends Model
{
    protected $table = 'return_requests';

    protected $fillable = [
        'order_id',
        'customer_id',
        'customer_email',
        'status',
        'reason',
        'staff_notes',
        'requested_at',
        'approved_at',
        'rejected_at',
        'received_at',
        'completed_at',
        'cancelled_at',
        'restocked_at',
        'approved_by',
        'rejected_by',
        'received_by',
        'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReturnRequestStatus::class,
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'restocked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<ReturnRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    public function isOwnedBy(Customer $customer): bool
    {
        return (int) $this->customer_id === (int) $customer->id;
    }

    public function restockableUnits(): int
    {
        return $this->items->sum(
            static fn (ReturnRequestItem $item): int => $item->restockableQuantity(),
        );
    }
}
