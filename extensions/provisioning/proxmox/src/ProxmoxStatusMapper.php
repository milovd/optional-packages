<?php

declare(strict_types=1);

namespace Agovena\Extensions\Proxmox;

final class ProxmoxStatusMapper
{
    /**
     * @param  array<string, mixed>  $status
     */
    public static function lifecycleStatus(array $status, bool $suspended = false): string
    {
        if ($suspended) {
            return 'suspended';
        }

        $value = strtolower((string) ($status['status'] ?? ''));

        return match ($value) {
            'running' => 'active',
            'stopped' => 'active',
            '' => 'provisioning',
            default => 'active',
        };
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public static function displayStatus(array $status): string
    {
        $value = strtolower((string) ($status['status'] ?? ''));

        return match ($value) {
            'running', 'stopped', 'paused' => $value,
            default => 'unknown',
        };
    }
}
