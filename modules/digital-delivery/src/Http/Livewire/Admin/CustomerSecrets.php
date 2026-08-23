<?php

declare(strict_types=1);

namespace Agovena\Modules\DigitalDelivery\Http\Livewire\Admin;

use Agovena\Modules\DigitalDelivery\Models\DigitalSecretDelivery;
use App\Models\Customer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class CustomerSecrets extends Component
{
    use AuthorizesRequests;

    public Customer $customer;

    /** Only one secret is ever rendered in plaintext at a time, and only on request. */
    public ?int $revealedId = null;

    public function reveal(int $id): void
    {
        $this->authorize('digital_delivery.manage');
        $this->revealedId = $id;
    }

    public function hide(): void
    {
        $this->revealedId = null;
    }

    public function render()
    {
        $deliveries = DigitalSecretDelivery::query()
            ->with('product')
            ->where('customer_id', $this->customer->id)
            ->latest('id')
            ->limit(8)
            ->get();

        $revealedValue = null;
        if ($this->revealedId !== null && Gate::allows('digital_delivery.manage')) {
            $revealed = $deliveries->firstWhere('id', $this->revealedId);
            if ($revealed instanceof DigitalSecretDelivery && $revealed->isDelivered()) {
                $revealedValue = $revealed->plainValue();
            }
        }

        return view('livewire.admin.digital-delivery.customer-section', [
            'deliveries' => $deliveries,
            'revealedValue' => $revealedValue,
        ]);
    }
}
