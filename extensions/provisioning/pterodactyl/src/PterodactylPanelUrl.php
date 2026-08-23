<?php

declare(strict_types=1);

namespace Agovena\Extensions\Pterodactyl;

final class PterodactylPanelUrl
{
    public static function normalize(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            throw PterodactylProviderException::failed('pterodactyl::messages.health.missing_url');
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw PterodactylProviderException::failed('pterodactyl::messages.health.invalid_url');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw PterodactylProviderException::failed('pterodactyl::messages.health.invalid_url');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw PterodactylProviderException::failed('pterodactyl::messages.health.invalid_url');
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '' || str_contains($host, '\\')) {
            throw PterodactylProviderException::failed('pterodactyl::messages.health.invalid_url');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}
