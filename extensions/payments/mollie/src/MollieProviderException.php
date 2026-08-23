<?php

declare(strict_types=1);

namespace Agovena\Extensions\Mollie;

use RuntimeException;

final class MollieProviderException extends RuntimeException
{
    public static function failed(string $translationKey): self
    {
        return new self(__($translationKey));
    }
}
