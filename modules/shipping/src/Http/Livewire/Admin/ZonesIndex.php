<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Http\Livewire\Admin;

use Agovena\Modules\Shipping\Models\ShippingZone;
use App\Agovena\Admin\AdminRegistrar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

final class ZonesIndex extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $countries = 'NL';

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
            'countries' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $codes = array_values(array_filter(array_map(
            static fn (string $c): string => strtoupper(trim($c)),
            explode(',', $data['countries']),
        )));

        ShippingZone::query()->create([
            'name' => $data['name'],
            'countries' => $codes,
            'is_active' => (bool) $data['is_active'],
            'sort' => (int) ShippingZone::query()->max('sort') + 10,
        ]);

        $this->reset(['name']);
        $this->countries = 'NL';
        $this->is_active = true;
        session()->flash('status', __('shipping::admin.saved'));
    }

    public function delete(int $id): void
    {
        $this->authorize('shipping.manage');
        ShippingZone::query()->whereKey($id)->delete();
        session()->flash('status', __('shipping::admin.deleted'));
    }

    public function render(AdminRegistrar $admin)
    {
        return view('livewire.admin.shipping.zones-index', [
            'zones' => ShippingZone::query()->orderBy('sort')->orderBy('name')->get(),
        ])->layout('layouts.admin', [
            'title' => __('shipping::admin.zones_title'),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
