<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareDomain;

use Agovena\Modules\Domains\Contracts\DomainDnsProvider;
use Agovena\Modules\Domains\Models\DomainRegistration;
use InvalidArgumentException;
use RuntimeException;

final class CloudflareDnsProvider implements DomainDnsProvider
{
    /**
     * Record types whose Cloudflare create/overwrite schema is fully expressed by
     * name/content/ttl (plus priority for MX). SRV, CAA and other data-object types
     * are refused rather than sent with an incomplete payload.
     */
    private const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT'];

    private const PROXIABLE_TYPES = ['A', 'AAAA', 'CNAME'];

    private const DOMAIN_PATTERN = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/';

    public function __construct(
        private readonly CloudflareDnsApi $api,
    ) {}

    public function key(): string
    {
        return 'cloudflare-dns';
    }

    /** Capability names match the ones DomainService and the customer DNS screen check. */
    public function capabilities(): array
    {
        return ['zone_management', 'records'];
    }

    public function ensureZone(DomainRegistration $registration): array
    {
        $domain = $this->domain($registration);
        $zone = $this->api->findOrCreateZone($domain);
        $reference = trim((string) ($zone['id'] ?? ''));
        if ($reference === '') {
            throw new RuntimeException('Cloudflare did not return a DNS zone reference.');
        }
        if (strtolower((string) ($zone['name'] ?? '')) !== $domain) {
            throw new RuntimeException('Cloudflare returned a DNS zone for a different domain.');
        }

        return [
            'zone_reference' => $reference,
            'nameservers' => is_array($zone['name_servers'] ?? null)
                ? array_values(array_map('strval', $zone['name_servers']))
                : [],
            'status' => isset($zone['status']) ? (string) $zone['status'] : null,
            'meta' => [
                'domain' => $domain,
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
        $payload = $this->validateRecord($record, $this->domain($registration));
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
        if ($domain === '' || ! preg_match(self::DOMAIN_PATTERN, $domain)) {
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

    /**
     * Cloudflare documents `name` as the complete record name including the zone name,
     * so relative names and `@` are expanded inside the registration's zone.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function validateRecord(array $record, string $domain): array
    {
        $type = strtoupper(trim((string) ($record['type'] ?? '')));
        $name = $this->completeName((string) ($record['name'] ?? ''), $domain);
        $content = trim((string) ($record['content'] ?? ''));
        $ttl = (int) ($record['ttl'] ?? 3600);

        if (! in_array($type, self::RECORD_TYPES, true)) {
            throw new InvalidArgumentException('This DNS record type is not supported.');
        }
        if ($content === '' || strlen($content) > 4096 || str_contains($content, "\r") || str_contains($content, "\n")) {
            throw new InvalidArgumentException('A valid DNS record value is required.');
        }
        if ($ttl !== 1 && ($ttl < 60 || $ttl > 86400)) {
            throw new InvalidArgumentException('DNS TTL must be 1 or between 60 and 86400 seconds.');
        }
        if ($type === 'A' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('An IPv4 address is required for an A record.');
        }
        if ($type === 'AAAA' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException('An IPv6 address is required for an AAAA record.');
        }

        $payload = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'proxied' => in_array($type, self::PROXIABLE_TYPES, true) && (bool) ($record['proxied'] ?? false),
        ];

        if ($type === 'MX') {
            $priority = filter_var($record['priority'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 65535],
            ]);
            if (! is_int($priority)) {
                throw new InvalidArgumentException('MX records require a priority between 0 and 65535.');
            }
            $payload['priority'] = $priority;
        }

        return $payload;
    }

    private function completeName(string $name, string $domain): string
    {
        $name = strtolower(rtrim(trim($name), '.'));
        if ($name === '' || $name === '@') {
            return $domain;
        }
        if ($name !== $domain && ! str_ends_with($name, '.'.$domain)) {
            $name .= '.'.$domain;
        }
        if (strlen($name) > 253 || ! preg_match('/\A(?:\*\.)?(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $name)) {
            throw new InvalidArgumentException('A valid DNS record name is required.');
        }

        return $name;
    }

    private function validateReference(string $reference): void
    {
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,191}\z/', $reference)) {
            throw new InvalidArgumentException('An invalid DNS record reference was supplied.');
        }
    }
}
