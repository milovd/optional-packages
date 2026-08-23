<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Listeners;

use Agovena\Modules\Shipping\Enums\ShipmentStatus;
use Agovena\Modules\Shipping\Models\Shipment;
use Agovena\Modules\Shipping\ShipmentService;
use App\Events\OrderCancelled;

final class CancelShipmentsWhenOrderCancelled
{
    public function __construct(
        private readonly ShipmentService $shipments,
    ) {}

    public function handle(OrderCancelled $event): void
    {
        $pending = Shipment::query()
            ->where('order_id', $event->order->id)
            ->whereIn('status', [ShipmentStatus::Pending, ShipmentStatus::Processing])
            ->get();

        foreach ($pending as $shipment) {
            $this->shipments->cancel($shipment);
        }
    }
}
