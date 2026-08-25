<?php

declare(strict_types=1);

namespace Agovena\Extensions\Tebex;

final class TebexWebhookVerifier
{
    public static function verify(string $body, string $signature, string $secret): bool
    {
        if ($body === '' || $signature === '' || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', hash('sha256', $body), $secret);

        return hash_equals($expected, trim($signature));
    }
}
