<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property int $capacity
 * @property string|null $venue
 */
class EventPerformance extends Model
{
    protected $fillable = [
        'event_id',
        'starts_at',
        'ends_at',
        'capacity',
        'venue',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(EventTicketType::class, 'performance_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(EventTicket::class, 'performance_id');
    }
}
