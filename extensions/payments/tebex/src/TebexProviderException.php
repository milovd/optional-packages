<?php

declare(strict_types=1);

namespace Agovena\Extensions\Tebex;

use RuntimeException;

final class TebexProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorKey,
        ?string $message = null,
        public readonly bool $unknownOutcome = false,
    )
    {
        parent::__construct($message ?? $errorKey);
    }

    public static function failed(string $errorKey): self
    {
        return new self($errorKey);
    }

    public static function unknown(string $errorKey): self
    {
        return new self($errorKey, unknownOutcome: true);
    }
}
