<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Support;

use RuntimeException;

final class ServerProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorKey,
        public readonly int $status = 0,
    ) {
        parent::__construct($errorKey, $status);
    }
}
