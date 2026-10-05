<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Console;

use Agovena\Modules\Domains\DomainService;
use Agovena\Modules\Domains\Enums\DomainRegistrationStatus;
use Agovena\Modules\Domains\Models\DomainRegistration;
use Illuminate\Console\Command;
use Throwable;

final class RefreshDomainRegistrationsCommand extends Command
{
    protected $signature = 'agovena:refresh-domain-registrations';

    protected $description = 'Refresh domain registrations that are still being processed by their registrar.';

    public function handle(DomainService $domains): int
    {
        DomainRegistration::query()
            ->where('status', DomainRegistrationStatus::Registering)
            ->orderBy('id')
            ->each(function (DomainRegistration $registration) use ($domains): void {
                try {
                    $domains->refreshRegistration($registration);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        return self::SUCCESS;
    }
}
