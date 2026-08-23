<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Enums;

enum EventTicketStatus: string
{
    case Issued = 'issued';
    case CheckedIn = 'checked_in';
    case Void = 'void';
}
