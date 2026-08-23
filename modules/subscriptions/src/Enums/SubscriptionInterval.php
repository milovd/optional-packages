<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Enums;

enum SubscriptionInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
