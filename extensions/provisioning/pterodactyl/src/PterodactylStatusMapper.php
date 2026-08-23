<?php

declare(strict_types=1);

namespace Agovena\Extensions\Pterodactyl;

final class PterodactylStatusMapper
{
    /**
     * @param  array<string, mixed>  $server
     */
    public static function lifecycleStatus(array $server): string
    {
        $suspended = (bool) ($server['suspended'] ?? false);
        $status = strtolower((string) ($server['status'] ?? ''));

        if ($suspended || $status === 'suspended') {
            return 'suspended';
        }

        return match ($status) {
            'installing', 'restoring_backup' => 'provisioning',
            'install_failed' => 'failed',
            default => 'active',
        };
    }

    /**
     * @param  array<string, mixed>  $server
     */
    public static function displayStatus(array $server): string
    {
        $lifecycle = self::lifecycleStatus($server);
        if ($lifecycle !== 'active') {
            return $lifecycle === 'provisioning' ? 'installing' : $lifecycle;
        }

        $current = strtolower((string) ($server['current_state'] ?? $server['currentState'] ?? ''));

        return match ($current) {
            'running', 'offline', 'starting', 'stopping' => $current,
            default => 'unknown',
        };
    }
}
