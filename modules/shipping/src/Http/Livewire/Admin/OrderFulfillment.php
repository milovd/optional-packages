<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Http\Livewire\Admin;

use Agovena\Modules\Shipping\Enums\ShipmentStatus;
use Agovena\Modules\Shipping\Models\Shipment;
use Agovena\Modules\Shipping\ShipmentService;
use App\Agovena\Shipping\Contracts\CreatesCarrierShipments;
use App\Agovena\Shipping\ShippingCarrierRegistry;
use App\Models\Order;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrderFulfillment extends Component
{
    use AuthorizesRequests;

    public Order $order;

    public string $carrier_name = '';

    public string $tracking_number = '';

    public string $tracking_url = '';

    public string $carrier_id = '';

    public function mount(Order $order): void
    {
        $this->authorize('shipping.view');
        $this->order = $order;
    }

    public function markProcessing(int $shipmentId, ShipmentService $shipments): void
    {
        $this->authorize('shipping.manage');
        $shipments->markProcessing($this->findShipment($shipmentId));
        session()->flash('status', __('shipping::admin.saved'));
    }

    public function markShipped(int $shipmentId, ShipmentService $shipments): void
    {
        $this->authorize('shipping.manage');
        try {
            $shipments->markShipped(
                $this->findShipment($shipmentId),
                filled($this->carrier_name) ? $this->carrier_name : null,
                filled($this->tracking_number) ? $this->tracking_number : null,
                filled($this->tracking_url) ? $this->tracking_url : null,
            );
            session()->flash('status', __('shipping::admin.saved'));
        } catch (ValidationException $e) {
            session()->flash('error', $e->errors()['status'][0] ?? $e->getMessage());
        }
    }

    public function markDelivered(int $shipmentId, ShipmentService $shipments): void
    {
        $this->authorize('shipping.manage');
        try {
            $shipments->markDelivered($this->findShipment($shipmentId));
            session()->flash('status', __('shipping::admin.saved'));
        } catch (ValidationException $e) {
            session()->flash('error', $e->errors()['status'][0] ?? $e->getMessage());
        }
    }

    public function cancelShipment(int $shipmentId, ShipmentService $shipments): void
    {
        $this->authorize('shipping.manage');
        try {
            $shipments->cancel($this->findShipment($shipmentId));
            session()->flash('status', __('shipping::admin.saved'));
        } catch (ValidationException $e) {
            session()->flash('error', $e->errors()['status'][0] ?? $e->getMessage());
        }
    }

    public function saveTracking(int $shipmentId, ShipmentService $shipments): void
    {
        $this->authorize('shipping.manage');
        $shipments->updateTracking(
            $this->findShipment($shipmentId),
            filled($this->carrier_name) ? $this->carrier_name : null,
            filled($this->tracking_number) ? $this->tracking_number : null,
            filled($this->tracking_url) ? $this->tracking_url : null,
        );
        session()->flash('status', __('shipping::admin.saved'));
    }

    public function createCarrierShipment(int $shipmentId, ShipmentService $shipments): void
    {
        $this->authorize('shipping.manage');
        try {
            $carrierId = $this->carrier_id !== '' ? $this->carrier_id : (string) ($this->availableCarriers()[0]['id'] ?? '');
            $shipments->dispatchCarrier($this->findShipment($shipmentId), $carrierId);
            session()->flash('status', __('shipping::admin.saved'));
        } catch (ValidationException $e) {
            session()->flash('error', collect($e->errors())->flatten()->first() ?? $e->getMessage());
        }
    }

    public function syncCarrierTracking(int $shipmentId, ShipmentService $shipments): void
    {
        $this->authorize('shipping.manage');
        try {
            $shipments->syncTracking($this->findShipment($shipmentId));
            session()->flash('status', __('shipping::admin.saved'));
        } catch (ValidationException $e) {
            session()->flash('error', collect($e->errors())->flatten()->first() ?? $e->getMessage());
        }
    }

    public function downloadLabel(int $shipmentId): ?StreamedResponse
    {
        $this->authorize('shipping.manage');
        $shipment = $this->findShipment($shipmentId);
        $path = $shipment->label_path;
        if ($path === null || $path === '' || ! Storage::disk('local')->exists($path)) {
            session()->flash('error', __('shipping::admin.no_shipments'));

            return null;
        }

        return Storage::disk('local')->download($path, 'shipment-'.$shipment->id.'.pdf', [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function render()
    {
        $shipments = Shipment::query()
            ->with(['items.orderItem'])
            ->where('order_id', $this->order->id)
            ->orderBy('id')
            ->get();

        if ($shipments->isNotEmpty() && $this->carrier_name === '' && $this->tracking_number === '') {
            /** @var Shipment $first */
            $first = $shipments->first();
            $this->carrier_name = (string) ($first->carrier_name ?? '');
            $this->tracking_number = (string) ($first->tracking_number ?? '');
            $this->tracking_url = (string) ($first->tracking_url ?? '');
        }

        $carriers = $this->availableCarriers();
        if ($this->carrier_id === '' && $carriers !== []) {
            $this->carrier_id = $carriers[0]['id'];
        }

        return view('livewire.admin.shipping.order-fulfillment', [
            'shipments' => $shipments,
            'statuses' => ShipmentStatus::cases(),
            'carriers' => $carriers,
        ]);
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function availableCarriers(): array
    {
        $out = [];
        foreach (app(ShippingCarrierRegistry::class)->all() as $carrier) {
            if (! $carrier instanceof CreatesCarrierShipments) {
                continue;
            }
            $label = $carrier->label();
            $out[] = [
                'id' => $carrier->id(),
                'label' => Lang::has($label) ? (string) __($label) : $label,
            ];
        }

        return $out;
    }

    private function findShipment(int $id): Shipment
    {
        return Shipment::query()
            ->where('order_id', $this->order->id)
            ->whereKey($id)
            ->firstOrFail();
    }
}
