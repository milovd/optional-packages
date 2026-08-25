<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

final class PaddleWebhookVerifier
{
    public static function verify(string $body, string $header, string $secret, int $toleranceSeconds = 5): bool
    {
        if ($body === '' || $header === '' || $secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(';', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if (is_string($key) && is_string($value) && $key !== '' && $value !== '') {
                $parts[$key][] = $value;
            }
        }

        $timestamp = isset($parts['ts'][0]) && ctype_digit($parts['ts'][0]) ? (int) $parts['ts'][0] : null;
        $signatures = $parts['h1'] ?? [];
        if ($timestamp === null || $signatures === []) {
            return false;
        }
        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.':'.$body, $secret);
        foreach ($signatures as $signature) {
            if (is_string($signature) && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
