<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareDomain;

interface CloudflareDnsApi
{
    /** @return array<string, mixed> */
    public function findOrCreateZone(string $domain): array;

    /** @return list<array<string, mixed>> */
    public function listRecords(string $zoneReference): array;

    /** @param array<string, mixed> $record @return array<string, mixed> */
    public function createRecord(string $zoneReference, array $record): array;

    /** @param array<string, mixed> $record @return array<string, mixed> */
    public function updateRecord(string $zoneReference, string $recordReference, array $record): array;

    /** @return array<string, mixed> */
    public function deleteRecord(string $zoneReference, string $recordReference): array;
}
