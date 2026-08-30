<?php

declare(strict_types=1);

namespace Agovena\Extensions\Pterodactyl;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $service_instance_id
 * @property int $server_id
 * @property int|null $node_id
 * @property bool|null $dedicated_ip
 * @property string $identifier
 * @property string|null $uuid
 * @property string $external_id
 * @property string|null $panel_status
 */
final class PterodactylServer extends Model
{
    protected $table = 'pterodactyl_servers';

    protected function casts(): array
    {
        return ['dedicated_ip' => 'boolean'];
    }

    protected $fillable = [
        'service_instance_id',
        'server_id',
        'node_id',
        'dedicated_ip',
        'identifier',
        'uuid',
        'external_id',
        'panel_status',
    ];
}
