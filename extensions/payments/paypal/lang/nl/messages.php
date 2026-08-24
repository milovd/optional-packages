<?php

declare(strict_types=1);

return [
    'gateway' => [
        'label' => 'PayPal',
    ],
    'settings' => [
        'client_id' => 'Client-ID',
        'client_id_help' => 'REST app client-ID uit het PayPal developer dashboard. Webhook-URL: /webhooks/payments/paypal',
        'client_secret' => 'Client secret',
        'client_secret_help' => 'REST app secret. Versleuteld opgeslagen. Laat leeg na opslaan om het opgeslagen secret te behouden.',
        'webhook_id' => 'Webhook-ID',
        'webhook_id_help' => 'Webhook-ID uit het PayPal developer dashboard. Vereist voor handtekeningverificatie.',
        'sandbox' => 'Sandbox-modus',
        'sandbox_help' => 'Gebruik PayPal sandbox-endpoints voor testen. Uitschakelen voor live betalingen.',
    ],
    'health' => [
        'ok' => 'Verbonden (:mode). Webhook: :webhook',
        'missing_client_id' => 'Client-ID is niet geconfigureerd.',
        'missing_secret' => 'Client secret is niet geconfigureerd.',
        'missing_webhook' => 'Webhook-ID is niet geconfigureerd.',
        'unauthorized' => 'PayPal heeft de REST-credentials geweigerd.',
        'unreachable' => 'PayPal kon niet worden bereikt. Controleer credentials en netwerk.',
    ],
    'errors' => [
        'not_configured' => 'PayPal is niet geconfigureerd.',
        'unauthorized' => 'PayPal heeft de API-credentials geweigerd.',
        'server_error' => 'PayPal gaf een tijdelijke serverfout terug.',
        'create_failed' => 'De betaling kon niet worden gestart.',
        'cancel_unsupported' => 'Deze betaling kan niet bij de provider worden geannuleerd.',
        'refund_failed' => 'De terugbetaling kon niet worden verwerkt.',
        'webhook_invalid' => 'De PayPal webhook payload was ongeldig.',
        'malformed' => 'PayPal gaf een onverwacht antwoord terug.',
        'provider_failed' => 'PayPal heeft het verzoek geweigerd.',
    ],
];
