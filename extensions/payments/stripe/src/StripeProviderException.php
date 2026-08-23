<?php

declare(strict_types=1);

namespace Agovena\Extensions\Stripe;

use RuntimeException;

final class StripeProviderException extends RuntimeException
{
    public static function failed(string $translationKey): self
    {
        return new self(__($translationKey));
    }
}
