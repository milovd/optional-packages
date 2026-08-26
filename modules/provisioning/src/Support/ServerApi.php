<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Support;

interface ServerApi
{
    /** @param array<string, mixed> $settings */
    public function withConnection(array $settings): self;

    /** @return array<string, mixed> */
    public function connectionTest(): array;

    /** @return array<string, mixed>|null */
    public function findServerByExternalId(string $externalId): ?array;

    /** @return array<string, mixed> */
    public function getServer(string $externalId): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createServer(array $payload): array;

    public function suspend(string $externalId): void;

    public function unsuspend(string $externalId): void;

    public function terminate(string $externalId): void;

    /** @param array<string, mixed> $payload */
    public function changePlan(string $externalId, array $payload): void;

    public function action(string $externalId, string $action): void;
}
