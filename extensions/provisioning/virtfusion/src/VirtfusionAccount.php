<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtfusion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Claim row proving that Agovena created a VirtFusion server itself. remote_id holds
 * `pending:<service_instance_id>` until the create call returns the server id.
 * user_relation is the relation string sent with a user Agovena created for this
 * claim; it is null when the user was reused from another claim of the same customer.
 *
 * @property int $id
 * @property int $service_instance_id
 * @property string $endpoint
 * @property string $remote_id
 * @property string|null $remote_name
 * @property int|null $user_id
 * @property string|null $user_relation
 * @property string|null $plan
 * @property string $state
 * @property int $revision
 * @property Carbon $created_at
 */
final class VirtfusionAccount extends Model
{
    public const STATE_CREATING = 'creating';

    public const STATE_ACTIVE = 'active';

    public const STATE_SUSPENDED = 'suspended';

    public const STATE_TERMINATED = 'terminated';

    public const STATE_FAILED = 'failed';

    public const STATE_UNKNOWN = 'unknown';

    protected $table = 'virtfusion_accounts';

    protected $fillable = [
        'service_instance_id',
        'endpoint',
        'remote_id',
        'remote_name',
        'user_id',
        'user_relation',
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
            'user_id' => 'integer',
            'revision' => 'integer',
        ];
    }
}
