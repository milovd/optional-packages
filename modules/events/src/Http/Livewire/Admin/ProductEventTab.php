<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Http\Livewire\Admin;

use Agovena\Modules\Events\Enums\EventStatus;
use Agovena\Modules\Events\Models\Event;
use Agovena\Modules\Events\Models\EventPerformance;
use Agovena\Modules\Events\Models\EventTicketType;
use App\Agovena\Catalog\Capabilities\ProductCapabilityManager;
use App\Models\Product;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

final class ProductEventTab extends Component
{
    use AuthorizesRequests;

    public Product $product;

    public ?int $eventId = null;

    public ?int $performanceId = null;

    public string $eventName = '';

    public string $venue = '';

    public string $description = '';

    public string $status = 'draft';

    public string $startsAt = '';

    public int $capacity = 100;

    public string $ticketName = '';

    public function mount(Product $product): void
    {
        $this->authorize('events.view');
        $this->product = $product;
        $this->eventName = $product->name;
        $this->ticketName = $product->name;

        $ticketType = EventTicketType::query()
            ->with(['event', 'performance'])
            ->where('product_id', $product->id)
            ->first();
        if ($ticketType === null) {
            return;
        }

        $this->eventId = $ticketType->event_id;
        $this->performanceId = $ticketType->performance_id;
        $this->eventName = $ticketType->event->name;
        $this->venue = (string) $ticketType->event->venue;
        $this->description = (string) $ticketType->event->description;
        $this->status = $ticketType->event->status->value;
        $this->startsAt = $ticketType->performance->starts_at->format('Y-m-d\TH:i');
        $this->capacity = max(1, (int) $ticketType->performance->capacity);
        $this->ticketName = $ticketType->name;
    }

    public function save(ProductCapabilityManager $capabilities): void
    {
        $this->authorize('events.manage');
        $data = $this->validate([
            'eventName' => ['required', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'in:draft,published,cancelled'],
            'startsAt' => ['required', 'date'],
            'capacity' => ['required', 'integer', 'min:1'],
            'ticketName' => ['required', 'string', 'max:120'],
        ]);

        DB::transaction(function () use ($capabilities, $data): void {
            $event = $this->eventId !== null
                ? Event::query()->findOrFail($this->eventId)
                : new Event;
            $event->fill([
                'name' => $data['eventName'],
                'slug' => $event->exists ? $event->slug : Str::slug($data['eventName']).'-'.Str::lower(Str::random(4)),
                'venue' => $data['venue'] !== '' ? $data['venue'] : null,
                'description' => $data['description'] !== '' ? $data['description'] : null,
                'status' => EventStatus::from($data['status']),
            ])->save();

            $performance = $this->performanceId !== null
                ? EventPerformance::query()->where('event_id', $event->id)->findOrFail($this->performanceId)
                : new EventPerformance;
            $performance->fill([
                'event_id' => $event->id,
                'starts_at' => $data['startsAt'],
                'capacity' => $data['capacity'],
                'venue' => $data['venue'] !== '' ? $data['venue'] : null,
            ])->save();

            if (! $this->product->hasCapability('event_ticket')) {
                $capabilities->enable($this->product, 'event_ticket');
            }

            EventTicketType::query()->updateOrCreate(
                ['product_id' => $this->product->id],
                [
                    'event_id' => $event->id,
                    'performance_id' => $performance->id,
                    'name' => $data['ticketName'],
                    'capacity' => $data['capacity'],
                ],
            );

            $this->eventId = $event->id;
            $this->performanceId = $performance->id;
        });

        session()->flash('status', __('events::admin.product_event_saved'));
    }

    public function render()
    {
        return view('livewire.admin.events.product-tab');
    }
}
