<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Http\Livewire\Admin;

use Agovena\Modules\Shipping\Enums\ShippingMethodType;
use Agovena\Modules\Shipping\Models\ShippingMethod;
use Agovena\Modules\Shipping\Models\ShippingZone;
use App\Agovena\Admin\AdminRegistrar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

final class MethodsIndex extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $code = '';

    public string $type = 'flat';

    public ?int $zone_id = null;

    public string $currency = 'EUR';

    public string $amount = '0';

    public string $min_subtotal = '';

    public string $tiers_json = '';

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('shipping.view');
    }

    public function save(): void
    {
        $this->authorize('shipping.manage');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('shipping_methods', 'code')],
            'type' => ['required', Rule::enum(ShippingMethodType::class)],
            'zone_id' => ['nullable', 'integer', 'exists:shipping_zones,id'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'min_subtotal' => ['nullable', 'integer', 'min:0'],
            'tiers_json' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $type = ShippingMethodType::from($data['type']);
        $config = [];
        if ($data['min_subtotal'] !== '' && $data['min_subtotal'] !== null) {
            $config['min_subtotal'] = (int) $data['min_subtotal'];
        }

        if (in_array($type, [ShippingMethodType::Flat, ShippingMethodType::Zone, ShippingMethodType::Free], true)) {
            $config['amount'] = (int) ($data['amount'] ?? 0);
        }

        if (in_array($type, [ShippingMethodType::Price, ShippingMethodType::Weight], true)) {
            $tiers = json_decode((string) $data['tiers_json'], true);
            $config['tiers'] = is_array($tiers) ? $tiers : [];
        }

        ShippingMethod::query()->create([
            'name' => $data['name'],
            'code' => $data['code'],
            'type' => $type,
            'zone_id' => $data['zone_id'] ?: null,
            'config' => $config,
            'currency' => strtoupper($data['currency']),
            'is_active' => (bool) $data['is_active'],
            'sort' => (int) ShippingMethod::query()->max('sort') + 10,
        ]);

        $this->reset(['name', 'code', 'amount', 'min_subtotal', 'tiers_json']);
        $this->type = 'flat';
        $this->is_active = true;
        session()->flash('status', __('shipping::admin.saved'));
    }

    public function delete(int $id): void
    {
        $this->authorize('shipping.manage');
        ShippingMethod::query()->whereKey($id)->delete();
        session()->flash('status', __('shipping::admin.deleted'));
    }

    public function render(AdminRegistrar $admin)
    {
        return view('livewire.admin.shipping.methods-index', [
            'methods' => ShippingMethod::query()->with('zone')->orderBy('sort')->orderBy('id')->get(),
            'zones' => ShippingZone::query()->orderBy('sort')->orderBy('name')->get(),
            'types' => ShippingMethodType::cases(),
        ])->layout('layouts.admin', [
            'title' => __('shipping::admin.methods_title'),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
