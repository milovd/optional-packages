<?php

declare(strict_types=1);

return [
    'gateway' => [
        'label' => 'Mollie',
    ],
    'settings' => [
        'api_key' => 'API key',
        'api_key_help' => 'Use a test_ or live_ key from your Mollie dashboard. Leave blank after saving to keep the stored key. Override with AGOVENA_EXT_MOLLIE_API_KEY. Webhook URL: /webhooks/payments/mollie',
        'enabled_methods' => 'Enabled payment methods',
        'enabled_methods_help' => 'Optional comma-separated Mollie method ids (ideal, bancontact, creditcard, paypal). Leave empty to offer all methods enabled on the Mollie profile.',
    ],
    'methods' => [
        'ideal' => 'iDEAL',
        'bancontact' => 'Bancontact',
        'creditcard' => 'Card',
        'paypal' => 'PayPal',
        'applepay' => 'Apple Pay',
        'klarna' => 'Klarna',
        'banktransfer' => 'Bank transfer',
        'sofort' => 'Sofort',
        'giropay' => 'Giropay',
        'eps' => 'EPS',
        'przelewy24' => 'Przelewy24',
        'kbc' => 'KBC',
        'belfius' => 'Belfius',
        'in3' => 'in3',
        'twint' => 'TWINT',
        'blik' => 'BLIK',
    ],
    'health' => [
        'ok' => 'Connected (:mode). Methods: :methods. Webhook: :webhook',
        'missing_key' => 'API key is not configured.',
        'invalid_key' => 'API key must start with test_ or live_.',
        'unreachable' => 'Could not reach Mollie. Check the API key and network.',
    ],
    'errors' => [
        'not_configured' => 'Mollie is not configured.',
        'unauthorized' => 'Mollie rejected the API credentials.',
        'server_error' => 'Mollie returned a temporary server error.',
        'create_failed' => 'The payment could not be started.',
        'cancel_unsupported' => 'This payment cannot be cancelled at the provider.',
        'refund_failed' => 'The refund could not be processed.',
        'sync_failed' => 'Payment status could not be refreshed.',
        'recurring_unavailable' => 'No reusable payment mandate is on file for this customer.',
    ],
];
