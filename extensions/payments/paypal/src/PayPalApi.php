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
     */
    public function verifyWebhookSignature(array $payload): bool;

    /**
     * Lightweight connectivity check against PayPal.
     */
    public function ping(): void;
}
