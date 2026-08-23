<?php

declare(strict_types=1);

namespace Agovena\Extensions\Stripe;

/**
 * Extension-owned Stripe API seam. Core never sees SDK types.
 */
interface StripeApi
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCheckoutSession(array $payload, ?string $idempotencyKey = null): array;

    /**
     * @return array<string, mixed>
     */
    public function retrieveCheckoutSession(string $id): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createPaymentIntent(array $payload, ?string $idempotencyKey = null): array;

    /**
     * @return array<string, mixed>
     */
    public function retrievePaymentIntent(string $id): array;

    /**
     * @return array<string, mixed>
     */
    public function cancelPaymentIntent(string $id): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function refundPaymentIntent(string $paymentIntentId, array $payload, ?string $idempotencyKey = null): array;

    /**
     * @return array<string, mixed>
     */
    public function retrieveBalance(): array;

    /**
     * Verify a signed Stripe webhook and return the normalized event array.
     *
     * @return array<string, mixed>
     */
    public function constructEvent(string $payload, string $signature, string $secret): array;
}
