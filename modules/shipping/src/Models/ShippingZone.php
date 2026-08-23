<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property list<string> $countries
 * @property bool $is_active
 * @property int $sort
 */
final class ShippingZone extends Model
{
    protected $table = 'shipping_zones';

    protected $fillable = [
        'name',
        'countries',
        'is_active',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return HasMany<ShippingMethod, $this> */
    public function methods(): HasMany
    {
        return $this->hasMany(ShippingMethod::class, 'zone_id');
    }

    public function coversCountry(string $countryCode): bool
    {
        $code = strtoupper($countryCode);
        $countries = $this->countries ?? [];

        foreach ($countries as $country) {
            if (strtoupper((string) $country) === $code) {
                return true;
            }
        }

        return false;
    }
}
