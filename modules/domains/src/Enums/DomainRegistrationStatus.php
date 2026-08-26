<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Enums;

enum DomainRegistrationStatus: string
{
    case Pending = 'pending';
    case Checking = 'checking';
    case Registering = 'registering';
    case Active = 'active';
    case RenewalDue = 'renewal_due';
    case TransferPending = 'transfer_pending';
    case Expired = 'expired';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
