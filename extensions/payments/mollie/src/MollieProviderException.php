<?php

declare(strict_types=1);

namespace Agovena\Extensions\Mollie;

use RuntimeException;
use Throwable;

final class MollieProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $unknownOutcome = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function failed(string $translationKey): self
    {
        return new self(__($translationKey));
    }

    public static function unknown(string $translationKey): self
    {
        return new self(__($translationKey), unknownOutcome: true);
    }
}
