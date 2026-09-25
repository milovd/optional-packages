<div class="store-provider-checkout store-provider-checkout--paypal store-provider-checkout--overlay">
    @if ($clientId === null)
        <p class="store-provider-checkout__message store-note" role="alert">{{ __('paypal::messages.checkout.missing_client_id') }}</p>
    @elseif ($orderId === null || $returnUrl === null)
        <p class="store-provider-checkout__message store-note" role="alert">{{ __('paypal::messages.checkout.missing_order') }}</p>
    @else
        <p id="paypal-checkout-status" class="store-provider-checkout__message store-note" role="status" aria-live="polite">
            {{ __('paypal::messages.checkout.loading') }}
        </p>
        <div id="paypal-button-container" aria-label="PayPal"></div>
        <noscript>
            <p class="store-provider-checkout__message store-note" role="alert">{{ __('paypal::messages.checkout.javascript_required') }}</p>
        </noscript>
        @push('scripts')
            <script src="https://www.paypal.com/sdk/js?client-id={{ rawurlencode($clientId) }}&currency={{ rawurlencode($currency) }}&intent=capture&components=buttons&commit=true"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const orderId = @json($orderId);
                    const returnUrl = @json($returnUrl);
                    const statusElement = document.getElementById('paypal-checkout-status');
                    const buttonContainer = document.getElementById('paypal-button-container');
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

                    const showError = function () {
                        if (statusElement !== null) {
                            statusElement.textContent = @json(__('paypal::messages.checkout.error'));
                        }
                    };

                    if (window.paypal === undefined || typeof window.paypal.Buttons !== 'function') {
                        showError();
                        return;
                    }

                    const buttons = window.paypal.Buttons({
                        createOrder: function () {
                            return Promise.resolve(orderId);
                        },
                        onApprove: function () {
                            if (statusElement !== null) {
                                statusElement.textContent = @json(__('paypal::messages.checkout.approval_received'));
                            }
                            window.setTimeout(function () {
                                returnToStatus('completed');
                            }, 250);
                        },
                        onCancel: function () {
                            returnToStatus('cancelled');
                        },
                        onError: function () {
                            showError();
                            window.setTimeout(function () {
                                returnToStatus('error');
                            }, 250);
                        },
                    });

                    if (typeof buttons.isEligible === 'function' && !buttons.isEligible()) {
                        showError();
                        return;
                    }

                    buttons.render('#paypal-button-container').then(function () {
                        if (statusElement !== null) {
                            statusElement.hidden = true;
                        }
                    }).catch(function () {
                        showError();
                    });
                });
            </script>
        @endpush
    @endif
</div>
