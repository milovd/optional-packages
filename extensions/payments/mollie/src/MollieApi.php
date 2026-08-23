<?php

declare(strict_types=1);

namespace Agovena\Extensions\Mollie;

/**
 * Extension-owned Mollie API seam. Core never sees SDK types.
 */
interface MollieApi
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createPayment(array $payload, ?string $idempotencyKey = null): array;

    /**
     * @return array<string, mixed>
     */
    public function getPayment(string $id): array;

    /**
     * @return array<string, mixed>
     */
    public function cancelPayment(string $id): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function refundPayment(string $paymentId, array $payload, ?string $idempotencyKey = null): array;

    /**
     * @return list<array{id: string, description: string}>
     */
    public function listEnabledMethods(): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCustomer(array $payload): array;
}
