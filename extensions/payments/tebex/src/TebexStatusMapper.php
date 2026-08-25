<?php

declare(strict_types=1);

namespace Agovena\Extensions\Tebex;

use App\Enums\PaymentStatus;

final class TebexStatusMapper
{
    public static function fromWebhook(string $type): PaymentStatus
    {
        return match (strtolower(trim($type))) {
            'payment.completed', 'recurring-payment.started', 'recurring-payment.renewed' => PaymentStatus::Paid,
            'payment.refunded' => PaymentStatus::Refunded,
            'payment.declined', 'payment.dispute.lost' => PaymentStatus::Failed,
            'recurring-payment.ended', 'payment.dispute.closed' => PaymentStatus::Cancelled,
            default => PaymentStatus::Pending,
        };
    }

    public static function fromPaymentStatusId(int|string|null $id): PaymentStatus
    {
        return match ((int) $id) {
            1 => PaymentStatus::Paid,
            2, 21 => PaymentStatus::Refunded,
            3 => PaymentStatus::Cancelled,
            18 => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
    }
}
