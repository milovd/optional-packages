<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Listeners;

use Agovena\Modules\Provisioning\ProvisioningService;
use App\Events\OrderCreated;


final class SnapshotProvisioningConfigurationWhenOrderCreated
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
    ) {}

    public function handle(OrderCreated $event): void
    {
        $this->provisioning->snapshotOrderConfiguration($event->order, $event->preflight);
    }

}
