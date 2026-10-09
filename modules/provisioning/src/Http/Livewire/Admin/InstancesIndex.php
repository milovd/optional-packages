<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Http\Livewire\Admin;

use Agovena\Modules\Provisioning\Models\ServiceInstance;
use App\Agovena\Admin\AdminRegistrar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

final class InstancesIndex extends Component
{
    use AuthorizesRequests;

    public string $status = '';

    public function mount(): void
    {
        $this->authorize('provisioning.view');
    }

    public function render(AdminRegistrar $admin)
    {
        $this->authorize('provisioning.view');

        $query = ServiceInstance::query()->with('product')->orderByDesc('id');
        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return view('provisioning::admin.index', [
            'instances' => $query->limit(100)->get(),
        ])->layout('layouts.admin', [
            'title' => __('provisioning::admin.title'),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
