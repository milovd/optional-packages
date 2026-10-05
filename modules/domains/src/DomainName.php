<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains;

final class DomainName
{
    public static function normalize(string $value): ?string
    {
        $value = trim(strtolower($value));
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '://')) {
            $parsed = parse_url($value);
            $value = is_array($parsed) && isset($parsed['host']) ? (string) $parsed['host'] : '';
        } elseif (str_contains($value, '/')) {
            $parsed = parse_url('https://'.$value);
            $value = is_array($parsed) && isset($parsed['host']) ? (string) $parsed['host'] : '';
        }

        $value = rtrim($value, '.');
        if (str_starts_with($value, 'www.')) {
            $value = substr($value, 4);
        }

        if ($value === '' || strlen($value) > 253 || ! str_contains($value, '.')) {
            return null;
        }

        foreach (explode('.', $value) as $label) {
            if ($label === '' || strlen($label) > 63 || ! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return null;
            }
        }

        return $value;
    }

    public static function tld(string $domain): ?string
    {
        $domain = self::normalize($domain);
        if ($domain === null) {
            return null;
        }

        $parts = explode('.', $domain);

        return end($parts) ?: null;
    }

    public static function baseLabel(string $domain): ?string
    {
        $domain = self::normalize($domain);
        if ($domain === null) {
            return null;
        }

        $parts = explode('.', $domain);
        array_pop($parts);

        return $parts !== [] ? implode('.', $parts) : null;
    }
}
