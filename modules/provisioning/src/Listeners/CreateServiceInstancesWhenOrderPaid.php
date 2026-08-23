<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Listeners;

use Agovena\Modules\Provisioning\ProvisioningService;
use App\Events\OrderPaid;

final class CreateServiceInstancesWhenOrderPaid
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $this->provisioning->createFromPaidOrder($event->order);
    }
}
