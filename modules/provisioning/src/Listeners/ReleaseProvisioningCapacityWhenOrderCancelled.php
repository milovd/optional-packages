<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Listeners;

use Agovena\Modules\Provisioning\CapacityReservationService;
use App\Events\OrderCancelled;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

final class ReleaseProvisioningCapacityWhenOrderCancelled implements ShouldQueueAfterCommit
{
    public int $tries = 5;

    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly CapacityReservationService $reservations,
    ) {}

    public function handle(OrderCancelled $event): void
    {
        $this->reservations->releaseForOrder($event->order);
    }
}
