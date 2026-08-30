<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Listeners;

use Agovena\Modules\Provisioning\ProvisioningService;
use App\Events\OrderPlacing;

final class SnapshotProvisioningConfigurationWhenOrderPlacing
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
    ) {}

    public function handle(OrderPlacing $event): void
    {
        if ($event->order !== null) {
            $this->provisioning->snapshotOrderConfiguration($event->order, $event->preflight);
        }
    }
}
