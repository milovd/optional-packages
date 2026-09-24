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

    ],
    'errors' => [
        'not_configured' => 'Tebex is niet geconfigureerd.',
        'create_failed' => 'Tebex-checkout kon niet worden aangemaakt.',
        'subscription_checkout_unsupported' => 'Tebex-subscriptions vereisen exact één subscription-item met quantity één.',
        'subscription_interval_unsupported' => 'Dit subscription-interval wordt niet ondersteund door Tebex Checkout.',
        'subscription_action_failed' => 'De Tebex-subscriptionactie kon niet worden uitgevoerd.',
        'request_failed' => 'Tebex-request mislukt.',
        'invalid_response' => 'Tebex gaf een ongeldige response terug.',
        'webhook_invalid' => 'De Tebex-webhook is ongeldig.',
        'refund_failed' => 'De Tebex-refund kon niet worden aangemaakt.',
        'partial_refund_unsupported' => 'Deze Agovena-adapter ondersteunt momenteel alleen volledige Tebex-refunds.',
    ],
    'checkout' => [
        'title' => 'Tebex-checkout',
        'loading' => 'Tebex-checkout wordt geladen...',
        'open' => 'Tebex-checkout openen',
        'unavailable' => 'Tebex-checkout kon niet worden geladen. Probeer opnieuw.',
        'missing_basket' => 'De Tebex-checkout kon niet worden gevonden.',
        'javascript_required' => 'JavaScript is vereist om de Tebex-checkout te openen.',
    ],
    'health' => [
        'missing_credentials' => 'Tebex project-ID of geheime sleutel ontbreekt.',
        'missing_webhook' => 'Tebex webhookgeheim ontbreekt.',
        'ok' => 'Tebex is geconfigureerd. Webhook: :webhook',
    ],
];
