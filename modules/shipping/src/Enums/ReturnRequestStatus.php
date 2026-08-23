<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Enums;

enum ReturnRequestStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Received = 'received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Requested => in_array($next, [self::Approved, self::Rejected, self::Cancelled], true),
            self::Approved => in_array($next, [self::Received, self::Cancelled], true),
            self::Received => $next === self::Completed,
            self::Rejected, self::Completed, self::Cancelled => false,
        };
    }

    /**
     * Open requests still count against the returnable quantity of an order line.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Received, self::Completed], true);
    }

    public function allowsRestock(): bool
    {
        return in_array($this, [self::Received, self::Completed], true);
    }
}
