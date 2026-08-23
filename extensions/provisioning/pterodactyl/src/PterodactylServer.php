<?php

declare(strict_types=1);

namespace Agovena\Extensions\Pterodactyl;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $service_instance_id
 * @property int $server_id
 * @property string $identifier
 * @property string|null $uuid
 * @property string $external_id
 * @property string|null $panel_status
 */
final class PterodactylServer extends Model
{
    protected $table = 'pterodactyl_servers';

    protected $fillable = [
        'service_instance_id',
        'server_id',
        'identifier',
        'uuid',
        'external_id',
        'panel_status',
    ];
}
