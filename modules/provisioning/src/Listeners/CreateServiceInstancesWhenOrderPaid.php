<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Listeners;

use Agovena\Modules\Provisioning\ProvisioningService;
use Agovena\Modules\Provisioning\Models\CapacityReservation;
use App\Events\OrderPaid;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

final class CreateServiceInstancesWhenOrderPaid implements ShouldQueueAfterCommit
{
    public int $tries = 5;

    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly ProvisioningService $provisioning,
    ) {}

    public function handle(OrderPaid $event): void
    {
        CapacityReservation::query()
            ->where('order_id', $event->order->id)
            ->update(['expires_at' => null]);

        $this->provisioning->createFromPaidOrder($event->order);
    }
}
