<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

use App\Enums\PaymentStatus;

final class PaddleStatusMapper
{
    public static function map(string $status, ?string $action = null): PaymentStatus
    {
        if ($action === 'refund' && in_array(strtolower(trim($status)), ['approved', 'completed'], true)) {
            return PaymentStatus::Refunded;
        }

        return match (strtolower(trim($status))) {
            'completed', 'paid' => PaymentStatus::Paid,
            'past_due', 'failed' => PaymentStatus::Failed,
            'canceled', 'cancelled' => PaymentStatus::Cancelled,
            'expired' => PaymentStatus::Expired,
            'approved' => PaymentStatus::Pending,
            default => PaymentStatus::Pending,
        };
    }
}
