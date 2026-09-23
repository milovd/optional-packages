<div class="store-confirmation">
    <h1 class="store-title">{{ __('paddle::messages.checkout.title') }}</h1>

    @if ($clientToken === null)
        <p class="store-note" role="alert">{{ __('paddle::messages.checkout.missing_client_token') }}</p>
    @elseif ($transactionId === null)
        <p class="store-note" role="alert">{{ __('paddle::messages.checkout.missing_transaction') }}</p>
    @else
        <p class="store-note" role="status">{{ __('paddle::messages.checkout.loading') }}</p>
        <div class="paddle-checkout-frame" aria-live="polite"></div>
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

                    const returnUrl = @json($returnUrl);
                    const allowedPaymentMethods = @json($allowedPaymentMethods);
                    const checkoutSettings = {
                        displayMode: 'inline',
                        frameTarget: 'paddle-checkout-frame',
                        frameInitialHeight: '650',
                        frameStyle: 'width: 100%; min-width: 312px; border: 0;',
                        variant: 'one-page',
                        allowLogout: false,
                        allowDiscountRemoval: false,
                        showAddDiscounts: false,
                        showAddTaxId: false,
                    };

                    if (allowedPaymentMethods.length > 0) {
                        checkoutSettings.allowedPaymentMethods = allowedPaymentMethods;
                    }

                    if (returnUrl !== null) {
                        checkoutSettings.successUrl = returnUrl;
                    }

                    const returnToStatus = function (eventName) {
                        if (returnUrl === null) {
                            return;
                        }

                        const target = new URL(returnUrl, window.location.origin);
                        if (eventName !== 'completed') {
                            target.searchParams.set('checkout_event', eventName);
                        }
                        window.location.assign(target.toString());
                    };

                    Paddle.Initialize({
                        token: @json($clientToken),
                        checkout: { settings: checkoutSettings },
                        eventCallback: function (event) {
                            if (event.name === 'checkout.completed') {
                                window.setTimeout(function () {
                                    returnToStatus('completed');
                                }, 250);
                            }
                            if (event.name === 'checkout.payment.failed') {
                                returnToStatus('failed');
                            }
                            if (event.name === 'checkout.closed') {
                                returnToStatus('cancelled');
                            }
                        },
                    });
                });
            </script>
        @endpush
    @endif
</div>
