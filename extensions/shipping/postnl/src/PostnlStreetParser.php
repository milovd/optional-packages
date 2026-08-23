<?php

declare(strict_types=1);

namespace Agovena\Extensions\Postnl;

final class PostnlStreetParser
{
    /**
     * @return array{street: string, house: string, addition: string}
     */
    public static function parse(string $line1): array
    {
        $line1 = trim($line1);
        if ($line1 === '') {
            throw PostnlProviderException::failed('postnl::messages.errors.invalid_address');
        }

        if (preg_match('/^(.*?)\\s+(\\d+)\\s*([a-zA-Z0-9\\-\\s]*)$/u', $line1, $matches) !== 1) {
            throw PostnlProviderException::failed('postnl::messages.errors.invalid_address');
        }

        return [
            'street' => trim($matches[1]),
            'house' => $matches[2],
            'addition' => trim($matches[3]),
        ];
    }
}
