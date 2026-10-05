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

    /** GET /zones documents per_page between 5 and 50. */
    private const ZONE_PAGE_SIZE = 50;

    private const RECORD_PAGE_SIZE = 500;

    /** Hard stop so a malformed result_info can never loop forever. */
    private const MAX_RECORD_PAGES = 200;

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
    ) {}

    public function findOrCreateZone(string $domain): array
    {
        $accountId = $this->accountId();
        $existing = $this->request('get', '/zones', [
            'name' => $domain,
            'account.id' => $accountId,
            'per_page' => self::ZONE_PAGE_SIZE,
        ]);
        $zones = is_array($existing['result'] ?? null) ? $existing['result'] : [];
        foreach ($zones as $zone) {
            if (is_array($zone) && strtolower((string) ($zone['name'] ?? '')) === $domain) {
                return $zone;
            }
        }

        return $this->result($this->request('post', '/zones', [], [
            'name' => $domain,
            'account' => ['id' => $accountId],
            'type' => 'full',
        ]));
    }

    public function listRecords(string $zoneReference): array
    {
        $path = '/zones/'.$this->reference($zoneReference).'/dns_records';
        $records = [];
        $page = 1;
        do {
            $response = $this->request('get', $path, [
                'page' => $page,
                'per_page' => self::RECORD_PAGE_SIZE,
            ]);
            $batch = is_array($response['result'] ?? null) ? $response['result'] : [];
            foreach ($batch as $record) {
                if (is_array($record)) {
                    $records[] = $record;
                }
            }
            $totalPages = (int) ($response['result_info']['total_pages'] ?? 1);
            $page++;
        } while ($batch !== [] && $page <= $totalPages && $page <= self::MAX_RECORD_PAGES);

        return $records;
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

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], array $payload = []): array
    {
        if (app()->environment('demo')) {
            throw new RuntimeException('Cloudflare DNS requests are disabled in the demo environment.');
        }

        $token = trim((string) $this->settings->get('cloudflare-domain', 'api_token', ''));
        if ($this->accountId() === '' || $token === '') {
            throw new RuntimeException('Cloudflare DNS is not configured.');
        }
        if (! preg_match('/\A[a-zA-Z0-9]{1,32}\z/', $this->accountId())) {
            throw new RuntimeException('Cloudflare DNS account ID is invalid.');
        }

        try {
            $request = Http::baseUrl(self::BASE_URL)
                ->withToken($token)
                ->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->timeout(15);
            $response = match ($method) {
                'get' => $request->get($path, $query),
                'post' => $request->post($path, $payload),
                'put' => $request->put($path, $payload),
                'delete' => $request->delete($path),
                default => throw new RuntimeException('Unsupported Cloudflare DNS request method.'),
            };
        } catch (Throwable $exception) {
            throw new RuntimeException('Cloudflare DNS request failed.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Cloudflare DNS returned an unsuccessful response.');
        }

        $decoded = $response->json();
        if (! is_array($decoded) || ($decoded['success'] ?? false) !== true) {
            throw new RuntimeException('Cloudflare DNS rejected the request.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
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
