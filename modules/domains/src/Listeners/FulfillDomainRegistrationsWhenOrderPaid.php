<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Listeners;

use Agovena\Modules\Domains\DomainService;
use Agovena\Modules\Domains\Enums\DomainRegistrationStatus;
use Agovena\Modules\Domains\Models\DomainRegistration;
use App\Events\OrderPaid;
use Throwable;

final class FulfillDomainRegistrationsWhenOrderPaid
{
    public function __construct(
        private readonly DomainService $domains,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $registrations = DomainRegistration::query()
            ->where('order_id', $event->order->id)
            ->where('status', DomainRegistrationStatus::Pending)
            ->get();

        foreach ($registrations as $registration) {
            try {
                $registration = $this->domains->register($registration);
                if ($registration->status === DomainRegistrationStatus::Active && $registration->dns_provider_key !== null && $registration->dns_provider_key !== '') {
                    $this->domains->ensureDnsZone($registration);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
