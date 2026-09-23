<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

use RuntimeException;

final class PaddleProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorKey,
        ?string $message = null,
        public readonly ?string $providerCode = null,
        public readonly ?string $providerDetail = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message ?? $errorKey);
    }

    public static function failed(
        string $errorKey,
        ?string $providerCode = null,
        ?string $providerDetail = null,
        ?int $httpStatus = null,
    ): self
    {
        return new self($errorKey, $errorKey, $providerCode, $providerDetail, $httpStatus);
    }

    public static function unknown(
        string $errorKey,
        ?string $providerCode = null,
        ?string $providerDetail = null,
        ?int $httpStatus = null,
    ): self
    {
        return new self($errorKey, $errorKey, $providerCode, $providerDetail, $httpStatus);
    }
}
