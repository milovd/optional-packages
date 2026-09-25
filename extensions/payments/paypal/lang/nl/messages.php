<?php

declare(strict_types=1);

return [
    'gateway' => [
        'label' => 'PayPal',
    ],
    'checkout' => [
        'title' => 'PayPal-checkout',
        'loading' => 'PayPal-checkout laden...',
        'approval_received' => 'Betalingsgoedkeuring ontvangen. Je betaling wordt bevestigd...',
        'missing_client_id' => 'PayPal-checkout is niet geconfigureerd.',
        'missing_order' => 'Deze PayPal-checkout is niet meer beschikbaar.',
        'javascript_required' => 'JavaScript is vereist om PayPal-checkout te openen.',
        'error' => 'PayPal-checkout kon niet worden geladen. Probeer opnieuw of kies een andere betaalmethode.',
    ],
    'settings' => [
        'client_id' => 'Client-ID',
        'client_id_help' => 'REST app client-ID uit het PayPal developer dashboard. Webhook-URL: /webhooks/payments/paypal',
        'client_secret' => 'Client secret',
        'client_secret_help' => 'REST app secret. Versleuteld opgeslagen. Laat leeg na opslaan om het opgeslagen secret te behouden.',
        'webhook_id' => 'Webhook-ID',
        'webhook_id_help' => 'Webhook-ID uit het PayPal developer dashboard. Vereist voor handtekeningverificatie.',
        'sandbox' => 'Sandboxmodus',
        'sandbox_help' => 'Gebruik PayPal Sandbox endpoints voor verificatie. Schakel dit uit voor live betalingen.',
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
        'recurring_unavailable' => 'Automatische verlenging is niet beschikbaar omdat er geen actieve herbruikbare PayPal-autorisatie is.',
        'recurring_failed' => 'De automatische PayPal-betaling kon niet worden voltooid.',
        'cancel_unsupported' => 'Deze betaling kan niet bij de provider worden geannuleerd.',
        'refund_failed' => 'De terugbetaling kon niet worden verwerkt.',
        'unknown_outcome' => 'PayPal gaf een onbekende uitkomst terug. Reconciliatie is vereist.',
        'webhook_invalid' => 'De PayPal webhook payload was ongeldig.',
        'malformed' => 'PayPal gaf een onverwacht antwoord terug.',
        'provider_failed' => 'PayPal heeft het verzoek geweigerd.',
    ],
];
