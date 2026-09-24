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
            'payment.declined' => PaymentStatus::Failed,
            'payment.dispute.lost', 'payment.dispute.closed' => PaymentStatus::Pending,
            'recurring-payment.ended' => PaymentStatus::Cancelled,
            default => PaymentStatus::Pending,
        };
    }

    public static function fromPaymentStatusId(int|string|null $id): PaymentStatus
    {
        return match ((int) $id) {
            1 => PaymentStatus::Paid,
            2 => PaymentStatus::Refunded,
            3 => PaymentStatus::Pending,
            18 => PaymentStatus::Failed,
            19, 21 => PaymentStatus::Pending,
            default => PaymentStatus::Pending,
        };
    }
}
