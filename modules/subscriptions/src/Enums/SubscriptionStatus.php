<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Ended = 'ended';
}
