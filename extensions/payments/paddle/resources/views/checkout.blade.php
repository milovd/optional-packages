<div class="store-provider-checkout store-provider-checkout--paddle store-provider-checkout--overlay">
    @if ($clientToken === null)
        <p class="store-provider-checkout__message store-note" role="alert">{{ __('paddle::messages.checkout.missing_client_token') }}</p>
    @elseif ($transactionId === null)
        <p class="store-provider-checkout__message store-note" role="alert">{{ __('paddle::messages.checkout.missing_transaction') }}</p>
    @else
        <p id="paddle-checkout-status" class="store-provider-checkout__message store-note" role="status" aria-live="polite">
            {{ __('paddle::messages.checkout.loading') }}
        </p>
        <noscript>
            <p class="store-provider-checkout__message store-note" role="alert">{{ __('paddle::messages.checkout.javascript_required') }}</p>
        </noscript>
        @push('scripts')
            <script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    @if ($sandbox)
                        Paddle.Environment.set('sandbox');
                    @endif

                    const transactionId = @json($transactionId);
                    const returnUrl = @json($returnUrl);
                    const allowedPaymentMethods = @json($allowedPaymentMethods);
                    const checkoutTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                    const checkoutLocale = @json($locale);
                    const checkoutStatus = document.getElementById('paddle-checkout-status');
                    const checkoutSettings = {
                        displayMode: 'overlay',
                        variant: 'one-page',
                        theme: checkoutTheme,
                        locale: checkoutLocale,
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

                    let returnedToStatus = false;

                    const returnToStatus = function (eventName) {
                        if (returnUrl === null || returnedToStatus) {
                            return;
                        }

                        returnedToStatus = true;
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
                            if (event.name === 'checkout.loaded' && checkoutStatus !== null) {
                                checkoutStatus.hidden = true;
                            }
                            if (event.name === 'checkout.completed') {
                                window.setTimeout(function () {
                                    returnToStatus('completed');
                                }, 250);
                            }
                            switch (event.name) {
                                case 'checkout.payment.failed':
                                    returnToStatus('failed');
                                    break;
                                case 'checkout.payment.error':
                                case 'checkout.error':
                                    returnToStatus('error');
                                    break;
                                case 'checkout.closed':
                                    returnToStatus('cancelled');
                                    break;
                            }
                        },
                    });

                    Paddle.Checkout.open({
                        transactionId: transactionId,
                        settings: checkoutSettings,
                    });
                });
            </script>
        @endpush
    @endif
</div>
