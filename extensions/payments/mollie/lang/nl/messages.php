<?php

declare(strict_types=1);

return [
    'gateway' => [
        'label' => 'Mollie',
    ],
    'settings' => [
        'api_key' => 'API-sleutel',
        'api_key_help' => 'Gebruik een test_ of live_ sleutel uit het Mollie-dashboard. Laat leeg na opslaan om de opgeslagen sleutel te behouden. Override: AGOVENA_EXT_MOLLIE_API_KEY. Webhook: /webhooks/payments/mollie',
        'enabled_methods' => 'Ingeschakelde betaalmethoden',
        'enabled_methods_help' => 'Optionele kommagescheiden Mollie-methode-ids (ideal, bancontact, creditcard, paypal). Leeg laten om alle methoden op het Mollie-profiel aan te bieden.',
    ],
    'methods' => [
        'ideal' => 'iDEAL',
        'bancontact' => 'Bancontact',
        'creditcard' => 'Kaart',
        'paypal' => 'PayPal',
        'applepay' => 'Apple Pay',
        'klarna' => 'Klarna',
        'banktransfer' => 'Overschrijving',
        'sofort' => 'Sofort',
        'giropay' => 'Giropay',
        'eps' => 'EPS',
        'przelewy24' => 'Przelewy24',
        'kbc' => 'KBC',
        'belfius' => 'Belfius',
        'in3' => 'in3',
        'twint' => 'TWINT',
        'blik' => 'BLIK',
    ],
    'health' => [
        'ok' => 'Verbonden (:mode). Methoden: :methods. Webhook: :webhook',
        'missing_key' => 'API-sleutel is niet geconfigureerd.',
        'invalid_key' => 'API-sleutel moet beginnen met test_ of live_.',
        'unreachable' => 'Mollie is niet bereikbaar. Controleer de API-sleutel en het netwerk.',
    ],
    'errors' => [
        'not_configured' => 'Mollie is niet geconfigureerd.',
        'unauthorized' => 'Mollie heeft de API-gegevens geweigerd.',
        'server_error' => 'Mollie gaf een tijdelijke serverfout terug.',
        'create_failed' => 'De betaling kon niet worden gestart.',
        'cancel_unsupported' => 'Deze betaling kan bij de provider niet worden geannuleerd.',
        'refund_failed' => 'De terugbetaling kon niet worden verwerkt.',
        'sync_failed' => 'Betalingsstatus kon niet worden vernieuwd.',
        'recurring_unavailable' => 'Er is geen herbruikbaar betaalmandaat voor deze klant.',
    ],
];
