<?php

return [
    'gateway' => ['label' => 'Tebex'],
    'settings' => [
        'project_id' => 'Project-ID',
        'project_id_help' => 'Identificatie van het Tebex Checkout-project.',
        'secret_key' => 'Geheime sleutel',
        'secret_key_help' => 'Tebex Checkout API-geheim.',
        'webhook_secret' => 'Webhookgeheim',
        'webhook_secret_help' => 'Geheim dat voor het Tebex-webhookendpoint is ingesteld.',
        'package_map' => 'Product-naar-Tebex-packagemapping',
        'package_map_help' => 'JSON-object dat Agovena-product-ID\'s aan Tebex-package-ID\'s koppelt.',
    ],
    'errors' => [
        'not_configured' => 'Tebex is niet geconfigureerd.',
        'package_mapping_missing' => 'Voor dit product ontbreekt een Tebex-packagemapping.',
        'create_failed' => 'Tebex-checkout kon niet worden aangemaakt.',
        'request_failed' => 'Tebex-request mislukt.',
        'invalid_response' => 'Tebex gaf een ongeldige response terug.',
        'webhook_invalid' => 'De Tebex-webhook is ongeldig.',
        'refund_failed' => 'De Tebex-refund kon niet worden aangemaakt.',
        'partial_refund_unsupported' => 'Deze Agovena-adapter ondersteunt momenteel alleen volledige Tebex-refunds.',
    ],
    'health' => [
        'missing_credentials' => 'Tebex project-ID of geheime sleutel ontbreekt.',
        'missing_webhook' => 'Tebex webhookgeheim ontbreekt.',
        'ok' => 'Tebex is geconfigureerd. Webhook: :webhook',
    ],
];
