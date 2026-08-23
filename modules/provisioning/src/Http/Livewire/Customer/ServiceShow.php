<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Http\Livewire\Customer;

use Agovena\Modules\Provisioning\EloquentProvisionedServiceResolver;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use Agovena\Modules\Subscriptions\Models\Subscription;
use App\Agovena\Provisioning\Contracts\ProvisionerActions;
use App\Agovena\Provisioning\Contracts\ProvisionerPanel;
use App\Agovena\Provisioning\ProvisionerRegistry;
use App\Agovena\Provisioning\RunProvisionerAction;
use App\Agovena\Theme\ThemeManager;
use App\Models\Customer;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

final class ServiceShow extends Component
{
    public ServiceInstance $instance;

    public function mount(ServiceInstance $instance): void
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        abort_unless($this->owns($instance, $customer), 404);
        $this->instance = $instance->load(['product', 'order.invoice']);
    }

    public function runAction(string $actionId, RunProvisionerAction $runner): void
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        abort_unless($this->owns($this->instance, $customer), 404);

        $runner->handle($customer, $this->instance->id, $actionId);
        session()->flash('status', __('provisioning::customer.action_completed'));
    }

    public function render(ThemeManager $themes)
    {
        $theme = $themes->active();
        $registry = app(ProvisionerRegistry::class);
        $provisioner = $this->instance->provider_key !== null ? $registry->get($this->instance->provider_key) : null;
        $info = EloquentProvisionedServiceResolver::info($this->instance);

        $subscription = null;
        if ($this->instance->subscription_id !== null
            && class_exists(Subscription::class)
            && Schema::hasTable('subscriptions')) {
            $subscription = Subscription::query()->find($this->instance->subscription_id);
        }

        return view($theme->view('account.services.show'), [
            'theme' => $theme,
            'instance' => $this->instance,
            'subscription' => $subscription,
            'panel' => $provisioner instanceof ProvisionerPanel ? $provisioner->panel($info) : null,
            'actions' => $provisioner instanceof ProvisionerActions ? $provisioner->actions($info) : [],
            'accountSection' => 'services',
        ])->layout($theme->view('layouts.storefront'), [
            'title' => __('provisioning::customer.show_title', ['number' => $this->instance->number]),
            'theme' => $theme,
        ]);
    }

    private function owns(ServiceInstance $instance, Customer $customer): bool
    {
        return (int) $instance->customer_id === (int) $customer->id
            || $instance->customer_email === $customer->email;
    }
}
