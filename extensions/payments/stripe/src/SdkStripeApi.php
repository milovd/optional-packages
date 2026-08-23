<?php

declare(strict_types=1);

namespace Agovena\Extensions\Stripe;

use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Stripe\Webhook;
use Throwable;

/**
 * Official Stripe PHP SDK adapter. SDK types stay inside this class.
 */
final class SdkStripeApi implements StripeApi
{
    public function __construct(
        private readonly string $secretKey,
    ) {}

    public function createCheckoutSession(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->request('post', '/v1/checkout/sessions', $payload, $idempotencyKey);
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return $this->request('get', '/v1/checkout/sessions/'.rawurlencode($id), [
            'expand' => ['payment_intent'],
        ]);
    }

    public function createPaymentIntent(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->request('post', '/v1/payment_intents', $payload, $idempotencyKey);
    }

    public function retrievePaymentIntent(string $id): array
    {
        return $this->request('get', '/v1/payment_intents/'.rawurlencode($id), [
            'expand' => ['latest_charge', 'payment_method'],
        ]);
    }

    public function cancelPaymentIntent(string $id): array
    {
        return $this->request('post', '/v1/payment_intents/'.rawurlencode($id).'/cancel', []);
    }

    public function refundPaymentIntent(string $paymentIntentId, array $payload, ?string $idempotencyKey = null): array
    {
        $payload['payment_intent'] = $paymentIntentId;

        return $this->request('post', '/v1/refunds', $payload, $idempotencyKey);
    }

    public function retrieveBalance(): array
    {
        return $this->request('get', '/v1/balance', []);
    }

    public function constructEvent(string $payload, string $signature, string $secret): array
    {
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException) {
            throw StripeProviderException::failed('stripe::messages.errors.webhook_invalid');
        } catch (Throwable) {
            throw StripeProviderException::failed('stripe::messages.errors.webhook_invalid');
        }

        return $this->toArray($event);
    }

    /**
     * @param  'delete'|'get'|'post'  $method
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $params, ?string $idempotencyKey = null): array
    {
        try {
            $client = new StripeClient([
                'api_key' => $this->secretKey,
                'max_network_retries' => 0,
            ]);
            $opts = [];
            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $opts['idempotency_key'] = $idempotencyKey;
            }
            $result = $client->request($method, $path, $params, $opts);
        } catch (StripeProviderException $exception) {
            throw $exception;
        } catch (ApiConnectionException) {
            throw StripeProviderException::failed('stripe::messages.health.unreachable');
        } catch (ApiErrorException) {
            throw StripeProviderException::failed('stripe::messages.errors.create_failed');
        } catch (Throwable) {
            throw StripeProviderException::failed('stripe::messages.health.unreachable');
        }

        return $this->toArray($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $object): array
    {
        if ($object instanceof StripeObject) {
            /** @var array<string, mixed> $array */
            $array = $object->toArray();

            return $array;
        }
        if (is_array($object)) {
            /** @var array<string, mixed> $object */
            return $object;
        }

        throw StripeProviderException::failed('stripe::messages.errors.create_failed');
    }
}
