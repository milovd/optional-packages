<?php

declare(strict_types=1);

namespace Agovena\Extensions\PayPal;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpPayPalApi implements PayPalApi
{
    private ?string $accessToken = null;

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
    ) {}

    public function createOrder(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->authorized('POST', '/v2/checkout/orders', $payload, $idempotencyKey);
    }

    public function getOrder(string $id): array
    {
        return $this->authorized('GET', '/v2/checkout/orders/'.rawurlencode($id));
    }

    public function captureOrder(string $id, ?string $idempotencyKey = null): array
    {
        return $this->authorized('POST', '/v2/checkout/orders/'.rawurlencode($id).'/capture', [], $idempotencyKey);
    }

    public function refundCapture(string $captureId, array $payload, ?string $idempotencyKey = null): array
    {
        return $this->authorized(
            'POST',
            '/v2/payments/captures/'.rawurlencode($captureId).'/refund',
            $payload,
            $idempotencyKey,
        );
    }

    public function verifyWebhookSignature(array $payload): bool
    {
        $response = $this->authorized('POST', '/v1/notifications/verify-webhook-signature', $payload);

        return strtoupper((string) ($response['verification_status'] ?? '')) === 'SUCCESS';
    }

    public function ping(): void
    {
        $this->accessToken();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function authorized(string $method, string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        $token = $this->accessToken();
        $url = $this->baseUrl().$path;
        $timeout = 20;

        try {
            $pending = Http::withToken($token)
                ->acceptJson()
                ->timeout($timeout)
                ->withHeaders(array_filter([
                    'PayPal-Request-Id' => is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null,
                ]));

            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url),
                'POST' => $pending->post($url, $body),
                default => throw PayPalProviderException::failed('paypal::messages.errors.provider_failed'),
            };
        } catch (PayPalProviderException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            throw strtoupper($method) === 'POST'
                ? PayPalProviderException::unknown('paypal::messages.health.unreachable')
                : PayPalProviderException::failed('paypal::messages.health.unreachable');
        } catch (Throwable) {
            throw strtoupper($method) === 'POST'
                ? PayPalProviderException::unknown('paypal::messages.errors.provider_failed')
                : PayPalProviderException::failed('paypal::messages.errors.provider_failed');
        }

        return $this->decode($response);
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $clientId = $this->clientId();
        $secret = $this->clientSecret();
        if ($clientId === null || $secret === null) {
            throw PayPalProviderException::failed('paypal::messages.errors.not_configured');
        }

        $url = $this->baseUrl().'/v1/oauth2/token';

        try {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $secret)
                ->acceptJson()
                ->timeout(20)
                ->post($url, ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw PayPalProviderException::failed('paypal::messages.health.unreachable');
        } catch (Throwable) {
            throw PayPalProviderException::failed('paypal::messages.errors.provider_failed');
        }

        $payload = $this->decode($response);
        $token = $payload['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw PayPalProviderException::failed('paypal::messages.health.unauthorized', $response->status());
        }

        $this->accessToken = $token;

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw PayPalProviderException::failed('paypal::messages.errors.unauthorized', $response->status());
        }

        if ($response->status() >= 500) {
            throw PayPalProviderException::failed('paypal::messages.errors.server_error', $response->status());
        }

        if ($response->failed()) {
            throw PayPalProviderException::failed('paypal::messages.errors.provider_failed', $response->status());
        }

        if ($response->body() === '' || $response->body() === '[]') {
            return [];
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw PayPalProviderException::failed('paypal::messages.errors.malformed');
        }

        return $json;
    }

    private function baseUrl(): string
    {
        $sandbox = filter_var($this->settings->get('paypal', 'sandbox', true), FILTER_VALIDATE_BOOLEAN);

        return $sandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    private function clientId(): ?string
    {
        $value = $this->settings->get('paypal', 'client_id');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function clientSecret(): ?string
    {
        $value = $this->settings->get('paypal', 'client_secret');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
