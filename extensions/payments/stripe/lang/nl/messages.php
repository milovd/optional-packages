<?php

declare(strict_types=1);

return [
    'gateway' => [
        'label' => 'Stripe',
    ],
    'settings' => [
        'secret_key' => 'Geheime sleutel',
        'secret_key_help' => 'Gebruik een sk_test_ of sk_live_ sleutel uit het Stripe-dashboard. Laat leeg na opslaan om de opgeslagen sleutel te houden. Override: AGOVENA_EXT_STRIPE_SECRET_KEY. Verzamel geen kaartgegevens op deze server.',
        'webhook_secret' => 'Webhook-ondertekeningsgeheim',
        'webhook_secret_help' => 'whsec_ geheim voor Stripe-Signature-verificatie. Webhook-URL: /webhooks/payments/stripe. Override: AGOVENA_EXT_STRIPE_WEBHOOK_SECRET.',
        'enabled_methods' => 'Ingeschakelde Checkout-methoden',
        'enabled_methods_help' => 'Optionele kommagescheiden Stripe-betaalmethoden (card, ideal, bancontact). Laat leeg om Stripe Checkout automatische methoden te laten tonen.',
    ],
    'methods' => [
        'card' => 'Kaart',
        'ideal' => 'iDEAL',
        'bancontact' => 'Bancontact',
        'klarna' => 'Klarna',
        'paypal' => 'PayPal',
        'sepa_debit' => 'SEPA-incasso',
    ],
    'health' => [
        'ok' => 'Verbonden (:mode). Webhook: :webhook',
        'missing_key' => 'Geheime sleutel is niet geconfigureerd.',
        'invalid_key' => 'Geheime sleutel moet beginnen met sk_test_ of sk_live_.',
        'missing_webhook' => 'Webhook-ondertekeningsgeheim is niet geconfigureerd.',
        'unreachable' => 'Stripe is niet bereikbaar. Controleer de geheime sleutel en het netwerk.',
    ],
    'errors' => [
        'not_configured' => 'Stripe is niet geconfigureerd.',
        'unauthorized' => 'Stripe heeft de API-gegevens geweigerd.',
        'server_error' => 'Stripe gaf een tijdelijke serverfout terug.',
        'create_failed' => 'De betaling kon niet worden gestart.',
        'cancel_unsupported' => 'Deze betaling kan bij de provider niet worden geannuleerd.',
        'refund_failed' => 'De terugbetaling kon niet worden verwerkt.',
        'sync_failed' => 'Betalingsstatus kon niet worden vernieuwd.',
        'recurring_unavailable' => 'Er is geen herbruikbare betaalautorisatie voor deze klant.',
        'webhook_invalid' => 'De Stripe-webhookhandtekening is ongeldig.',
    ],
];
