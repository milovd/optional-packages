<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtfusion;

use Agovena\Modules\Provisioning\Support\ServerApi;

/**
 * VirtFusion API v1 seam. Generic ServerApi methods map to documented endpoints:
 * connectionTest => GET /connect, createServer => POST /servers, getServer =>
 * GET /servers/{id}, suspend/unsuspend => POST /servers/{id}/(un)suspend,
 * terminate => DELETE /servers/{id}.
 */
interface VirtfusionApi extends ServerApi
{
    /** @param array<string, mixed> $settings */
    public function withConnection(array $settings): static;

    /** @return list<int> Ids of the enabled packages (GET /packages). */
    public function enabledPackageIds(): array;

    /** Creates a user linked by the relation string and returns its id (POST /users). */
    public function createUser(string $name, string $email, string $relation): int;

    /** Returns the id of the user holding the relation string (GET /users/{relStr}/byExtRelation). */
    public function userIdByRelation(string $relation): int;

    /** Queues the operating system install (POST /servers/{id}/build). */
    public function buildServer(string $serverId, int $templateId): void;

    /** Moves the server to another package (PUT /servers/{id}/package/{packageId}). */
    public function changePackage(string $serverId, int $packageId): void;
}
