<?php

declare(strict_types=1);

namespace Agovena\Modules\DigitalDelivery\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A merchant-owned secret in the pool: licence key, code, credential or account secret.
 * The value lives only in `value_ciphertext` and is never a plain attribute.
 *
 * @property int $id
 * @property int $product_id
 * @property string $value_ciphertext
 * @property string|null $value_fingerprint
 * @property string|null $label
 * @property string $status
 * @property Carbon|null $allocated_at
 */
final class DigitalSecretItem extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ALLOCATED = 'allocated';

    public const STATUS_DISABLED = 'disabled';

    protected $table = 'digital_secret_items';

    protected $fillable = [
        'product_id',
        'value_fingerprint',
        'label',
        'status',
        'allocated_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'value_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'allocated_at' => 'datetime',
        ];
    }

    /**
     * Non-reversible fingerprint of a value, used only to detect duplicates in a pool.
     */
    public static function fingerprint(string $plain): string
    {
        return hash('sha256', self::normalize($plain));
    }

    public static function normalize(string $plain): string
    {
        return trim($plain);
    }

    /**
     * Safe-for-lists tail of a secret: **** when too short to mask meaningfully.
     */
    public static function maskedHint(string $plain): string
    {
        $normalized = self::normalize($plain);

        if (Str::length($normalized) <= 4) {
            return '••••';
        }

        return '••••'.Str::substr($normalized, -4);
    }

    public function setPlainValue(string $plain): void
    {
        $normalized = self::normalize($plain);
        $this->attributes['value_ciphertext'] = Crypt::encryptString($normalized);
        $this->value_fingerprint = self::fingerprint($normalized);
    }

    public function plainValue(): ?string
    {
        $ciphertext = $this->attributes['value_ciphertext'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        return Crypt::decryptString($ciphertext);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasOne<DigitalSecretDelivery, $this> */
    public function delivery(): HasOne
    {
        return $this->hasOne(DigitalSecretDelivery::class, 'digital_secret_item_id');
    }
}
