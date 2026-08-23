<?php

declare(strict_types=1);

namespace Agovena\Modules\Digital\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $product_id
 * @property string $label
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property int|null $download_limit
 * @property bool $is_active
 */
final class DigitalAsset extends Model
{
    protected $table = 'digital_assets';

    protected $fillable = [
        'product_id',
        'label',
        'disk',
        'path',
        'filename',
        'download_limit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'download_limit' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<DigitalEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(DigitalEntitlement::class);
    }
}
