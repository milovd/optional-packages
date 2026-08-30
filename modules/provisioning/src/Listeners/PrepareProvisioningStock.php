<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Listeners;

use App\Events\OrderPreflight;

final class PrepareProvisioningStock
{
    public function __construct(
        private readonly AssertProvisioningStockBeforeOrderPlacing $stock,
    ) {}

    public function handle(OrderPreflight $event): void
    {
        $this->stock->preflight($event);
    }
}
