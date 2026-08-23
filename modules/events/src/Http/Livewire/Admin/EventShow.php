<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Http\Livewire\Admin;

use Agovena\Modules\Events\Enums\EventStatus;
use Agovena\Modules\Events\EventService;
use Agovena\Modules\Events\Models\Event;
use Agovena\Modules\Events\Models\EventPerformance;
use Agovena\Modules\Events\Models\EventTicketType;
use App\Agovena\Admin\AdminRegistrar;
use App\Agovena\Catalog\Capabilities\ProductCapabilityManager;
use App\Models\Product;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

final class EventShow extends Component
{
    use AuthorizesRequests;

    public Event $event;

    public string $name = '';

    public string $venue = '';

    public string $description = '';

    public string $status = 'draft';

    public string $performance_starts_at = '';

    public int $performance_capacity = 100;

    public ?int $ticket_product_id = null;

    public ?int $ticket_performance_id = null;

    public string $ticket_name = '';

    public function mount(Event $event): void
    {
        $this->authorize('events.view');
        $this->event = $event;
        $this->name = $event->name;
        $this->venue = (string) ($event->venue ?? '');
        $this->description = (string) ($event->description ?? '');
        $this->status = $event->status->value;
    }

    public function save(): void
    {
        $this->authorize('events.manage');
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'in:draft,published,cancelled'],
        ]);
        $this->event->fill([
            'name' => $data['name'],
            'venue' => $data['venue'] !== '' ? $data['venue'] : null,
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'status' => EventStatus::from($data['status']),
        ])->save();
        session()->flash('status', __('events::admin.saved'));
    }

    public function addPerformance(): void
    {
        $this->authorize('events.manage');
        $data = $this->validate([
            'performance_starts_at' => ['required', 'date'],
            'performance_capacity' => ['required', 'integer', 'min:1'],
        ]);
        EventPerformance::query()->create([
            'event_id' => $this->event->id,
            'starts_at' => $data['performance_starts_at'],
            'capacity' => $data['performance_capacity'],
            'venue' => $this->event->venue,
        ]);
        $this->reset('performance_starts_at');
        session()->flash('status', __('events::admin.performance_added'));
    }

    public function attachTicketType(ProductCapabilityManager $capabilities): void
    {
        $this->authorize('events.manage');
        $data = $this->validate([
            'ticket_product_id' => ['required', 'integer'],
            'ticket_performance_id' => ['required', 'integer'],
            'ticket_name' => ['required', 'string', 'max:120'],
        ]);
        $product = Product::query()->findOrFail((int) $data['ticket_product_id']);
        $performance = EventPerformance::query()
            ->where('event_id', $this->event->id)
            ->whereKey((int) $data['ticket_performance_id'])
            ->firstOrFail();

        $capabilities->enable($product, 'event_ticket');

        EventTicketType::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'performance_id' => $performance->id,
            ],
            [
                'event_id' => $this->event->id,
                'name' => $data['ticket_name'],
            ],
        );
        session()->flash('status', __('events::admin.ticket_type_saved'));
    }

    public function render(AdminRegistrar $admin, EventService $events)
    {
        $this->event->load(['performances', 'ticketTypes.product']);
        $remaining = [];
        foreach ($this->event->performances as $performance) {
            $remaining[$performance->id] = $events->remainingForPerformance($performance);
        }

        return view('livewire.admin.events.show', [
            'remaining' => $remaining,
            'products' => Product::query()->active()->orderBy('name')->limit(100)->get(),
        ])->layout('layouts.admin', [
            'title' => $this->event->name,
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
