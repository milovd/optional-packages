<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Enums;

enum ShipmentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Processing, self::Shipped, self::Cancelled], true),
            self::Processing => in_array($next, [self::Shipped, self::Cancelled], true),
            self::Shipped => in_array($next, [self::Delivered, self::Cancelled], true),
            self::Delivered, self::Cancelled => false,
        };
    }
}
