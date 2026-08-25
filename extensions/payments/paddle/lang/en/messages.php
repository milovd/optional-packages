<?php

return [
    'gateway' => ['label' => 'Paddle'],
    'settings' => [
        'api_key' => 'API key',
        'api_key_help' => 'Store the Paddle Billing API key in the encrypted extension settings.',
        'webhook_secret' => 'Webhook secret',
        'webhook_secret_help' => 'Secret for the Paddle notification destination.',
        'price_map' => 'Product to Paddle price mapping',
        'price_map_help' => 'JSON object mapping Agovena product IDs to Paddle price IDs.',
        'sandbox' => 'Sandbox mode',
        'sandbox_help' => 'Use the Paddle sandbox API endpoint.',
    ],
    'errors' => [
        'not_configured' => 'Paddle is not configured.',
        'price_mapping_missing' => 'A Paddle price mapping is missing for this product.',
        'items_missing' => 'This order has no payable items.',
        'create_failed' => 'Paddle checkout could not be created.',
        'request_failed' => 'Paddle request failed.',
        'invalid_response' => 'Paddle returned an invalid response.',
        'webhook_invalid' => 'The Paddle webhook is invalid.',
        'refund_failed' => 'Paddle refund could not be created.',
        'partial_refund_unsupported' => 'Paddle currently supports full refunds in this Agovena adapter only.',
    ],
    'health' => [
        'missing_key' => 'Paddle API key is missing.',
        'missing_webhook' => 'Paddle webhook secret is missing.',
        'ok' => 'Paddle is configured for :mode. Webhook: :webhook',
    ],
];
