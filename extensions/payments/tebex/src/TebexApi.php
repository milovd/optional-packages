<?php

declare(strict_types=1);

namespace Agovena\Extensions\Tebex;

interface TebexApi
{
    /** @param array<string, mixed> $payload */
    public function createBasket(array $payload, ?string $idempotencyKey = null): array;

    /** @return array<string, mixed> */
    public function getBasket(string $ident): array;

    /** @return array<string, mixed> */
    public function addPackage(string $ident, string $packageId, int $quantity, ?string $idempotencyKey = null): array;

    /** @return array<string, mixed> */
    public function getPayment(string $transactionId): array;

    /** @return array<string, mixed> */
    public function refundPayment(string $transactionId, ?string $reason = null, ?string $idempotencyKey = null): array;
}
