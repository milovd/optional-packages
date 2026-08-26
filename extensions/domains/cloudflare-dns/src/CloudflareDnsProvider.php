<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareDns;

use Agovena\Modules\Domains\Contracts\DomainDnsProvider;
use Agovena\Modules\Domains\Models\DomainRegistration;
use InvalidArgumentException;
use RuntimeException;

final class CloudflareDnsProvider implements DomainDnsProvider
{
    private const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'SRV', 'TXT', 'CAA'];

    public function __construct(
        private readonly CloudflareDnsApi $api,
    ) {}

    public function key(): string
    {
        return 'cloudflare-dns';
    }

    public function capabilities(): array
    {
        return ['zone_management', 'record_management'];
    }

    public function ensureZone(DomainRegistration $registration): array
    {
        $domain = $this->domain($registration);
        $zone = $this->api->findOrCreateZone($domain);
        $reference = trim((string) ($zone['id'] ?? ''));
        if ($reference === '') {
            throw new RuntimeException('Cloudflare did not return a DNS zone reference.');
        }

        return [
            'zone_reference' => $reference,
            'nameservers' => is_array($zone['name_servers'] ?? null)
                ? array_values(array_map('strval', $zone['name_servers']))
                : [],
            'status' => isset($zone['status']) ? (string) $zone['status'] : null,
            'meta' => [
                'domain' => (string) ($zone['name'] ?? $domain),
            ],
        ];
    }

    public function listRecords(DomainRegistration $registration): array
    {
        return $this->api->listRecords($this->zoneReference($registration));
    }

    public function upsertRecord(DomainRegistration $registration, array $record): array
    {
        $zoneReference = $this->zoneReference($registration);
        $payload = $this->validateRecord($record);
        $recordReference = isset($record['id']) ? trim((string) $record['id']) : '';

        if ($recordReference !== '') {
            $this->validateReference($recordReference);

            return $this->api->updateRecord($zoneReference, $recordReference, $payload);
        }

        return $this->api->createRecord($zoneReference, $payload);
    }

    public function deleteRecord(DomainRegistration $registration, string $recordReference): array
    {
        $recordReference = trim($recordReference);
        $this->validateReference($recordReference);

        return $this->api->deleteRecord($this->zoneReference($registration), $recordReference);
    }

    private function domain(DomainRegistration $registration): string
    {
        $domain = strtolower(rtrim(trim((string) $registration->domain_name), '.'));
        if ($domain === '' || ! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/', $domain)) {
            throw new InvalidArgumentException('A fully qualified ASCII domain is required.');
        }

        return $domain;
    }

    private function zoneReference(DomainRegistration $registration): string
    {
        $meta = is_array($registration->meta) ? $registration->meta : [];
        $zone = is_array($meta['dns_zone'] ?? null) ? $meta['dns_zone'] : [];
        $reference = trim((string) ($zone['zone_reference'] ?? ''));
        if ($reference === '') {
            throw new RuntimeException('The DNS zone must be prepared before records can be changed.');
        }

        return $reference;
    }

    /** @param array<string, mixed> $record @return array{type: string, name: string, content: string, ttl: int, proxied: bool} */
    private function validateRecord(array $record): array
    {
        $type = strtoupper(trim((string) ($record['type'] ?? '')));
        $name = strtolower(rtrim(trim((string) ($record['name'] ?? '')), '.'));
        $content = trim((string) ($record['content'] ?? ''));
        $ttl = (int) ($record['ttl'] ?? 3600);

        if (! in_array($type, self::RECORD_TYPES, true)) {
            throw new InvalidArgumentException('This DNS record type is not supported.');
        }
        if ($name === '' || strlen($name) > 253 || str_contains($name, "\r") || str_contains($name, "\n")) {
            throw new InvalidArgumentException('A valid DNS record name is required.');
        }
        if ($content === '' || strlen($content) > 4096 || str_contains($content, "\r") || str_contains($content, "\n")) {
            throw new InvalidArgumentException('A valid DNS record value is required.');
        }
        if ($ttl !== 1 && ($ttl < 60 || $ttl > 86400)) {
            throw new InvalidArgumentException('DNS TTL must be 1 or between 60 and 86400 seconds.');
        }
        if (in_array($type, ['A', 'AAAA'], true) && filter_var($content, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('An IP address is required for this DNS record type.');
        }

        return [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'proxied' => (bool) ($record['proxied'] ?? false),
        ];
    }

    private function validateReference(string $reference): void
    {
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,191}\z/', $reference)) {
            throw new InvalidArgumentException('An invalid DNS record reference was supplied.');
        }
    }
}
