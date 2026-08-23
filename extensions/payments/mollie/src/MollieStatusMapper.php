<?php

declare(strict_types=1);

namespace Agovena\Extensions\Mollie;

use App\Enums\PaymentStatus;
use App\Support\MoneyFormatter;

final class MollieStatusMapper
{
    public static function map(string $providerStatus, ?array $amountRefunded = null, int $paidMinor = 0, string $currency = 'EUR'): PaymentStatus
    {
        $normalized = strtolower(trim($providerStatus));

        if ($normalized === 'paid' && self::refundedMinor($amountRefunded, $currency) > 0) {
            $refunded = self::refundedMinor($amountRefunded, $currency);
            if ($paidMinor > 0 && $refunded >= $paidMinor) {
                return PaymentStatus::Refunded;
            }

            return PaymentStatus::PartiallyRefunded;
        }

        return match ($normalized) {
            'paid' => PaymentStatus::Paid,
            'failed' => PaymentStatus::Failed,
            'canceled', 'cancelled' => PaymentStatus::Cancelled,
            'expired' => PaymentStatus::Expired,
            'refunded' => PaymentStatus::Refunded,
            'open', 'pending', 'authorized' => PaymentStatus::Pending,
            default => PaymentStatus::Pending,
        };
    }

    /**
     * @param  array<string, mixed>|null  $amountRefunded
     */
    private static function refundedMinor(?array $amountRefunded, string $currency): int
    {
        if ($amountRefunded === null) {
            return 0;
        }

        $value = $amountRefunded['value'] ?? null;
        if (! is_string($value) || $value === '') {
            return 0;
        }

        try {
            return MoneyFormatter::minorFromMajorInput($value, $currency);
        } catch (\Throwable) {
            return 0;
        }
    }
}
