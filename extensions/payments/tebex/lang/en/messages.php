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
        'package_map' => 'Product to Tebex package mapping',
        'package_map_help' => 'JSON object mapping Agovena product IDs to Tebex package IDs.',
    ],
    'errors' => [
        'not_configured' => 'Tebex is not configured.',
        'package_mapping_missing' => 'A Tebex package mapping is missing for this product.',
        'create_failed' => 'Tebex checkout could not be created.',
        'request_failed' => 'Tebex request failed.',
        'invalid_response' => 'Tebex returned an invalid response.',
        'webhook_invalid' => 'The Tebex webhook is invalid.',
        'refund_failed' => 'Tebex refund could not be created.',
        'partial_refund_unsupported' => 'Tebex currently supports full refunds in this Agovena adapter only.',
    ],
    'health' => [
        'missing_credentials' => 'Tebex project ID or secret key is missing.',
        'missing_webhook' => 'Tebex webhook secret is missing.',
        'ok' => 'Tebex is configured. Webhook: :webhook',
    ],
];
