<?php

declare(strict_types=1);

return [
    'name' => 'Enhance',
    'settings' => [
        'api_url' => 'API-URL',
        'api_url_help' => 'Configureer de providerverbinding. Geheimen worden versleuteld opgeslagen en nooit opnieuw getoond.',
        'api_token' => 'API-token',
        'api_token_help' => 'Configureer de providerverbinding. Geheimen worden versleuteld opgeslagen en nooit opnieuw getoond.',
        'verify_tls' => 'TLS verifiëren',
        'verify_tls_help' => 'Configureer de providerverbinding. Geheimen worden versleuteld opgeslagen en nooit opnieuw getoond.',
        'timeout' => 'Request-timeout (seconden)',
        'timeout_help' => 'Configureer de providerverbinding. Geheimen worden versleuteld opgeslagen en nooit opnieuw getoond.',
        'account_id' => 'Account-id',
        'account_id_help' => 'Configureer de providerverbinding. Geheimen worden versleuteld opgeslagen en nooit opnieuw getoond.',
    ],
    'product' => [
        'domain' => 'Domein',
        'domain_help' => 'Providerkoppeling voor dit product.',
        'plan' => 'Plan',
        'plan_help' => 'Providerkoppeling voor dit product.',
    ],
    'actions' => [
        'start' => 'Starten',
        'stop' => 'Stoppen',
        'restart' => 'Herstarten',
    ],
    'panel' => [
        'title' => 'Server',
        'status' => 'Status',
        'external_ref' => 'Externe referentie',
        'management_url' => 'Beheer-URL',
    ],
    'health' => [
        'connected' => 'Verbinding geverifieerd',
        'not_configured' => 'Providerverbinding is niet geconfigureerd.',
    ],
    'status' => [
        'unknown' => 'Onbekend',
        'active' => 'Actief',
        'provisioning' => 'Provisioning',
        'suspended' => 'Opgeschort',
        'terminated' => 'Beëindigd',
        'failed' => 'Mislukt',
    ],
    'errors' => [
        'not_configured' => 'Providerverbinding is niet geconfigureerd.',
        'not_provisioned' => 'De service heeft nog geen providerkoppeling.',
        'action_unavailable' => 'Deze provideractie is niet beschikbaar.',
        'unauthorized' => 'De provider heeft de gegevens geweigerd.',
        'not_found' => 'De providerresource is niet gevonden.',
        'provider_failed' => 'De provider gaf een fout terug.',
        'timeout' => 'De providerrequest duurde te lang.',
        'unreachable' => 'De provider kon niet worden bereikt.',
        'malformed' => 'De provider gaf een ongeldige response terug.',
        'capacity_unsupported' => 'Deze provider heeft geen geverifieerde capaciteitscheck; checkout is daarom uitgeschakeld voor dit product.',
        'out_of_stock' => 'Dit product is tijdelijk niet beschikbaar omdat de gevraagde capaciteit vol zit.',
    ],
];
