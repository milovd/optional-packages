<?php

declare(strict_types=1);

namespace Agovena\Extensions\Proxmox;

interface ProxmoxApi
{
    public function withConnection(array $settings): ProxmoxApi;

    public function connectionTest(): array;

    public function nextVmId(): int;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cloneVm(string $node, int $templateVmid, array $payload): string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateConfig(string $node, int $vmid, array $payload): void;

    public function start(string $node, int $vmid): void;

    public function stop(string $node, int $vmid): void;

    public function deleteVm(string $node, int $vmid): void;

    /**
     * @return array<string, mixed>
     */
    public function currentStatus(string $node, int $vmid): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findVmConfig(string $node, int $vmid): ?array;
}
