<div class="store-confirmation">
    <h1 class="store-title">{{ __('paddle::messages.checkout.title') }}</h1>

    @if ($clientToken === null)
        <p class="store-note" role="alert">{{ __('paddle::messages.checkout.missing_client_token') }}</p>
    @else
        <p class="store-note" role="status">{{ __('paddle::messages.checkout.loading') }}</p>
        <noscript>
            <p class="store-note" role="alert">{{ __('paddle::messages.checkout.javascript_required') }}</p>
        </noscript>
        @push('scripts')
            <script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    @if ($sandbox)
                        Paddle.Environment.set('sandbox');
                    @endif
                    Paddle.Initialize({
                        token: @json($clientToken),
                    });
                });
            </script>
        @endpush
    @endif
</div>
