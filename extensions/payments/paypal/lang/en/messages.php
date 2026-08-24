<?php

declare(strict_types=1);

return [
    'gateway' => [
        'label' => 'PayPal',
    ],
    'settings' => [
        'client_id' => 'Client ID',
        'client_id_help' => 'REST app client ID from the PayPal developer dashboard. Webhook URL: /webhooks/payments/paypal',
        'client_secret' => 'Client secret',
        'client_secret_help' => 'REST app secret. Encrypted at rest. Leave blank after saving to keep the stored secret.',
        'webhook_id' => 'Webhook ID',
        'webhook_id_help' => 'Webhook ID from the PayPal developer dashboard. Required for signature verification.',
        'sandbox' => 'Sandbox mode',
        'sandbox_help' => 'Use PayPal sandbox endpoints for testing. Disable for live payments.',
    ],
    'health' => [
        'ok' => 'Connected (:mode). Webhook: :webhook',
        'missing_client_id' => 'Client ID is not configured.',
        'missing_secret' => 'Client secret is not configured.',
        'missing_webhook' => 'Webhook ID is not configured.',
        'unauthorized' => 'PayPal rejected the REST credentials.',
        'unreachable' => 'Could not reach PayPal. Check credentials and network.',
    ],
    'errors' => [
        'not_configured' => 'PayPal is not configured.',
        'unauthorized' => 'PayPal rejected the API credentials.',
        'server_error' => 'PayPal returned a temporary server error.',
        'create_failed' => 'The payment could not be started.',
        'cancel_unsupported' => 'This payment cannot be cancelled at the provider.',
        'refund_failed' => 'The refund could not be processed.',
        'webhook_invalid' => 'The PayPal webhook payload was invalid.',
        'malformed' => 'PayPal returned an unexpected response.',
        'provider_failed' => 'PayPal rejected the request.',
    ],
];
