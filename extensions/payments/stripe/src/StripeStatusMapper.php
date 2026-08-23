<?php

declare(strict_types=1);

namespace Agovena\Extensions\Stripe;

use App\Enums\PaymentStatus;

final class StripeStatusMapper
{
    public static function map(string $providerStatus, int $amount = 0, int $amountRefunded = 0): PaymentStatus
    {
        $normalized = strtolower(trim($providerStatus));

        if (in_array($normalized, ['succeeded', 'paid', 'complete'], true) && $amountRefunded > 0) {
            if ($amount > 0 && $amountRefunded >= $amount) {
                return PaymentStatus::Refunded;
            }

            return PaymentStatus::PartiallyRefunded;
        }

        return match ($normalized) {
            'succeeded', 'paid', 'complete' => PaymentStatus::Paid,
            'canceled', 'cancelled' => PaymentStatus::Cancelled,
            'payment_failed', 'failed' => PaymentStatus::Failed,
            'expired' => PaymentStatus::Expired,
            'refunded' => PaymentStatus::Refunded,
            'partially_refunded' => PaymentStatus::PartiallyRefunded,
            default => PaymentStatus::Pending,
        };
    }

    public static function fromEventType(string $type, array $object): PaymentStatus
    {
        return match ($type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => self::fromCheckoutSession($object),
            'payment_intent.succeeded' => self::map('succeeded', self::int($object['amount'] ?? 0), self::int($object['amount_refunded'] ?? 0)),
            'payment_intent.payment_failed' => PaymentStatus::Failed,
            'payment_intent.canceled' => PaymentStatus::Cancelled,
            'checkout.session.expired', 'checkout.session.async_payment_failed' => PaymentStatus::Failed,
            'charge.refunded' => self::fromCharge($object),
            default => PaymentStatus::Pending,
        };
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public static function fromCheckoutSession(array $session): PaymentStatus
    {
        $paymentStatus = strtolower((string) ($session['payment_status'] ?? ''));
        if ($paymentStatus === 'paid') {
            return PaymentStatus::Paid;
        }
        if ($paymentStatus === 'unpaid' && strtolower((string) ($session['status'] ?? '')) === 'expired') {
            return PaymentStatus::Expired;
        }

        return PaymentStatus::Pending;
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    public static function fromCharge(array $charge): PaymentStatus
    {
        $amount = self::int($charge['amount'] ?? 0);
        $refunded = self::int($charge['amount_refunded'] ?? 0);
        if ((bool) ($charge['refunded'] ?? false) || ($amount > 0 && $refunded >= $amount)) {
            return PaymentStatus::Refunded;
        }
        if ($refunded > 0) {
            return PaymentStatus::PartiallyRefunded;
        }

        return PaymentStatus::Paid;
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    public static function fromPaymentIntent(array $intent): PaymentStatus
    {
        $amount = self::int($intent['amount'] ?? $intent['amount_received'] ?? 0);
        $refunded = self::int($intent['amount_refunded'] ?? 0);
        $latest = $intent['latest_charge'] ?? null;
        if (is_array($latest)) {
            $refunded = max($refunded, self::int($latest['amount_refunded'] ?? 0));
        }

        return self::map((string) ($intent['status'] ?? 'processing'), $amount, $refunded);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
