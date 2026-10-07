<div class="admin-page">
    <x-ag.page-header :heading="__('events::admin.title')" :lede="__('events::admin.lede')" />

    @if (session('status'))
        <p class="ag-alert ag-alert--success" role="status">{{ session('status') }}</p>
    @endif

    @can('events.manage')
        <section class="admin-panel">
            <h2 class="admin-panel__title">{{ __('events::admin.create') }}</h2>
            <form class="ag-form" wire:submit="create">
                <div class="ag-field">
                    <label class="ag-field__label" for="event-name">{{ __('events::admin.name') }}</label>
                    <input id="event-name" class="ag-input" wire:model="name">
                    @error('name') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="event-venue">{{ __('events::admin.venue') }}</label>
                    <input id="event-venue" class="ag-input" wire:model="venue">
                </div>
                <button class="ag-btn ag-btn--primary" type="submit">{{ __('events::admin.create') }}</button>
            </form>
        </section>
    @endcan

    <div class="ag-table-wrap">
        <table class="ag-table">
            <thead>
                <tr>
                    <th>{{ __('events::admin.name') }}</th>
                    <th>{{ __('common.status') }}</th>
                    <th>{{ __('events::admin.performances') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td><a href="{{ route('admin.events.show', $event) }}">{{ $event->name }}</a></td>
                        <td><span class="ag-badge">{{ __('events::admin.status.'.$event->status->value) }}</span></td>
                        <td>{{ $event->performances_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">{{ __('events::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $events->links() }}
</div>
