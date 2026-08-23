<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping;

use Agovena\Modules\Shipping\Enums\ShipmentStatus;
use Agovena\Modules\Shipping\Models\Shipment;
use Agovena\Modules\Shipping\Models\ShipmentItem;
use Agovena\Modules\Shipping\Models\ShippingMethod;
use App\Agovena\Notifications\SendsCataloguedMail;
use App\Agovena\Shipping\DispatchCarrierShipment;
use App\Agovena\Shipping\ShippingCarrierRegistry;
use App\Agovena\Shipping\SyncCarrierTracking;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

final class ShipmentService
{
    public function createPendingForOrder(Order $order, ?int $shippingMethodId): Shipment
    {
        $order->loadMissing('items');

        $shippableItems = [];
        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                continue;
            }
            $product = Product::query()->with('capabilities')->find($item->product_id);
            if ($product === null || ! $product->hasCapability('shippable')) {
                continue;
            }
            $shippableItems[] = $item;
        }

        if ($shippableItems === []) {
            throw ValidationException::withMessages([
                'shipping' => __('shipping::errors.no_shippable_items'),
            ]);
        }

        $method = null;
        if ($shippingMethodId !== null) {
            $method = ShippingMethod::query()->find($shippingMethodId);
        }

        return DB::transaction(function () use ($order, $shippableItems, $method): Shipment {
            $shipment = Shipment::query()->create([
                'order_id' => $order->id,
                'status' => ShipmentStatus::Pending,
                'shipping_method_id' => $method?->id,
                'shipping_method_label' => $order->shipping_method_label ?? $method?->name,
                'shipping_amount' => (int) ($order->shipping_amount ?? 0),
                'currency' => $order->currency,
            ]);

            foreach ($shippableItems as $item) {
                /** @var OrderItem $item */
                ShipmentItem::query()->create([
                    'shipment_id' => $shipment->id,
                    'order_item_id' => $item->id,
                    'quantity' => $item->quantity,
                ]);
            }

            return $shipment->load('items');
        });
    }

    public function dispatchCarrier(Shipment $shipment, string $carrierId, string $serviceCode = ''): Shipment
    {
        if ($shipment->external_ref !== null && $shipment->external_ref !== '') {
            return $shipment;
        }

        $order = $shipment->order()->firstOrFail();
        if ($order->status->value !== 'paid') {
            throw ValidationException::withMessages([
                'shipping' => __('shipping::errors.order_not_fulfillable'),
            ]);
        }

        $result = app(DispatchCarrierShipment::class)->handle($order, $carrierId, $serviceCode);

        $shipment->carrier_id = $carrierId;
        $shipment->external_ref = $result->externalId;
        $shipment->carrier_name = $this->carrierLabel($carrierId);
        $shipment->tracking_number = $result->trackingNumber;
        $shipment->tracking_url = $result->trackingUrl;
        $shipment->label_path = $result->labelPath;
        $shipment->save();

        if ($shipment->status === ShipmentStatus::Pending) {
            $this->markProcessing($shipment->fresh() ?? $shipment);
        }

        return $shipment->fresh() ?? $shipment;
    }

    public function syncTracking(Shipment $shipment): Shipment
    {
        if ($shipment->carrier_id === null || $shipment->external_ref === null || $shipment->external_ref === '') {
            return $shipment;
        }

        $remote = app(SyncCarrierTracking::class)->handle($shipment->carrier_id, $shipment->external_ref);
        if (($remote['tracking_number']) !== null) {
            $shipment->tracking_number = $remote['tracking_number'];
        }
        if (($remote['tracking_url']) !== null) {
            $shipment->tracking_url = $remote['tracking_url'];
        }
        $shipment->save();

        $status = $remote['status'];
        $fresh = $shipment->fresh() ?? $shipment;
        if ($status === 'shipped' && $fresh->status !== ShipmentStatus::Shipped && $fresh->status !== ShipmentStatus::Delivered) {
            return $this->markShipped($fresh, $fresh->carrier_name, $fresh->tracking_number, $fresh->tracking_url);
        }
        if ($status === 'delivered' && $fresh->status !== ShipmentStatus::Delivered) {
            if (in_array($fresh->status, [ShipmentStatus::Pending, ShipmentStatus::Processing], true)) {
                $fresh = $this->markShipped($fresh, $fresh->carrier_name, $fresh->tracking_number, $fresh->tracking_url);
            }

            return $this->markDelivered($fresh);
        }

        return $fresh;
    }

    public function markProcessing(Shipment $shipment): Shipment
    {
        return $this->transition($shipment, ShipmentStatus::Processing);
    }

    public function markShipped(
        Shipment $shipment,
        ?string $carrierName = null,
        ?string $trackingNumber = null,
        ?string $trackingUrl = null,
    ): Shipment {
        if ($carrierName !== null) {
            $shipment->carrier_name = $carrierName;
        }
        if ($trackingNumber !== null) {
            $shipment->tracking_number = $trackingNumber;
        }
        if ($trackingUrl !== null) {
            $shipment->tracking_url = $trackingUrl !== '' ? $trackingUrl : null;
        }
        $shipment->shipped_at ??= now();

        return $this->transition($shipment, ShipmentStatus::Shipped);
    }

    public function markDelivered(Shipment $shipment): Shipment
    {
        $shipment->delivered_at ??= now();

        return $this->transition($shipment, ShipmentStatus::Delivered);
    }

    public function cancel(Shipment $shipment): Shipment
    {
        if ($shipment->status === ShipmentStatus::Delivered) {
            throw ValidationException::withMessages([
                'status' => __('shipping::errors.cannot_cancel_delivered'),
            ]);
        }

        if ($shipment->carrier_id !== null && $shipment->external_ref !== null && $shipment->external_ref !== '') {
            try {
                app(DispatchCarrierShipment::class)->cancel($shipment->carrier_id, $shipment->external_ref);
            } catch (ValidationException $exception) {
                if ($shipment->status === ShipmentStatus::Shipped) {
                    throw $exception;
                }
            }
        }

        return $this->transition($shipment, ShipmentStatus::Cancelled);
    }

    private function carrierLabel(string $carrierId): string
    {
        $carrier = app(ShippingCarrierRegistry::class)->get($carrierId);
        if ($carrier === null) {
            return $carrierId;
        }
        $label = $carrier->label();

        return Lang::has($label) ? (string) __($label) : $label;
    }

    public function updateTracking(
        Shipment $shipment,
        ?string $carrierName,
        ?string $trackingNumber,
        ?string $trackingUrl,
    ): Shipment {
        $shipment->carrier_name = $carrierName;
        $shipment->tracking_number = $trackingNumber;
        $shipment->tracking_url = filled($trackingUrl) ? $trackingUrl : null;
        $shipment->save();

        return $shipment->fresh() ?? $shipment;
    }

    private function transition(Shipment $shipment, ShipmentStatus $next): Shipment
    {
        if (! $shipment->status->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => __('shipping::errors.invalid_transition', [
                    'from' => $shipment->status->value,
                    'to' => $next->value,
                ]),
            ]);
        }

        $shipment->status = $next;
        $shipment->save();

        $fresh = $shipment->fresh(['items']) ?? $shipment;
        if ($next === ShipmentStatus::Shipped) {
            $order = $fresh->order;
            app(SendsCataloguedMail::class)->toOrderCustomer(
                $order->customer_id,
                (string) $order->customer_email,
                'shipment_sent',
                [
                    'name' => (string) $order->customer_name,
                    'number' => $order->number,
                    'total' => '',
                    'action_url' => route('customer.orders.show', $order),
                    'action_label' => __('notifications.shipment_sent.action'),
                ],
            );
        }

        return $fresh;
    }
}
