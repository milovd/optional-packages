<?php

declare(strict_types=1);

namespace Agovena\Extensions\Proxmox;

final class ProxmoxApiUrl
{
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw ProxmoxProviderException::failed('proxmox::messages.health.missing_url');
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ProxmoxProviderException::failed('proxmox::messages.health.invalid_url');
        }

        try {
            $parts = parse_url($url);
        } catch (ValueError) {
            throw ProxmoxProviderException::failed('proxmox::messages.health.invalid_url');
        }

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            throw ProxmoxProviderException::failed('proxmox::messages.health.invalid_url');
        }

        $host = (string) $parts['host'];
        if ($host === ''
            || (filter_var($host, FILTER_VALIDATE_IP) === false
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)
        ) {
            throw ProxmoxProviderException::failed('proxmox::messages.health.invalid_url');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $port = $parts['port'] ?? null;
        if ($port !== null && (! is_int($port) || $port < 1 || $port > 65535)) {
            throw ProxmoxProviderException::failed('proxmox::messages.health.invalid_url');
        }
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $authorityHost = str_contains($host, ':') ? '['.$host.']' : strtolower($host);

        return $scheme.'://'.$authorityHost.($port === null ? '' : ':'.$port);
    }
}
