<?php

declare(strict_types=1);

namespace Agovena\Extensions\Convoy;

use Agovena\Modules\Provisioning\Support\ServerApi;

/**
 * Convoy v4.6.1 Application API seam (/api/application). Generic ServerApi methods map to:
 * connectionTest => GET /servers?per_page=1, createServer => POST /servers, getServer =>
 * GET /servers/{uuid}, suspend/unsuspend => POST /servers/{uuid}/settings/(un)suspend,
 * terminate => DELETE /servers/{uuid}.
 *
 * @phpstan-type Limits array{cpu: int, memory: int, disk: int, snapshots: int, backups: int, bandwidth: int|null}
 * @phpstan-type Server array{uuid: string, userId: int, hostname: string|null, status: string|null, limits: Limits, addressIds: list<int>}
 */
interface ConvoyApi extends ServerApi
{
    /** @param array<string, mixed> $settings */
    public function withConnection(array $settings): static;

    /** Creates a non-admin user and returns its id (POST /users). */
    public function createUser(string $name, string $email, string $password): int;

    /**
     * @param  array<string, mixed>  $payload
     * @return Server
     */
    public function createServer(array $payload): array;

    /** @return Server */
    public function getServer(string $externalId): array;

    /**
     * Replaces the resource limits and address ids of a server (PATCH /servers/{uuid}/settings/build).
     *
     * @param  Limits  $limits
     * @param  list<int>  $addressIds
     */
    public function updateBuild(string $uuid, array $limits, array $addressIds): void;
}
