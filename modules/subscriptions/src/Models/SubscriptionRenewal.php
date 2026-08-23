<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Models;

use Agovena\Modules\Subscriptions\Enums\RenewalStatus;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $subscription_id
 * @property int|null $order_id
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 * @property RenewalStatus $status
 * @property int $charge_attempts
 * @property CarbonInterface|null $last_charged_at
 * @property CarbonInterface|null $next_retry_at
 * @property string|null $last_error
 * @property bool $auto_charge_attempted
 * @property bool $require_manual_payment
 * @property CarbonInterface|null $failure_notified_at
 */
final class SubscriptionRenewal extends Model
{
    protected $table = 'subscription_renewals';

    protected $fillable = [
        'subscription_id',
        'order_id',
        'period_start',
        'period_end',
        'status',
        'charge_attempts',
        'last_charged_at',
        'next_retry_at',
        'last_error',
        'auto_charge_attempted',
        'require_manual_payment',
        'failure_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'status' => RenewalStatus::class,
            'charge_attempts' => 'integer',
            'last_charged_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'auto_charge_attempted' => 'boolean',
            'require_manual_payment' => 'boolean',
            'failure_notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
