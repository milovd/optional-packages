<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Models;

use Agovena\Modules\Shipping\Enums\ShippingMethodType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property ShippingMethodType $type
 * @property int|null $zone_id
 * @property array<string, mixed>|null $config
 * @property string $currency
 * @property bool $is_active
 * @property int $sort
 * @property-read ShippingZone|null $zone
 */
final class ShippingMethod extends Model
{
    protected $table = 'shipping_methods';

    protected $fillable = [
        'name',
        'code',
        'type',
        'zone_id',
        'config',
        'currency',
        'is_active',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'type' => ShippingMethodType::class,
            'config' => 'array',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<ShippingZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'zone_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function configArray(): array
    {
        return is_array($this->config) ? $this->config : [];
    }
}
