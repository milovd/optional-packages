<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Http\Livewire\Admin;

use Agovena\Modules\Domains\DomainDnsProviderRegistry;
use Agovena\Modules\Domains\DomainRegistrarRegistry;
use Agovena\Modules\Domains\DomainService;
use Agovena\Modules\Domains\Models\DomainRegistration;
use App\Agovena\Admin\AdminRegistrar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Throwable;

final class RegistrationsIndex extends Component
{
    use AuthorizesRequests;

    public string $status = '';

    public function mount(): void
    {
        $this->authorize('domains.view');
    }

    public function register(int $registrationId, DomainService $domains): void
    {
        $this->authorize('domains.manage');

        try {
            $domains->register(DomainRegistration::query()->findOrFail($registrationId));
            session()->flash('status', __('domains::admin.flash.registered'));
        } catch (Throwable) {
            session()->flash('error', __('domains::admin.flash.operation_failed'));
        }
    }

    public function renew(int $registrationId, DomainService $domains, int $years = 1): void
    {
        $this->authorize('domains.manage');

        try {
            $domains->renew(DomainRegistration::query()->findOrFail($registrationId), max(1, min(99, $years)));
            session()->flash('status', __('domains::admin.flash.renewed'));
        } catch (Throwable) {
            session()->flash('error', __('domains::admin.flash.operation_failed'));
        }
    }

    public function ensureDnsZone(int $registrationId, DomainService $domains): void
    {
        $this->authorize('domains.manage');

        try {
            $domains->ensureDnsZone(DomainRegistration::query()->findOrFail($registrationId));
            session()->flash('status', __('domains::admin.flash.dns_zone_ready'));
        } catch (Throwable) {
            session()->flash('error', __('domains::admin.flash.operation_failed'));
        }
    }

    public function render(
        AdminRegistrar $admin,
        DomainRegistrarRegistry $registrars,
        DomainDnsProviderRegistry $dnsProviders,
    ) {
        $query = DomainRegistration::query()
            ->with(['product', 'customer'])
            ->orderByDesc('id');
        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return view('livewire.admin.domains.index', [
            'registrations' => $query->limit(100)->get(),
            'registrars' => $registrars->all(),
            'dnsProviders' => $dnsProviders->all(),
            'canManage' => auth()->user()?->can('domains.manage') === true,
        ])->layout('layouts.admin', [
            'title' => __('domains::admin.title'),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
