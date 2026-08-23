<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Enums;

enum RenewalStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
