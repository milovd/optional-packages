<?php

declare(strict_types=1);

namespace Agovena\Modules\Inventory\Listeners;

use Agovena\Modules\Inventory\InventoryService;
use App\Events\OrderCancelled;

final class RestockWhenOrderCancelled
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function handle(OrderCancelled $event): void
    {
        $this->inventory->releaseForOrder($event->order);
    }
}
