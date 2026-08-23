<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Http\Livewire\Admin;

use Agovena\Modules\Subscriptions\Models\Subscription;
use App\Agovena\Admin\AdminRegistrar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

final class SubscriptionsIndex extends Component
{
    use AuthorizesRequests;

    public string $status = '';

    public function mount(): void
    {
        $this->authorize('subscriptions.view');
    }

    public function render(AdminRegistrar $admin)
    {
        $query = Subscription::query()->with('product')->orderByDesc('id');
        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return view('livewire.admin.subscriptions.index', [
            'subscriptions' => $query->limit(100)->get(),
        ])->layout('layouts.admin', [
            'title' => __('subscriptions::admin.title'),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
