<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareDomain;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class HttpCloudflareDnsApi implements CloudflareDnsApi
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
    ) {}

    public function findOrCreateZone(string $domain): array
    {
        $accountId = $this->accountId();
        $existing = $this->request('get', '/zones', [
            'name' => $domain,
            'account.id' => $accountId,
            'per_page' => 1,
        ]);
        $zones = is_array($existing['result'] ?? null) ? $existing['result'] : [];
        $zone = is_array($zones[0] ?? null) ? $zones[0] : null;
        if ($zone !== null) {
            return $zone;
        }

        $created = $this->request('post', '/zones', [], [
            'name' => $domain,
            'account' => ['id' => $accountId],
            'jump_start' => false,
        ]);

        return is_array($created['result'] ?? null) ? $created['result'] : [];
    }

    public function listRecords(string $zoneReference): array
    {
        $response = $this->request('get', '/zones/'.$this->reference($zoneReference).'/dns_records', [
            'per_page' => 100,
        ]);
        $records = $response['result'] ?? [];

        return is_array($records) ? array_values(array_filter($records, 'is_array')) : [];
    }

    public function createRecord(string $zoneReference, array $record): array
    {
        return $this->result($this->request(
            'post',
            '/zones/'.$this->reference($zoneReference).'/dns_records',
            [],
            $record,
        ));
    }

    public function updateRecord(string $zoneReference, string $recordReference, array $record): array
    {
        return $this->result($this->request(
            'put',
            '/zones/'.$this->reference($zoneReference).'/dns_records/'.$this->reference($recordReference),
            [],
            $record,
        ));
    }

    public function deleteRecord(string $zoneReference, string $recordReference): array
    {
        return $this->result($this->request(
            'delete',
            '/zones/'.$this->reference($zoneReference).'/dns_records/'.$this->reference($recordReference),
        ));
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $method, string $path, array $query = [], array $payload = []): array
    {
        $token = trim((string) $this->settings->get('cloudflare-domain', 'api_token', ''));
        if ($this->accountId() === '' || $token === '') {
            throw new RuntimeException('Cloudflare DNS is not configured.');
        }

        try {
            $request = Http::baseUrl(self::BASE_URL)
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout(15);
            $response = match (strtolower($method)) {
                'get' => $request->get($path, $query),
                'post' => $request->post($path, $payload),
                'put' => $request->put($path, $payload),
                'delete' => $request->delete($path),
                default => throw new RuntimeException('Unsupported Cloudflare DNS request method.'),
            };

            if (! $response->successful()) {
                throw new RuntimeException('Cloudflare DNS returned an unsuccessful response.');
            }

            $decoded = $response->json();
            if (! is_array($decoded) || ($decoded['success'] ?? false) !== true) {
                throw new RuntimeException('Cloudflare DNS rejected the request.');
            }

            return $decoded;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && str_contains($exception->getMessage(), 'Cloudflare DNS')) {
                throw $exception;
            }

            throw new RuntimeException('Cloudflare DNS request failed.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    private function result(array $response): array
    {
        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    private function accountId(): string
    {
        return trim((string) $this->settings->get('cloudflare-domain', 'account_id', ''));
    }

    private function reference(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '' || ! preg_match('/\A[a-zA-Z0-9_-]{1,191}\z/', $reference)) {
            throw new RuntimeException('Invalid Cloudflare DNS reference.');
        }

        return $reference;
    }
}
