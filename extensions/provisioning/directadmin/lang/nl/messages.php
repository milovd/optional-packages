<?php

declare(strict_types=1);

return [
    'name' => 'DirectAdmin',
    'settings' => [
        'api_url' => 'API-URL',
        'api_url_help' => 'DirectAdmin-paneeladres, bijvoorbeeld https://server.example.com:2222. Zonder pad of inloggegevens.',
        'api_username' => 'API-gebruikersnaam',
        'api_username_help' => 'Reseller- of adminaccount dat eigenaar wordt van de aangemaakte gebruikers. Gebruik reseller|gebruiker alleen voor impersonatie.',
        'api_token' => 'Login key',
        'api_token_help' => 'Aparte DirectAdmin Login Key voor dit account. Versleuteld opgeslagen en nooit opnieuw getoond.',
        'ip' => 'IP-adres voor accounts',
        'ip_help' => 'Vrij of gedeeld IP-adres dat DirectAdmin aan nieuwe gebruikers toewijst.',
        'verify_tls' => 'TLS verifiëren',
        'verify_tls_help' => 'Laat dit aan. Uitschakelen mag alleen bij lokale ontwikkeling.',
        'timeout' => 'Request-timeout (seconden)',
        'timeout_help' => 'Maximale wachttijd voor een DirectAdmin-request.',
    ],
    'product' => [
        'package' => 'Gebruikerspakket',
        'package_help' => 'Bestaand DirectAdmin-gebruikerspakket van het API-account.',
        'domain' => 'Domein',
        'domain_help' => 'Hoofddomein van de aangemaakte DirectAdmin-gebruiker.',
    ],
    'panel' => [
        'title' => 'Hostingaccount',
        'status' => 'Status',
        'login_url' => 'Inlog-URL',
        'username' => 'Gebruikersnaam',
        'domain' => 'Domein',
    ],
    'health' => [
        'connected' => 'Verbinding geverifieerd',
        'not_configured' => 'Providerverbinding is niet geconfigureerd.',
    ],
    'status' => [
        'active' => 'Actief',
        'suspended' => 'Opgeschort',
    ],
    'errors' => [
        'not_configured' => 'Providerverbinding is niet geconfigureerd.',
        'invalid_mapping' => 'De DirectAdmin-verbindingsinstellingen zijn ongeldig.',
        'invalid_product' => 'De DirectAdmin-productinstellingen zijn onvolledig of ongeldig.',
        'not_provisioned' => 'Deze service heeft geen DirectAdmin-account dat door Agovena is aangemaakt.',
        'invalid_state' => 'Deze actie is niet mogelijk in de huidige accountstatus.',
        'identifier_claimed' => 'Deze DirectAdmin-gebruikersnaam of dit domein is al geclaimd door een andere service.',
        'not_owned' => 'Het DirectAdmin-account is niet door deze service aangemaakt en blijft ongewijzigd.',
        'endpoint_changed' => 'De DirectAdmin-server van deze service is gewijzigd. Handmatige controle is nodig.',
        'plan_unavailable' => 'Het gevraagde DirectAdmin-pakket bestaat niet.',
        'stale_claim' => 'Het accountrecord is tijdens deze actie gewijzigd. Handmatige controle is nodig.',
        'busy' => 'Er loopt nog een andere actie voor deze service.',
        'action_unavailable' => 'Deze provideractie is niet beschikbaar.',
        'unauthorized' => 'De provider heeft de gegevens geweigerd.',
        'provider_rejected' => 'DirectAdmin heeft het verzoek geweigerd.',
        'provider_failed' => 'De provider gaf een fout terug.',
        'timeout' => 'De providerrequest duurde te lang.',
        'unreachable' => 'De provider kon niet worden bereikt.',
        'malformed' => 'De provider gaf een ongeldige response terug.',
        'capacity_unsupported' => 'Deze provider heeft geen geverifieerde capaciteitscheck; checkout is daarom uitgeschakeld voor dit product.',
    ],
];
