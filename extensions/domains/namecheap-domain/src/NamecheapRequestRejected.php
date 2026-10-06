<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapDomain;

use RuntimeException;

/**
 * Namecheap answered with ApiResponse Status="ERROR". The provider error text is never
 * kept because it can echo request values; only the documented error number is exposed.
 */
final class NamecheapRequestRejected extends RuntimeException
{
    public function __construct(
        public readonly ?string $errorNumber,
    ) {
        parent::__construct($errorNumber !== null
            ? 'Namecheap Registrar rejected the request (error '.$errorNumber.').'
            : 'Namecheap Registrar rejected the request.');
    }
}
