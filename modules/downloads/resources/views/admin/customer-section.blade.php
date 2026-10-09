<section class="admin-panel">
    <h2 class="admin-panel__title">{{ __('digital::admin.customer_heading') }}</h2>
    @if ($entitlements->isEmpty())
        <p class="ag-muted">{{ __('digital::admin.customer_empty') }}</p>
    @else
        <div class="ag-table-wrap">
            <table class="ag-table">
                <thead>
                    <tr>
                        <th>{{ __('digital::admin.label') }}</th>
                        <th>{{ __('digital::admin.download_limit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entitlements as $row)
                        <tr wire:key="customer-download-{{ $row->id }}">
                            <td>{{ $row->asset?->label ?? $row->asset?->filename }}</td>
                            <td>{{ $row->download_count }} / {{ $row->download_limit ?? '∞' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
