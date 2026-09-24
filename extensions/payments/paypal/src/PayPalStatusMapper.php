<?php

declare(strict_types=1);

namespace Agovena\Extensions\PayPal;

use App\Enums\PaymentStatus;

final class PayPalStatusMapper
{
    public static function map(string $providerStatus): PaymentStatus
    {
        return match (strtoupper(trim($providerStatus))) {
            'COMPLETED', 'ACTIVE' => PaymentStatus::Paid,
            'VOIDED', 'CANCELLED', 'EXPIRED' => PaymentStatus::Cancelled,
            'DECLINED', 'FAILED', 'DENIED', 'REVERSED' => PaymentStatus::Failed,
            'PARTIALLY_REFUNDED' => PaymentStatus::PartiallyRefunded,
            'REFUNDED' => PaymentStatus::Refunded,
            'CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED', 'APPROVAL_PENDING', 'SUSPENDED', 'PENDING' => PaymentStatus::Pending,
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
            'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.SALE.COMPLETED' => PaymentStatus::Paid,
            'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.REFUND.PENDING' => PaymentStatus::Pending,
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED',
            'PAYMENT.SALE.DENIED', 'PAYMENT.SALE.REVERSED',
            'PAYMENT.CAPTURE.REFUND.FAILED' => PaymentStatus::Failed,
            'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.SALE.REFUNDED' => PaymentStatus::Refunded,
            'PAYMENT.CAPTURE.REVERSED' => PaymentStatus::Cancelled,
            'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.EXPIRED' => PaymentStatus::Cancelled,
            'BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.UPDATED',
            'BILLING.SUBSCRIPTION.SUSPENDED' => PaymentStatus::Pending,
            default => null,
        };
    }
}
