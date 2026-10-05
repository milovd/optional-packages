<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareDomain;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class HttpCloudflareApi implements CloudflareApi
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    private const DEFAULT_TIMEOUT = 15;

    private const REGISTRATION_TIMEOUT = 45;

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
    ) {}

    public function check(array $domains): array
    {
        return $this->decode($this->send('post', '/registrar/domain-check', ['domains' => $domains]));
    }

    public function register(string $domain, array $payload = []): array
    {
        // No `Prefer: respond-async`: Cloudflare holds the request for its synchronous window
        // and answers 201 with a completed workflow in most cases, or 202 while still
        // processing. The workflow `state` in the body is authoritative for both. A client
        // timeout is reconciled by the registrar through the registration-status endpoint.
        return $this->decode($this->send(
            'post',
            '/registrar/registrations',
            array_merge(['domain_name' => $domain], $payload, ['domain_name' => $domain]),
            self::REGISTRATION_TIMEOUT,
        ));
    }

    public function registrationStatus(string $domain): ?array
    {
        $response = $this->send('get', '/registrar/registrations/'.rawurlencode($domain).'/registration-status');
        if ($response->status() === 404) {
            return null;
        }

        return $this->decode($response);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $method, string $path, array $payload = [], int $timeout = self::DEFAULT_TIMEOUT): Response
    {
        if (app()->environment('demo')) {
            throw new RuntimeException('Cloudflare Registrar requests are disabled in the demo environment.');
        }

        $accountId = trim((string) $this->settings->get('cloudflare-domain', 'account_id', ''));
        $apiToken = trim((string) $this->settings->get('cloudflare-domain', 'api_token', ''));
        if ($accountId === '' || $apiToken === '') {
            throw new RuntimeException('Cloudflare Registrar is not configured.');
        }
        if (! preg_match('/\A[a-zA-Z0-9]{1,32}\z/', $accountId)) {
            throw new RuntimeException('Cloudflare Registrar account ID is invalid.');
        }

        try {
            $request = Http::baseUrl(self::BASE_URL)
                ->withToken($apiToken)
                ->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->timeout($timeout);
            $url = '/accounts/'.$accountId.$path;

            return $method === 'get' ? $request->get($url) : $request->post($url, $payload);
        } catch (Throwable $exception) {
            throw new RuntimeException('Cloudflare Registrar request failed.', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException('Cloudflare Registrar returned an unsuccessful response.');
        }

        $decoded = $response->json();
        if (! is_array($decoded) || ($decoded['success'] ?? false) !== true) {
            throw new RuntimeException('Cloudflare Registrar rejected the request.');
        }

        return $decoded;
    }
}
