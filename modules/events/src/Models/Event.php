<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Models;

use Agovena\Modules\Events\Enums\EventStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property EventStatus $status
 * @property string|null $venue
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property-read Collection<int, EventPerformance> $performances
 * @property-read Collection<int, EventTicketType> $ticketTypes
 */
class Event extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'status',
        'venue',
        'starts_at',
        'ends_at',
        'sales_starts_at',
        'sales_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sales_starts_at' => 'datetime',
            'sales_ends_at' => 'datetime',
        ];
    }

    /** @return HasMany<EventPerformance, $this> */
    public function performances(): HasMany
    {
        return $this->hasMany(EventPerformance::class)->orderBy('starts_at');
    }

    /** @return HasMany<EventTicketType, $this> */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(EventTicketType::class);
    }

    /** @return HasMany<EventTicket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(EventTicket::class);
    }
}
