<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Http\Livewire\Admin;

use Agovena\Modules\Shipping\Enums\ReturnRequestStatus;
use Agovena\Modules\Shipping\Models\ReturnRequest;
use App\Agovena\Admin\AdminRegistrar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

final class ReturnsIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $status = '';

    public function mount(): void
    {
        $this->authorize('returns.view');
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(AdminRegistrar $admin)
    {
        $query = ReturnRequest::query()
            ->with(['order', 'customer'])
            ->orderByDesc('id');

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return view('livewire.admin.shipping.returns-index', [
            'returns' => $query->paginate(20),
            'statuses' => ReturnRequestStatus::cases(),
        ])->layout('layouts.admin', [
            'title' => __('shipping::returns.admin_title'),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
