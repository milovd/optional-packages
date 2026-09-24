<?php

declare(strict_types=1);

namespace Agovena\Extensions\Tebex;

interface TebexApi
{
    /** @param array<string, mixed> $payload */
    public function createCheckout(array $payload, ?string $idempotencyKey = null): array;


    /** @return array<string, mixed> */
    public function getPayment(string $transactionId): array;

    /** @return array<string, mixed> */
    public function getRecurringPayment(string $reference): array;

    /** @return array<string, mixed> */
    public function cancelRecurringPayment(string $reference): array;

    /** @return array<string, mixed> */
    public function updateRecurringPaymentStatus(string $reference, string $status, ?string $pausedUntil = null): array;

    /** @return array<string, mixed> */
    public function refundPayment(string $transactionId, ?string $reason = null, ?string $idempotencyKey = null): array;
}
