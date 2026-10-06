<?php

declare(strict_types=1);

namespace Agovena\Extensions\DirectAdmin;

/**
 * DirectAdmin usernames are 4 to 8 lowercase alphanumeric characters. The value is
 * generated once per service instance and stored in its claim row, so a retry
 * always reuses it.
 */
class DirectAdminUsernameGenerator
{
    private const LETTERS = 'abcdefghijklmnopqrstuvwxyz';

    private const ALPHANUMERIC = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public function generate(): string
    {
        $username = self::LETTERS[random_int(0, 25)];
        for ($i = 0; $i < 7; $i++) {
            $username .= self::ALPHANUMERIC[random_int(0, 35)];
        }

        return $username;
    }
}
