<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Providers;

use Agovena\Modules\Domains\Contracts\DomainDnsProvider;
use Agovena\Modules\Domains\DomainName;
use Agovena\Modules\Domains\Models\DomainRegistration;

final class DemoDnsProvider implements DomainDnsProvider
{
    public function key(): string
    {
        return 'demo-dns';
    }

    public function capabilities(): array
    {
        return ['zone_management', 'records'];
    }

    public function ensureZone(DomainRegistration $registration): array
    {
        $domain = DomainName::normalize((string) $registration->domain_name) ?? 'domain.invalid';

        return [
            'zone_reference' => 'demo-zone-'.substr(hash('sha256', $domain), 0, 16),
            'nameservers' => ['ns1.demo.agovena.invalid', 'ns2.demo.agovena.invalid'],
            'status' => 'active',
            'meta' => [
                'mode' => 'demo',
                'calls_enabled' => false,
            ],
        ];
    }

    public function listRecords(DomainRegistration $registration): array
    {
        $meta = is_array($registration->meta) ? $registration->meta : [];
        $records = $meta['dns_records'] ?? null;

        return is_array($records) && $records !== []
            ? array_values($records)
            : [
                [
                    'id' => 'demo-record-a',
                    'type' => 'A',
                    'name' => '@',
                    'content' => '192.0.2.10',
                    'ttl' => 3600,
                    'proxied' => false,
                ],
                [
                    'id' => 'demo-record-cname',
                    'type' => 'CNAME',
                    'name' => 'www',
                    'content' => 'demo.agovena.invalid',
                    'ttl' => 3600,
                    'proxied' => false,
                ],
            ];
    }

    public function upsertRecord(DomainRegistration $registration, array $record): array
    {
        $records = $this->listRecords($registration);
        $id = trim((string) ($record['id'] ?? ''));
        $id = $id !== '' ? $id : 'demo-record-'.substr(hash('sha256', json_encode($record)), 0, 12);
        $normalized = [
            'id' => $id,
            'type' => strtoupper(trim((string) ($record['type'] ?? 'A'))),
            'name' => trim((string) ($record['name'] ?? '@')),
            'content' => trim((string) ($record['content'] ?? '')),
            'ttl' => max(60, (int) ($record['ttl'] ?? 3600)),
            'proxied' => (bool) ($record['proxied'] ?? false),
        ];
        $found = false;
        foreach ($records as $index => $existing) {
            if ((string) ($existing['id'] ?? '') === $id) {
                $records[$index] = $normalized;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $records[] = $normalized;
        }

        $meta = is_array($registration->meta) ? $registration->meta : [];
        $meta['dns_records'] = array_values($records);
        $registration->forceFill(['meta' => $meta])->save();

        return $normalized;
    }

    public function deleteRecord(DomainRegistration $registration, string $recordReference): array
    {
        $records = array_values(array_filter(
            $this->listRecords($registration),
            static fn (array $record): bool => (string) ($record['id'] ?? '') !== $recordReference,
        ));
        $meta = is_array($registration->meta) ? $registration->meta : [];
        $meta['dns_records'] = $records;
        $registration->forceFill(['meta' => $meta])->save();

        return ['deleted' => true, 'id' => $recordReference];
    }
}
