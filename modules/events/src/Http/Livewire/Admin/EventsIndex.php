<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Http\Livewire\Admin;

use Agovena\Modules\Events\Enums\EventStatus;
use Agovena\Modules\Events\Models\Event;
use App\Agovena\Admin\AdminRegistrar;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

final class EventsIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $name = '';

    public string $venue = '';

    public function mount(): void
    {
        $this->authorize('events.view');
    }

    public function create(): void
    {
        $this->authorize('events.manage');
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
        ]);

        $event = Event::query()->create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'venue' => $data['venue'] !== '' ? $data['venue'] : null,
            'status' => EventStatus::Draft,
        ]);

        $this->redirect(route('admin.events.show', $event), navigate: true);
    }

    public function render(AdminRegistrar $admin)
    {
        return view('livewire.admin.events.index', [
            'events' => Event::query()->withCount('performances')->latest('id')->paginate(20),
        ])->layout('layouts.admin', [
            'title' => __('events::admin.title'),
            'navigation' => $admin->navigationItems(),
        ]);
    }
}
