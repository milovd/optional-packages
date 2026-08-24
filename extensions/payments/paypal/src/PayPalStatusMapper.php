<?php

declare(strict_types=1);

namespace Agovena\Extensions\PayPal;

use App\Enums\PaymentStatus;

final class PayPalStatusMapper
{
    public static function map(string $providerStatus): PaymentStatus
    {
        return match (strtoupper(trim($providerStatus))) {
            'COMPLETED' => PaymentStatus::Paid,
            'VOIDED' => PaymentStatus::Cancelled,
            'DECLINED', 'FAILED' => PaymentStatus::Failed,
            'PARTIALLY_REFUNDED' => PaymentStatus::PartiallyRefunded,
            'REFUNDED' => PaymentStatus::Refunded,
            'CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED' => PaymentStatus::Pending,
            default => PaymentStatus::Pending,
        };
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public static function fromOrder(array $order): PaymentStatus
    {
        $status = strtoupper((string) ($order['status'] ?? ''));

        if ($status === 'COMPLETED') {
            return PaymentStatus::Paid;
        }

        return self::map($status);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function fromWebhookEvent(array $event): ?PaymentStatus
    {
        $type = (string) ($event['event_type'] ?? '');

        return match ($type) {
            'CHECKOUT.ORDER.APPROVED' => PaymentStatus::Pending,
            'PAYMENT.CAPTURE.COMPLETED' => PaymentStatus::Paid,
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => PaymentStatus::Failed,
            'PAYMENT.CAPTURE.REFUNDED' => PaymentStatus::Refunded,
            'PAYMENT.CAPTURE.REVERSED' => PaymentStatus::Cancelled,
            default => null,
        };
    }
}
