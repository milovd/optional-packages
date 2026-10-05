<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Contracts;

use Agovena\Modules\Domains\Models\DomainRegistration;

/**
 * Registrars whose registrations can finish asynchronously implement this
 * read-only status lookup. It must never submit or resubmit a billable request.
 */
interface RefreshesRegistrationStatus
{
    /** @return array{provider_reference: string|null, expires_at: string|null, status: string|null, meta: array<string, mixed>} */
    public function refreshRegistration(DomainRegistration $registration): array;
}
