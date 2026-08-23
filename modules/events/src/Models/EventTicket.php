<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Models;

use Agovena\Modules\Events\Enums\EventTicketStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property string $token
 * @property int $event_id
 * @property int $performance_id
 * @property int $ticket_type_id
 * @property int|null $customer_id
 * @property string $customer_email
 * @property string $customer_name
 * @property EventTicketStatus $status
 * @property Carbon|null $checked_in_at
 * @property int|null $checked_in_by
 * @property-read Event|null $event
 * @property-read EventPerformance|null $performance
 * @property-read EventTicketType|null $ticketType
 */
class EventTicket extends Model
{
    protected $fillable = [
        'number',
        'token',
        'event_id',
        'performance_id',
        'ticket_type_id',
        'product_id',
        'order_id',
        'order_item_id',
        'customer_id',
        'customer_email',
        'customer_name',
        'status',
        'checked_in_at',
        'checked_in_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventTicketStatus::class,
            'checked_in_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function performance(): BelongsTo
    {
        return $this->belongsTo(EventPerformance::class, 'performance_id');
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(EventTicketType::class, 'ticket_type_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }
}
