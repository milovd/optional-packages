<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

interface PaddleApi
{
    /** @param array<string, mixed> $payload */
    public function createTransaction(array $payload, ?string $idempotencyKey = null): array;

    /** @return array<string, mixed> */
    public function getTransaction(string $transactionId): array;

    /** @return array<string, mixed> */
    public function createAdjustment(string $transactionId, string $reason, string $type = 'full', ?string $idempotencyKey = null): array;
}
