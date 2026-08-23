<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Enums;

enum ServiceInstanceStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
    case Failed = 'failed';
}
