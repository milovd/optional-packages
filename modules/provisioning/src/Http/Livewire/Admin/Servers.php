<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Http\Livewire\Admin;

use App\Agovena\Admin\AdminRegistrar;
use App\Agovena\Provisioning\Contracts\ConfiguresProvisioningServers;
use App\Agovena\Provisioning\ProvisionerRegistry;
use App\Models\ProvisioningServer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

final class Servers extends Component
{
    use AuthorizesRequests;

    public ?int $editingId = null;

    public string $name = '';

    public string $providerKey = '';

    /** @var array<string, mixed> */
    public array $settings = [];

    public bool $isActive = true;

    public function mount(): void
    {
        $this->authorize('provisioning.manage');
        $this->providerKey = (string) (array_key_first($this->providers()) ?? '');
        $this->initializeDefaults();
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'settings']);
        $this->isActive = true;
        $this->providerKey = (string) (array_key_first($this->providers()) ?? '');
        $this->initializeDefaults();
    }

    public function edit(int $id): void
    {
        $server = ProvisioningServer::query()->findOrFail($id);
        $this->editingId = $server->id;
        $this->name = $server->name;
        $this->providerKey = $server->provider_key;
        $this->isActive = $server->is_active;
        $this->settings = [];
        foreach ($this->settingDefinitions() as $definition) {
            $this->settings[$definition->key] = $definition->secret
                ? ''
                : ($server->settings[$definition->key] ?? $definition->default ?? '');
        }
    }

    public function updatedProviderKey(): void
    {
        $this->settings = [];
        $this->initializeDefaults();
    }

    public function save(): void
    {
        $this->authorize('provisioning.manage');
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'providerKey' => ['required', 'string', 'max:64'],
            'isActive' => ['boolean'],
            'settings' => ['array'],
        ];
        foreach ($this->settingDefinitions() as $definition) {
            $required = $definition->required && ($this->editingId === null || ! $definition->secret);
            $rules['settings.'.$definition->key] = [
                $required ? 'required' : 'nullable',
                $definition->type === 'boolean' ? 'boolean' : 'string',
            ];
        }
        $data = $this->validate($rules);

        $server = $this->editingId !== null
            ? ProvisioningServer::query()->findOrFail($this->editingId)
            : new ProvisioningServer;
        $stored = $server->exists ? $server->settings : [];
        foreach ($this->settingDefinitions() as $definition) {
            $value = $data['settings'][$definition->key] ?? null;
            if ($definition->secret && ($value === null || $value === '')) {
                continue;
            }
            $stored[$definition->key] = $value ?? $definition->default ?? '';
        }
        $server->fill([
            'name' => $data['name'],
            'provider_key' => $data['providerKey'],
            'settings' => $stored,
            'is_active' => $data['isActive'],
        ])->save();

        $this->editingId = $server->id;
        session()->flash('status', __('provisioning::admin.server_saved'));
    }

    public function testConnection(): void
    {
        $this->authorize('provisioning.manage');
        $provider = app(ProvisionerRegistry::class)->get($this->providerKey);
        if (! $provider instanceof ConfiguresProvisioningServers) {
            $this->addError('providerKey', __('provisioning::admin.server_provider_unavailable'));

            return;
        }

        $settings = $this->settings;
        if ($this->editingId !== null) {
            $stored = ProvisioningServer::query()->findOrFail($this->editingId)->settings;
            foreach ($this->settingDefinitions() as $definition) {
                if ($definition->secret && empty($settings[$definition->key])) {
                    $settings[$definition->key] = $stored[$definition->key] ?? '';
                }
            }
        }
        $result = $provider->testServer($settings);
        $result->ok
            ? session()->flash('status', __('provisioning::admin.server_connection_ok'))
            : $this->addError('providerKey', __($result->message));
    }

    /** @return array<string, string> */
    private function providers(): array
    {
        $providers = [];
        foreach (app(ProvisionerRegistry::class)->all() as $provider) {
            if ($provider instanceof ConfiguresProvisioningServers) {
                $providers[$provider->id()] = $provider->label();
            }
        }

        return $providers;
    }

    private function provider(): ?ConfiguresProvisioningServers
    {
        $provider = app(ProvisionerRegistry::class)->get($this->providerKey);

        return $provider instanceof ConfiguresProvisioningServers ? $provider : null;
    }

    private function settingDefinitions(): array
    {
        return $this->provider()?->serverSettings() ?? [];
    }

    private function initializeDefaults(): void
    {
        foreach ($this->settingDefinitions() as $definition) {
            $this->settings[$definition->key] = $definition->default ?? ($definition->type === 'boolean' ? false : '');
        }
    }

    public function render(AdminRegistrar $admin)
    {
        return view('livewire.admin.provisioning.servers', [
            'servers' => ProvisioningServer::query()->orderBy('name')->get(),
            'providers' => $this->providers(),
            'settingDefinitions' => $this->settingDefinitions(),
        ])->layout('layouts.admin', [
            'title' => __('provisioning::admin.servers_title'),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
