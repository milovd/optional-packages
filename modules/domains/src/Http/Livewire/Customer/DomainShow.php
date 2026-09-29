<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Http\Livewire\Customer;

use Agovena\Modules\Domains\DomainService;
use Agovena\Modules\Domains\DomainDnsProviderRegistry;
use Agovena\Modules\Domains\Models\DomainRegistration;
use App\Agovena\Theme\ThemeManager;
use App\Models\Customer;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class DomainShow extends Component
{
    public DomainRegistration $registration;

    public string $recordId = '';
    public string $recordType = 'A';
    public string $recordName = '@';
    public string $recordContent = '';
    public int $recordTtl = 3600;

    public function mount(DomainRegistration $registration): void
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        abort_unless($registration->customer_id === $customer->id, 404);
        $this->registration = $registration;
    }

    public function saveRecord(DomainService $domains, DomainDnsProviderRegistry $providers): void
    {
        $this->validate([
            'recordType' => ['required', 'in:A,AAAA,CNAME,MX,TXT'],
            'recordName' => ['required', 'string', 'max:253'],
            'recordContent' => ['required', 'string', 'max:2048'],
            'recordTtl' => ['required', 'integer', 'min:60', 'max:86400'],
        ]);
        $provider = $providers->get((string) $this->registration->dns_provider_key);
        if ($provider === null || ! in_array('records', $provider->capabilities(), true)) {
            throw ValidationException::withMessages(['recordContent' => __('domains::customer.dns_unavailable')]);
        }
        $domains->upsertDnsRecord($this->registration, [
            'id' => $this->recordId,
            'type' => $this->recordType,
            'name' => $this->recordName,
            'content' => $this->recordContent,
            'ttl' => $this->recordTtl,
        ]);
        $this->reset('recordId', 'recordContent');
        $this->recordType = 'A';
        $this->recordName = '@';
        $this->recordTtl = 3600;
        $this->registration->refresh();
        session()->flash('status', __('domains::customer.dns_saved'));
    }

    public function editRecord(array $record): void
    {
        $this->recordId = (string) ($record['id'] ?? '');
        $this->recordType = (string) ($record['type'] ?? 'A');
        $this->recordName = (string) ($record['name'] ?? '@');
        $this->recordContent = (string) ($record['content'] ?? '');
        $this->recordTtl = (int) ($record['ttl'] ?? 3600);
    }

    public function deleteRecord(string $id, DomainService $domains): void
    {
        $domains->deleteDnsRecord($this->registration, $id);
        $this->registration->refresh();
        session()->flash('status', __('domains::customer.dns_deleted'));
    }

    public function render(ThemeManager $themes, DomainDnsProviderRegistry $providers)
    {
        $theme = $themes->active();
        $provider = $providers->get((string) $this->registration->dns_provider_key);
        $records = $provider === null ? [] : $provider->listRecords($this->registration);

        return view($theme->view('account.domain-show'), [
            'theme' => $theme,
            'records' => $records,
            'accountSection' => 'domains',
            'zone' => data_get($this->registration->meta, 'dns_zone', []),
        ])->layout($theme->view('layouts.storefront'), [
            'title' => $this->registration->domain_name ?? __('domains::customer.title'),
            'theme' => $theme,
        ]);
    }
}
