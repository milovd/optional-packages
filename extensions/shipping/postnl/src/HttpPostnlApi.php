<?php

declare(strict_types=1);

namespace Agovena\Extensions\Postnl;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpPostnlApi implements PostnlApi
{
    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
    ) {}

    public function barcode(array $query): array
    {
        return $this->request('GET', '/shipment/v1_1/barcode', query: $query);
    }

    public function createShipment(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/v3/shipment', body: $payload, idempotencyKey: $idempotencyKey, query: ['confirm' => 'true']);
    }

    public function status(string $barcode): array
    {
        return $this->request('GET', '/shipment/v2/status/barcode/'.rawurlencode($barcode));
    }

    public function checkout(array $payload): array
    {
        $decoded = $this->request('POST', '/shipment/v1/checkout', body: $payload);
        $options = $decoded['DeliveryOptions'] ?? $decoded['deliveryOptions'] ?? [];
        if (! is_array($options)) {
            return [];
        }

        $out = [];
        foreach ($options as $option) {
            if (is_array($option)) {
                $out[] = $option;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, scalar|bool>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null, ?string $idempotencyKey = null): array
    {
        $key = $this->apiKey();
        if ($key === null) {
            throw PostnlProviderException::failed('postnl::messages.errors.not_configured');
        }

        $pending = Http::timeout(15)
            ->connectTimeout(5)
            ->withHeaders(array_filter([
                'apikey' => $key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $idempotencyKey,
            ]))
            ->withOptions(['allow_redirects' => false]);

        $url = $this->baseUrl().$path;
        if ($query !== [] && strtoupper($method) === 'POST') {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url, $query),
                'POST' => $pending->post($url, $body ?? []),
                default => throw PostnlProviderException::failed('postnl::messages.errors.create_failed'),
            };
        } catch (ConnectionException) {
            throw PostnlProviderException::failed('postnl::messages.errors.timeout');
        } catch (PostnlProviderException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw PostnlProviderException::failed('postnl::messages.health.unreachable');
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw PostnlProviderException::failed('postnl::messages.errors.not_configured', $response->status());
        }
        if ($response->status() === 400) {
            throw PostnlProviderException::failed('postnl::messages.errors.invalid_address', 400);
        }
        if ($response->status() === 422) {
            throw PostnlProviderException::failed('postnl::messages.errors.unsupported_destination', 422);
        }
        if ($response->failed()) {
            throw PostnlProviderException::failed('postnl::messages.errors.create_failed', $response->status());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw PostnlProviderException::failed('postnl::messages.errors.create_failed');
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function apiKey(): ?string
    {
        $key = $this->settings->get('postnl', 'api_key');
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return trim($key);
    }

    private function baseUrl(): string
    {
        $sandbox = (bool) $this->settings->get('postnl', 'sandbox', true);

        return $sandbox ? 'https://api-sandbox.postnl.nl' : 'https://api.postnl.nl';
    }
}
