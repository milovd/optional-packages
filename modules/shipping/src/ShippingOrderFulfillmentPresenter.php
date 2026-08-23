<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping;

use Agovena\Modules\Shipping\Models\Shipment;
use App\Agovena\Fulfillment\FulfillmentShipmentView;
use App\Agovena\Fulfillment\OrderFulfillmentPresenter;
use App\Models\Order;

final class ShippingOrderFulfillmentPresenter implements OrderFulfillmentPresenter
{
    public function forOrder(Order $order): array
    {
        $shipments = Shipment::query()
            ->with(['items.orderItem'])
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        $views = [];
        foreach ($shipments as $shipment) {
            $items = [];
            foreach ($shipment->items as $row) {
                $orderItem = $row->orderItem;
                $items[] = [
                    'label' => $orderItem !== null
                        ? (string) $orderItem->label
                        : (string) __('shipping::admin.unknown_item'),
                    'quantity' => $row->quantity,
                ];
            }

            $views[] = new FulfillmentShipmentView(
                status: $shipment->status->value,
                statusLabel: __('shipping::status.'.$shipment->status->value),
                carrierName: $shipment->carrier_name,
                trackingNumber: $shipment->tracking_number,
                trackingUrl: $shipment->tracking_url,
                shippedAt: $shipment->shipped_at?->toDateTimeString(),
                deliveredAt: $shipment->delivered_at?->toDateTimeString(),
                items: $items,
                shippingMethod: $shipment->shipping_method_label,
            );
        }

        return $views;
    }
}
