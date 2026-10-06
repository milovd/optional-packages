<?php

declare(strict_types=1);

namespace Agovena\Extensions\Enhance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Claim row proving that Agovena created an Enhance customer org, subscription and
 * website itself. remote_id holds `pending:<service_instance_id>` until the
 * subscription create returns its id; remote_name is the website domain.
 *
 * @property int $id
 * @property int $service_instance_id
 * @property string $endpoint
 * @property string $remote_id
 * @property string|null $remote_name
 * @property string|null $customer_org_id
 * @property string|null $website_id
 * @property string|null $plan
 * @property string $state
 * @property int $revision
 * @property Carbon $created_at
 */
final class EnhanceAccount extends Model
{
    public const STATE_CREATING = 'creating';

    public const STATE_ACTIVE = 'active';

    public const STATE_SUSPENDED = 'suspended';

    public const STATE_TERMINATED = 'terminated';

    public const STATE_FAILED = 'failed';

    public const STATE_UNKNOWN = 'unknown';

    protected $table = 'enhance_accounts';

    protected $fillable = [
        'service_instance_id',
        'endpoint',
        'remote_id',
        'remote_name',
        'customer_org_id',
        'website_id',
        'plan',
        'state',
        'revision',
    ];

    protected $attributes = [
        'revision' => 0,
    ];

    protected function casts(): array
    {
        return [
            'service_instance_id' => 'integer',
            'revision' => 'integer',
        ];
    }
}
