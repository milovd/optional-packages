<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Http\Livewire\Admin;

use Agovena\Modules\Events\EventService;
use Agovena\Modules\Events\Models\EventTicket;
use App\Agovena\Admin\AdminRegistrar;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class CheckIn extends Component
{
    use AuthorizesRequests;

    public string $code = '';

    public ?int $lastTicketId = null;

    public bool $already = false;

    public function mount(): void
    {
        $this->authorize('events.checkin');
    }

    public function submit(EventService $events): void
    {
        $this->authorize('events.checkin');
        $this->validate([
            'code' => ['required', 'string', 'max:80'],
        ]);

        try {
            /** @var User|null $staff */
            $staff = Auth::user();
            $result = $events->checkIn($this->code, $staff);
            $this->lastTicketId = $result['ticket']->id;
            $this->already = $result['already'];
            $this->reset('code');
        } catch (ValidationException $e) {
            $this->lastTicketId = null;
            $this->already = false;
            throw $e;
        }
    }

    public function render(AdminRegistrar $admin)
    {
        $ticket = $this->lastTicketId !== null
            ? EventTicket::query()->with(['event', 'performance', 'ticketType'])->find($this->lastTicketId)
            : null;

        return view('livewire.admin.events.check-in', [
            'ticket' => $ticket,
        ])->layout('layouts.admin', [
            'title' => __('events::admin.checkin_title'),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
