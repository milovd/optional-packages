<?php

declare(strict_types=1);

return [
    'gateway' => [
        'label' => 'Stripe',
    ],
    'settings' => [
        'secret_key' => 'Secret key',
        'secret_key_help' => 'Use a sk_test_ or sk_live_ key from the Stripe dashboard. Leave blank after saving to keep the stored key. Override with AGOVENA_EXT_STRIPE_SECRET_KEY. Do not collect card details on this server.',
        'webhook_secret' => 'Webhook signing secret',
        'webhook_secret_help' => 'whsec_ secret for Stripe-Signature verification. Webhook URL: /webhooks/payments/stripe. Override with AGOVENA_EXT_STRIPE_WEBHOOK_SECRET.',
        'enabled_methods' => 'Enabled Checkout methods',
        'enabled_methods_help' => 'Optional comma-separated Stripe payment method types (card, ideal, bancontact). Leave empty to let Stripe Checkout present automatic methods.',
    ],
    'methods' => [
        'card' => 'Card',
        'ideal' => 'iDEAL',
        'bancontact' => 'Bancontact',
        'klarna' => 'Klarna',
        'paypal' => 'PayPal',
        'sepa_debit' => 'SEPA Direct Debit',
    ],
    'health' => [
        'ok' => 'Connected (:mode). Webhook: :webhook',
        'missing_key' => 'Secret key is not configured.',
        'invalid_key' => 'Secret key must start with sk_test_ or sk_live_.',
        'missing_webhook' => 'Webhook signing secret is not configured.',
        'unreachable' => 'Could not reach Stripe. Check the secret key and network.',
    ],
    'errors' => [
        'not_configured' => 'Stripe is not configured.',
        'unauthorized' => 'Stripe rejected the API credentials.',
        'server_error' => 'Stripe returned a temporary server error.',
        'create_failed' => 'The payment could not be started.',
        'cancel_unsupported' => 'This payment cannot be cancelled at the provider.',
        'refund_failed' => 'The refund could not be processed.',
        'sync_failed' => 'Payment status could not be refreshed.',
        'recurring_unavailable' => 'No reusable payment authorization is on file for this customer.',
        'webhook_invalid' => 'The Stripe webhook signature is invalid.',
    ],
];
