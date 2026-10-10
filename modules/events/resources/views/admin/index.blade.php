<div class="admin-page ag-list-page c-events c-events--index">
    <x-ag.page-header :heading="__('events::admin.title')" :lede="__('events::admin.lede')" />
    @includeIf('events::admin.partials.tabs', ['activeTab' => 'events'])

    @if (session('status'))
        <p class="ag-alert ag-alert--success" role="status">{{ session('status') }}</p>
    @endif

    @can('events.manage')
        <section class="admin-panel c-events__section">
            <div class="c-events__heading">
                <x-ag.icon-tile name="calendar" tone="blue" :size="20" />
                <h2 class="admin-panel__title">{{ __('events::admin.create') }}</h2>
            </div>
            <form class="ag-form c-events__form" wire:submit="create">
                <div class="c-events__fields">
                    <div class="ag-field">
                        <label class="ag-field__label" for="event-name">{{ __('events::admin.name') }}</label>
                        <input id="event-name" class="ag-input" wire:model="name">
                        @error('name') <p class="ag-field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="ag-field">
                        <label class="ag-field__label" for="event-venue">{{ __('events::admin.venue') }}</label>
                        <input id="event-venue" class="ag-input" wire:model="venue">
                    </div>
                </div>
                <div class="c-events__actions">
                    <button class="ag-btn ag-btn--primary" type="submit">{{ __('events::admin.create') }}</button>
                </div>
            </form>
        </section>
    @endcan

    @if ($events->isEmpty())
        <x-ag.empty :title="__('events::admin.empty')">
            <x-slot:icon><x-ag.icon name="calendar" :size="24" /></x-slot:icon>
        </x-ag.empty>
    @else
        <div class="ag-table-wrap c-events__table-wrap" role="region" aria-label="{{ __('events::admin.title') }}" tabindex="0">
            <table class="ag-table c-events__table">
                <thead><tr>
                    <th scope="col">{{ __('events::admin.name') }}</th>
                    <th scope="col">{{ __('common.status') }}</th>
                    <th scope="col">{{ __('events::admin.performances') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($events as $event)
                        <tr wire:key="event-{{ $event->id }}">
                            <td class="c-events__identity">
                                <div class="c-events__identity-inner">
                                    <x-ag.icon-tile name="calendar" tone="violet" :size="16" />
                                    <a class="ag-table__name" href="{{ route('admin.events.show', $event) }}">{{ $event->name }}</a>
                                </div>
                            </td>
                            <td class="c-events__detail" data-label="{{ __('common.status') }}">
                                <x-ag.badge :variant="match ($event->status->value) { 'published' => 'success', 'draft' => 'warning', default => 'muted' }">{{ __('events::admin.status.'.$event->status->value) }}</x-ag.badge>
                            </td>
                            <td class="c-events__detail" data-label="{{ __('events::admin.performances') }}">{{ $event->performances_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    {{ $events->links() }}
</div>
