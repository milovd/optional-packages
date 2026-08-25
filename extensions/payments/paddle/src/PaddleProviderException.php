<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

use RuntimeException;

final class PaddleProviderException extends RuntimeException
{
    public function __construct(public readonly string $errorKey, ?string $message = null)
    {
        parent::__construct($message ?? $errorKey);
    }

    public static function failed(string $errorKey): self
    {
        return new self($errorKey);
    }
}
