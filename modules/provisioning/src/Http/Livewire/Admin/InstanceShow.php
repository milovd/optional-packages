<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Http\Livewire\Admin;

use Agovena\Modules\Provisioning\Models\ServiceInstance;
use Agovena\Modules\Provisioning\ProvisioningOrchestrator;
use Agovena\Modules\Provisioning\ProvisioningService;
use App\Agovena\Admin\AdminRegistrar;
use App\Agovena\Provisioning\Contracts\ProvisionerLifecycle;
use App\Agovena\Provisioning\ProvisionerRegistry;
use App\Livewire\Concerns\RequiresRecentPassword;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class InstanceShow extends Component
{
    use AuthorizesRequests;
    use RequiresRecentPassword;

    public ServiceInstance $instance;

    public string $provider_key = '';

    public string $external_ref = '';

    public function mount(ServiceInstance $instance): void
    {
        $this->authorize('provisioning.view');
        $this->instance = $instance->load(['product', 'order']);
        $this->provider_key = (string) $instance->provider_key;
        $this->external_ref = (string) $instance->external_ref;
    }

    public function saveTracking(ProvisioningService $provisioning): void
    {
        $this->authorize('provisioning.manage');
        $this->instance = $provisioning->updateTracking(
            $this->instance,
            $this->provider_key,
            $this->external_ref,
        );
        session()->flash('status', __('provisioning::admin.tracking_saved'));
    }

    public function markProvisioning(ProvisioningService $provisioning): void
    {
        $this->authorize('provisioning.manage');
        $this->instance = $provisioning->markProvisioning($this->instance);
        session()->flash('status', __('provisioning::admin.marked_provisioning'));
    }

    public function markManualReview(ProvisioningService $provisioning): void
    {
        $this->authorize('provisioning.manage');
        $this->instance = $provisioning->markManualReview(
            $this->instance,
            $this->instance->failure_message ?? __('provisioning::admin.manual_review_default'),
        );
        session()->flash('status', __('provisioning::admin.manual_reviewed'));
    }

    public function retryProvisioning(ProvisioningOrchestrator $orchestrator): void
    {
        $this->authorize('provisioning.manage');
        $this->instance = $orchestrator->provision($this->instance);
        session()->flash('status', __('provisioning::admin.provisioned'));
    }

    public function syncStatus(ProvisioningOrchestrator $orchestrator): void
    {
        $this->authorize('provisioning.manage');
        try {
            $this->instance = $orchestrator->sync($this->instance);
            session()->flash('status', __('provisioning::admin.synced'));
        } catch (ValidationException $exception) {
            session()->flash('error', $exception->errors()['instance'][0] ?? $exception->getMessage());
        }
    }

    public function activate(ProvisioningService $provisioning): void
    {
        $this->authorize('provisioning.manage');
        $this->instance = $provisioning->activate(
            $this->instance,
            $this->external_ref !== '' ? $this->external_ref : null,
        );
        session()->flash('status', __('provisioning::admin.activated'));
    }

    public function suspend(ProvisioningOrchestrator $orchestrator, ProvisioningService $provisioning): void
    {
        $this->authorize('provisioning.manage');
        try {
            $this->instance = $this->usesLifecycle()
                ? $orchestrator->suspend($this->instance)
                : $provisioning->suspend($this->instance);
            session()->flash('status', __('provisioning::admin.suspended'));
        } catch (ValidationException $exception) {
            session()->flash('error', $exception->errors()['instance'][0] ?? $exception->getMessage());
        }
    }

    public function unsuspend(ProvisioningOrchestrator $orchestrator, ProvisioningService $provisioning): void
    {
        $this->authorize('provisioning.manage');
        try {
            $this->instance = $this->usesLifecycle()
                ? $orchestrator->unsuspend($this->instance)
                : $provisioning->unsuspend($this->instance);
            session()->flash('status', __('provisioning::admin.unsuspended'));
        } catch (ValidationException $exception) {
            session()->flash('error', $exception->errors()['instance'][0] ?? $exception->getMessage());
        }
    }

    public function terminate(ProvisioningOrchestrator $orchestrator, ProvisioningService $provisioning): void
    {
        $this->authorize('provisioning.manage');

        if (! $this->requireRecentPassword('terminate')) {
            return;
        }
        try {
            $this->instance = $this->usesLifecycle()
                ? $orchestrator->terminate($this->instance)
                : $provisioning->terminate($this->instance);
            session()->flash('status', __('provisioning::admin.terminated'));
        } catch (ValidationException $exception) {
            session()->flash('error', $exception->errors()['instance'][0] ?? $exception->getMessage());
        }
    }

    public function render(AdminRegistrar $admin, ProvisionerRegistry $provisioners)
    {
        $provisioner = $this->instance->provider_key !== null
            ? $provisioners->get($this->instance->provider_key)
            : null;

        $settings = $this->instance->meta['provider_settings'] ?? [];
        $settings = is_array($settings) ? $settings : [];

        return view('livewire.admin.provisioning.show', [
            'instance' => $this->instance,
            'usesLifecycle' => $this->usesLifecycle(),
            'providerLabel' => $provisioner?->label() ?? $this->instance->provider_key,
            'providerSettings' => $settings,
        ])->layout('layouts.admin', [
            'title' => __('provisioning::admin.show_title', ['number' => $this->instance->number]),
            'navigation' => $admin->navigationItems(),
        ]);
    }

    private function usesLifecycle(): bool
    {
        if ($this->instance->provider_key === null || $this->instance->provider_key === '') {
            return false;
        }

        $provisioner = app(ProvisionerRegistry::class)->get($this->instance->provider_key);

        return $provisioner instanceof ProvisionerLifecycle;
    }
}
