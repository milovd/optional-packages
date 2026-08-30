<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'order_id',
    'order_item_id',
    'product_id',
    'provider_key',
    'capacity_key',
    'quantity',
    'requirements',
    'requirements_fingerprint',
    'expires_at',
])]
#[Hidden(['capacity_key'])]
final class CapacityReservation extends Model
{
    protected $table = 'provisioning_capacity_reservations';

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'order_item_id' => 'integer',
            'product_id' => 'integer',
            'quantity' => 'integer',
            'requirements' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
