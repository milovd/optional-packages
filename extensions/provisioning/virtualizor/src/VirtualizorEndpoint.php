<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtualizor;

use Agovena\Modules\Provisioning\Support\ServerProviderException;
use ValueError;

/**
 * Credential-independent connection identity: lowercase scheme://host:port.
 */
final class VirtualizorEndpoint
{
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new ServerProviderException('errors.not_configured');
        }

        try {
            $parts = parse_url($url);
        } catch (ValueError) {
            $parts = false;
        }

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === ''
            || (filter_var($host, FILTER_VALIDATE_IP) === false
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)
        ) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        $authority = str_contains($host, ':') ? '['.$host.']' : $host;

        return $scheme.'://'.$authority.':'.$port;
    }
}
