<?php

declare(strict_types=1);

namespace Agovena\Extensions\Stripe;

use RuntimeException;

final class StripeProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $unknownOutcome = false,
    ) {
        parent::__construct($message);
    }

    public static function failed(string $translationKey): self
    {
        return new self(__($translationKey));
    }

    public static function unknown(string $translationKey): self
    {
        return new self(__($translationKey), true);
    }
}
