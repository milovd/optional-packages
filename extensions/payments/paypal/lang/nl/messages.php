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
        'sandbox' => 'Sandboxmodus',
        'sandbox_help' => 'Gebruik PayPal Sandbox endpoints voor tests. Schakel dit uit voor live betalingen.',
        'subscription_plan_id' => 'Subscription plan ID',
        'subscription_plan_id_help' => 'Bestaande actieve PayPal Billing Plan ID voor automatische subscriptions. Prijs, valuta, interval en trial van het plan moeten overeenkomen met het product.',
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
        'subscription_failed' => 'De PayPal subscription kon niet worden gestart.',
        'subscription_plan_missing' => 'Voor automatische PayPal subscriptions is een actieve subscription plan ID vereist.',
        'subscription_plan_invalid' => 'Het geconfigureerde PayPal subscription plan komt niet overeen met prijs, valuta, interval of trial van dit product.',
        'subscription_cancel_failed' => 'De PayPal subscription kon niet worden geannuleerd.',
        'subscription_resume_failed' => 'De PayPal subscription kon niet worden hervat.',
        'cancel_unsupported' => 'Deze betaling kan niet bij de provider worden geannuleerd.',
        'refund_failed' => 'De terugbetaling kon niet worden verwerkt.',
        'unknown_outcome' => 'PayPal gaf een onbekende uitkomst terug. Reconciliatie is vereist.',
        'webhook_invalid' => 'De PayPal webhook payload was ongeldig.',
        'malformed' => 'PayPal gaf een onverwacht antwoord terug.',
        'provider_failed' => 'PayPal heeft het verzoek geweigerd.',
    ],
];
