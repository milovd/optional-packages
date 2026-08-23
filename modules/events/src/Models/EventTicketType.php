<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $event_id
 * @property int|null $performance_id
 * @property int $product_id
 * @property string $name
 * @property int|null $capacity
 * @property-read Event $event
 * @property-read EventPerformance|null $performance
 */
class EventTicketType extends Model
{
    protected $fillable = [
        'event_id',
        'performance_id',
        'product_id',
        'name',
        'capacity',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(EventTicket::class, 'ticket_type_id');
    }
}
