<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Contracts;

use Agovena\Modules\Domains\Models\DomainRegistration;

interface DomainDnsProvider
{
    public function key(): string;

    /** @return list<string> */
    public function capabilities(): array;

    /** @return array{zone_reference: string|null, nameservers: list<string>, status: string|null, meta: array<string, mixed>} */
    public function ensureZone(DomainRegistration $registration): array;

    /** @return list<array<string, mixed>> */
    public function listRecords(DomainRegistration $registration): array;

    /** @param array<string, mixed> $record @return array<string, mixed> */
    public function upsertRecord(DomainRegistration $registration, array $record): array;

    /** @return array<string, mixed> */
    public function deleteRecord(DomainRegistration $registration, string $recordReference): array;
}
