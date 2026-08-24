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

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            throw ProxmoxProviderException::failed('proxmox::messages.health.invalid_url');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ProxmoxProviderException::failed('proxmox::messages.health.invalid_url');
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return rtrim($scheme.'://'.$host.$port, '/');
    }
}
