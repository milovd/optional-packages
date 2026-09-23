<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

interface PaddleApi
{
    /** @param array<string, mixed> $payload */
    public function createTransaction(array $payload, ?string $idempotencyKey = null): array;

    /** @param array<string, mixed> $payload */
    public function previewTransaction(array $payload): array;

    /** @return array<string, mixed> */
    public function getTransaction(string $transactionId): array;

    /** @return array<string, mixed> */
    public function getSubscription(string $subscriptionId): array;

    /** @return array<string, mixed> */
    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = true): array;

    /** @return array<string, mixed> */
    public function clearScheduledSubscriptionChange(string $subscriptionId): array;

    /**
     * @param  list<array{item_id: string, type: string, amount?: string}>|null  $items
     * @return array<string, mixed>
     */
    public function createAdjustment(
        string $transactionId,
        string $reason,
        string $type = 'full',
        ?array $items = null,
        ?string $idempotencyKey = null,
    ): array;
}
