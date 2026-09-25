<?php

declare(strict_types=1);

namespace Agovena\Extensions\PayPal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Extension-owned mapping from Agovena customers to PayPal vaulted payment tokens.
 * The token is encrypted at rest and is never exposed to Core or storefront code.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property string $customer_email
 * @property string|null $paypal_customer_id
 * @property string|null $payment_token_id
 * @property string|null $payment_token_hash
 * @property string $status
 * @property Carbon|null $last_verified_at
 */
final class PayPalPaymentAuthorization extends Model
{
    protected $table = 'paypal_payment_authorizations';

    protected $fillable = [
        'customer_id',
        'customer_email',
        'paypal_customer_id',
        'payment_token_id',
        'payment_token_hash',
        'status',
        'last_verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_token_id' => 'encrypted',
            'last_verified_at' => 'datetime',
        ];
    }
}
