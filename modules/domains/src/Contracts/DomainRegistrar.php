<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Contracts;

use Agovena\Modules\Domains\Models\DomainRegistration;

interface DomainRegistrar
{
    public function key(): string;

    /** @return list<string> */
    public function capabilities(): array;

    /** @return array{available: bool, domain: string, price_minor: int|null, currency: string|null, reason: string|null} */
    public function checkAvailability(string $domain): array;

    /** @return array{provider_reference: string|null, expires_at: string|null, status: string|null, meta: array<string, mixed>} */
    public function register(DomainRegistration $registration): array;

    /** @return array{provider_reference: string|null, expires_at: string|null, status: string|null, meta: array<string, mixed>} */
    public function renew(DomainRegistration $registration, int $years = 1): array;
}
