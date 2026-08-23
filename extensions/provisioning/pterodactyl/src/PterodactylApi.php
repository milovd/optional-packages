<?php

declare(strict_types=1);

namespace Agovena\Extensions\Pterodactyl;

/**
 * Array-shaped Pterodactyl Application/Client API seam.
 * Official SDK types must not leave this Extension.
 */
interface PterodactylApi
{
    /** @param array<string, mixed> $settings */
    public function withConnection(array $settings): self;

    /**
     * @return array<string, mixed>
     */
    public function connectionTest(): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findServerByExternalId(string $externalId): ?array;

    /**
     * @return array<string, mixed>
     */
    public function getServer(int $serverId): array;

    /**
     * @return array<string, mixed>
     */
    public function getEgg(int $nestId, int $eggId): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createServer(array $payload): array;

    public function suspend(int $serverId): void;

    public function unsuspend(int $serverId): void;

    public function delete(int $serverId): void;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateBuild(int $serverId, array $payload): array;

    /**
     * @return array<string, mixed>
     */
    public function clientServer(string $identifier): array;

    public function power(string $identifier, string $signal): void;
}
