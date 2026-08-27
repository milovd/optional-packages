<?php

declare(strict_types=1);

namespace Agovena\Extensions\DomainDns;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class HttpCloudflareApi implements CloudflareApi
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
    ) {}

    public function check(array $domains): array
    {
        return $this->post('/registrar/domain-check', ['domains' => array_values($domains)]);
    }

    public function register(string $domain, array $payload = []): array
    {
        return $this->post('/registrar/registrations', array_merge(
            ['domain_name' => $domain],
            $payload,
        ));
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $payload): array
    {
        $accountId = trim((string) $this->settings->get('domain-dns', 'cloudflare_account_id', ''));
        $apiToken = trim((string) $this->settings->get('domain-dns', 'cloudflare_api_token', ''));
        if ($accountId === '' || $apiToken === '') {
            throw new RuntimeException('Cloudflare Registrar is not configured.');
        }

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->withToken($apiToken)
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post('/accounts/'.$accountId.$path, $payload);

            if (! $response->successful()) {
                throw new RuntimeException('Cloudflare Registrar returned an unsuccessful response.');
            }

            $decoded = $response->json();
            if (! is_array($decoded) || ($decoded['success'] ?? false) !== true) {
                throw new RuntimeException('Cloudflare Registrar rejected the request.');
            }

            return $decoded;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && $exception->getMessage() !== 'Cloudflare Registrar request failed.') {
                throw $exception;
            }

            throw new RuntimeException('Cloudflare Registrar request failed.', previous: $exception);
        }
    }
}
