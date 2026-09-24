<?php

declare(strict_types=1);

namespace Agovena\Extensions\PayPal;

/**
 * Extension-owned PayPal REST API seam. Core never sees HTTP details.
 */
interface PayPalApi
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createOrder(array $payload, ?string $idempotencyKey = null): array;

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $id): array;

    /**
     * @return array<string, mixed>
     */
    public function captureOrder(string $id, ?string $idempotencyKey = null): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function refundCapture(string $captureId, array $payload, ?string $idempotencyKey = null): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function refundSale(string $saleId, array $payload, ?string $idempotencyKey = null): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createSubscription(array $payload, ?string $idempotencyKey = null): array;

    /**
     * @return array<string, mixed>
     */
    public function getSubscription(string $id): array;

    /**
     * @return array<string, mixed>
     */
    public function getPlan(string $id): array;

    public function suspendSubscription(string $id, string $reason): void;

    public function activateSubscription(string $id, string $reason): void;

    public function cancelSubscription(string $id, string $reason): void;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verifyWebhookSignature(array $payload): bool;

    /**
     * Lightweight connectivity check against PayPal.
     */
    public function ping(): void;
}
