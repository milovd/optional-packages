<?php

declare(strict_types=1);

namespace Agovena\Extensions\Proxmox;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $service_instance_id
 * @property int $vmid
 * @property string $node
 * @property string $hostname
 * @property string $external_id
 * @property string|null $power_status
 */
final class ProxmoxVm extends Model
{
    protected $table = 'proxmox_vms';

    protected $fillable = [
        'service_instance_id',
        'vmid',
        'node',
        'hostname',
        'external_id',
        'power_status',
    ];
}
