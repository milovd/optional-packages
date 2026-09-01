<?php

declare(strict_types=1);

namespace Agovena\Extensions\PayPal;

use RuntimeException;

final class PayPalProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorKey = 'paypal::messages.errors.provider_failed',
        public readonly int $status = 0,
        public readonly bool $unknownOutcome = false,
    ) {
        parent::__construct($message);
    }

    public static function failed(string $errorKey, int $status = 0): self
    {
        return new self($errorKey, $errorKey, $status);
    }

    public static function unknown(string $errorKey, int $status = 0): self
    {
        return new self($errorKey, $errorKey, $status, true);
    }
}
