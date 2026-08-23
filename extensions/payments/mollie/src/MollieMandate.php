<?php

declare(strict_types=1);

namespace Agovena\Extensions\Mollie;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Extension-owned mapping from Agovena customers to Mollie customer/mandate ids.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property string $customer_email
 * @property string $mollie_customer_id
 * @property string|null $mandate_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MollieMandate extends Model
{
    protected $table = 'mollie_mandates';

    protected $fillable = [
        'customer_id',
        'customer_email',
        'mollie_customer_id',
        'mandate_id',
    ];
}
