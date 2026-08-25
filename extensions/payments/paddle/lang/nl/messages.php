<?php

return [
    'gateway' => ['label' => 'Paddle'],
    'settings' => [
        'api_key' => 'API-sleutel',
        'api_key_help' => 'Bewaar de Paddle Billing API-sleutel in de versleutelde extensie-instellingen.',
        'webhook_secret' => 'Webhookgeheim',
        'webhook_secret_help' => 'Geheim van de Paddle-notificatiedestination.',
        'price_map' => 'Product-naar-Paddle-prijsmapping',
        'price_map_help' => 'JSON-object dat Agovena-product-ID\'s aan Paddle-price-ID\'s koppelt.',
        'sandbox' => 'Sandboxmodus',
        'sandbox_help' => 'Gebruik het Paddle-sandboxendpoint.',
    ],
    'errors' => [
        'not_configured' => 'Paddle is niet geconfigureerd.',
        'price_mapping_missing' => 'Voor dit product ontbreekt een Paddle-prijsmapping.',
        'items_missing' => 'Deze order bevat geen betaalbare items.',
        'create_failed' => 'Paddle-checkout kon niet worden aangemaakt.',
        'request_failed' => 'Paddle-request mislukt.',
        'invalid_response' => 'Paddle gaf een ongeldige response terug.',
        'webhook_invalid' => 'De Paddle-webhook is ongeldig.',
        'refund_failed' => 'De Paddle-refund kon niet worden aangemaakt.',
        'partial_refund_unsupported' => 'Deze Agovena-adapter ondersteunt momenteel alleen volledige Paddle-refunds.',
    ],
    'health' => [
        'missing_key' => 'Paddle API-sleutel ontbreekt.',
        'missing_webhook' => 'Paddle webhookgeheim ontbreekt.',
        'ok' => 'Paddle is geconfigureerd voor :mode. Webhook: :webhook',
    ],
];
