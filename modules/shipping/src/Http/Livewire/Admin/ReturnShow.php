<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping\Http\Livewire\Admin;

use Agovena\Modules\Shipping\Enums\ReturnRequestStatus;
use Agovena\Modules\Shipping\Models\ReturnRequest;
use Agovena\Modules\Shipping\ReturnRequestService;
use App\Agovena\Admin\AdminRegistrar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class ReturnShow extends Component
{
    use AuthorizesRequests;

    public ReturnRequest $returnRequest;

    public string $reject_reason = '';

    public string $staff_notes = '';

    /** @var array<int, int|string> */
    public array $restock_quantities = [];

    public function mount(ReturnRequest $returnRequest): void
    {
        $this->authorize('returns.view');
        $this->returnRequest = $returnRequest->load(['items.orderItem', 'order', 'customer']);
        $this->staff_notes = (string) ($returnRequest->staff_notes ?? '');
    }

    public function approve(ReturnRequestService $returns): void
    {
        $this->authorize('returns.manage');
        $this->run(fn () => $returns->approve($this->returnRequest, $this->actorId()));
    }

    public function reject(ReturnRequestService $returns): void
    {
        $this->authorize('returns.manage');
        $this->run(fn () => $returns->reject($this->returnRequest, $this->reject_reason, $this->actorId()));
    }

    public function markReceived(ReturnRequestService $returns): void
    {
        $this->authorize('returns.manage');
        $this->run(fn () => $returns->markReceived($this->returnRequest, $this->actorId()));
    }

    public function complete(ReturnRequestService $returns): void
    {
        $this->authorize('returns.manage');
        $this->run(fn () => $returns->complete($this->returnRequest, $this->actorId()));
    }

    public function saveNotes(): void
    {
        $this->authorize('returns.manage');
        $this->returnRequest->staff_notes = $this->staff_notes !== '' ? $this->staff_notes : null;
        $this->returnRequest->save();
        $this->refreshRequest();
        session()->flash('status', __('shipping::returns.saved'));
    }

    public function restock(ReturnRequestService $returns): void
    {
        $this->authorize('returns.manage');

        if (! $returns->inventoryAvailable()) {
            session()->flash('error', __('shipping::returns.restock_unavailable'));

            return;
        }

        $quantities = [];
        foreach ($this->restock_quantities as $itemId => $quantity) {
            $quantities[(int) $itemId] = (int) $quantity;
        }

        try {
            $restocked = $returns->restock($this->returnRequest, $quantities);
        } catch (ValidationException $e) {
            session()->flash('error', collect($e->errors())->flatten()->first() ?? $e->getMessage());

            return;
        }

        $this->restock_quantities = [];
        $this->refreshRequest();

        session()->flash('status', $restocked > 0
            ? __('shipping::returns.restocked', ['count' => $restocked])
            : __('shipping::returns.restocked_none'));
    }

    public function render(AdminRegistrar $admin, ReturnRequestService $returns)
    {
        $status = $this->returnRequest->status;

        return view('livewire.admin.shipping.return-show', [
            'request' => $this->returnRequest,
            'inventoryAvailable' => $returns->inventoryAvailable(),
            'canApprove' => $status->canTransitionTo(ReturnRequestStatus::Approved),
            'canReject' => $status->canTransitionTo(ReturnRequestStatus::Rejected),
            'canReceive' => $status->canTransitionTo(ReturnRequestStatus::Received),
            'canComplete' => $status->canTransitionTo(ReturnRequestStatus::Completed),
            'canRestock' => $status->allowsRestock(),
        ])->layout('layouts.admin', [
            'title' => __('shipping::returns.admin_show_title', ['number' => $this->returnRequest->id]),
            'navigation' => $admin->navigationItems(),
        ]);
    }

    private function run(callable $action): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            session()->flash('error', collect($e->errors())->flatten()->first() ?? $e->getMessage());

            return;
        }

        $this->refreshRequest();
        $this->reject_reason = '';
        session()->flash('status', __('shipping::returns.saved'));
    }

    private function refreshRequest(): void
    {
        $this->returnRequest = $this->returnRequest->fresh(['items.orderItem', 'order', 'customer'])
            ?? $this->returnRequest;
        $this->staff_notes = (string) ($this->returnRequest->staff_notes ?? '');
    }

    private function actorId(): ?int
    {
        $id = Auth::id();

        return is_numeric($id) ? (int) $id : null;
    }
}
