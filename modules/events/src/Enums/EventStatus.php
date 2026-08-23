<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';
}
