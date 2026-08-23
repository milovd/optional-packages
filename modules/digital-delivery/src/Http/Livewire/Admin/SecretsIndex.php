<?php

declare(strict_types=1);

namespace Agovena\Modules\DigitalDelivery\Http\Livewire\Admin;

use Agovena\Modules\DigitalDelivery\DigitalSecretFulfillmentService;
use Agovena\Modules\DigitalDelivery\Models\DigitalSecretDelivery;
use App\Agovena\Admin\AdminRegistrar;
use App\Models\Product;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class SecretsIndex extends Component
{
    use AuthorizesRequests;

    public ?int $product_id = null;

    public string $codes = '';

    public string $label = '';

    public ?int $assign_delivery_id = null;

    public string $assign_value = '';

    public function mount(): void
    {
        $this->authorize('digital_delivery.view');
    }

    public function addCodes(DigitalSecretFulfillmentService $secrets): void
    {
        $this->authorize('digital_delivery.manage');

        $data = $this->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'codes' => ['required', 'string', 'max:100000'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $product = Product::query()->with('capabilities')->findOrFail((int) $data['product_id']);
        if (! $product->hasCapability(DigitalSecretFulfillmentService::CAPABILITY)) {
            $this->addError('product_id', __('digital-delivery::errors.product_not_secret'));

            return;
        }

        $lines = preg_split('/\R/u', (string) $data['codes']) ?: [];
        $values = array_values(array_filter(
            array_map('strval', $lines),
            static fn (string $line): bool => trim($line) !== '',
        ));

        if ($values === []) {
            $this->addError('codes', __('digital-delivery::errors.no_codes'));

            return;
        }

        $result = $secrets->addPoolItems(
            $product,
            $values,
            $data['label'] !== '' ? $data['label'] : null,
        );

        $this->reset(['codes', 'label', 'product_id']);
        session()->flash('status', __('digital-delivery::admin.codes_added', [
            'added' => $result['added'],
            'skipped' => $result['skipped'],
        ]));
    }

    public function startAssign(int $deliveryId): void
    {
        $this->authorize('digital_delivery.manage');
        $this->assign_delivery_id = $deliveryId;
        $this->assign_value = '';
        $this->resetErrorBag('assign_value');
    }

    public function cancelAssign(): void
    {
        $this->assign_delivery_id = null;
        $this->assign_value = '';
    }

    public function assign(DigitalSecretFulfillmentService $secrets): void
    {
        $this->authorize('digital_delivery.manage');

        $data = $this->validate([
            'assign_delivery_id' => ['required', 'integer', 'exists:digital_secret_deliveries,id'],
            'assign_value' => ['required', 'string', 'max:4000'],
        ]);

        $delivery = DigitalSecretDelivery::query()->findOrFail((int) $data['assign_delivery_id']);
        $actorId = Auth::id();

        try {
            $secrets->assignManual(
                $delivery,
                (string) $data['assign_value'],
                is_numeric($actorId) ? (int) $actorId : null,
            );
        } catch (ValidationException $e) {
            // Surface the service message on the field the staff member is editing.
            $this->addError('assign_value', $e->errors()['value'][0] ?? __('digital-delivery::errors.value_required'));

            return;
        }

        $this->cancelAssign();
        session()->flash('status', __('digital-delivery::admin.assigned'));
    }

    public function revoke(int $deliveryId, DigitalSecretFulfillmentService $secrets): void
    {
        $this->authorize('digital_delivery.manage');
        $secrets->revoke(DigitalSecretDelivery::query()->findOrFail($deliveryId));
        session()->flash('status', __('digital-delivery::admin.revoked'));
    }

    public function render(AdminRegistrar $admin, DigitalSecretFulfillmentService $secrets)
    {
        $products = Product::query()
            ->whereHas('capabilities', static fn ($q) => $q->where('capability', DigitalSecretFulfillmentService::CAPABILITY))
            ->orderBy('name')
            ->get();

        $counts = [];
        foreach ($products as $product) {
            $counts[$product->id] = [
                'available' => $secrets->availableCount((int) $product->id),
                'allocated' => $secrets->allocatedCount((int) $product->id),
            ];
        }

        return view('livewire.admin.digital-delivery.secrets-index', [
            'products' => $products,
            'counts' => $counts,
            'deliveries' => DigitalSecretDelivery::query()
                ->with(['product', 'order'])
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ])->layout('layouts.admin', [
            'title' => __('digital-delivery::admin.title'),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
