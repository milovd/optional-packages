<?php

return [
    'gateway' => ['label' => 'Tebex'],
    'settings' => [
        'project_id' => 'Project ID',
        'project_id_help' => 'Tebex Checkout project identifier.',
        'secret_key' => 'Secret key',
        'secret_key_help' => 'Tebex Checkout API secret.',
        'webhook_secret' => 'Webhook secret',
        'webhook_secret_help' => 'Secret configured for the Tebex webhook endpoint.',

    ],
    'errors' => [
        'not_configured' => 'Tebex is not configured.',
        'create_failed' => 'Tebex checkout could not be created.',
        'subscription_checkout_unsupported' => 'Tebex subscriptions require exactly one subscription item with quantity one.',
        'subscription_interval_unsupported' => 'This subscription interval is not supported by Tebex Checkout.',
        'subscription_action_failed' => 'The Tebex subscription action could not be completed.',
        'request_failed' => 'Tebex request failed.',
        'invalid_response' => 'Tebex returned an invalid response.',
        'webhook_invalid' => 'The Tebex webhook is invalid.',
        'refund_failed' => 'Tebex refund could not be created.',
        'partial_refund_unsupported' => 'Tebex currently supports full refunds in this Agovena adapter only.',
    ],
    'checkout' => [
        'title' => 'Tebex checkout',
        'loading' => 'Loading Tebex checkout...',
        'open' => 'Open Tebex checkout',
        'unavailable' => 'Tebex checkout could not be loaded. Try again.',
        'missing_basket' => 'The Tebex checkout could not be found.',
        'javascript_required' => 'JavaScript is required to open the Tebex checkout.',
    ],
    'health' => [
        'missing_credentials' => 'Tebex project ID or secret key is missing.',
        'missing_webhook' => 'Tebex webhook secret is missing.',
        'ok' => 'Tebex is configured. Webhook: :webhook',
    ],
];
