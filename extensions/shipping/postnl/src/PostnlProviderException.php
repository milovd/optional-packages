<?php

declare(strict_types=1);

namespace Agovena\Extensions\Postnl;

use RuntimeException;

final class PostnlProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorKey = 'postnl::messages.errors.create_failed',
        public readonly int $status = 0,
    ) {
        parent::__construct($message);
    }

    public static function failed(string $errorKey, int $status = 0): self
    {
        return new self($errorKey, $errorKey, $status);
    }
}
