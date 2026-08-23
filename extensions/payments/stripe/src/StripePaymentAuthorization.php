<?php

declare(strict_types=1);

namespace Agovena\Extensions\Stripe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Extension-owned mapping from Agovena customers to Stripe customer/payment-method ids.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property string $customer_email
 * @property string $stripe_customer_id
 * @property string|null $payment_method_id
 * @property string $status
 * @property Carbon|null $last_verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StripePaymentAuthorization extends Model
{
    protected $table = 'stripe_payment_authorizations';

    protected $fillable = [
        'customer_id',
        'customer_email',
        'stripe_customer_id',
        'payment_method_id',
        'status',
        'last_verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_verified_at' => 'datetime',
        ];
    }
}
