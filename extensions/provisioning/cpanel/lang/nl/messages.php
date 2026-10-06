<?php

declare(strict_types=1);

return [
    'name' => 'CPanel',
    'settings' => [
        'api_url' => 'WHM-URL',
        'api_url_help' => 'Alleen schema, host en poort van WHM, bijvoorbeeld https://server.example.com:2087. Geen pad of inloggegevens. Accounts blijven aan deze serveridentiteit gekoppeld.',
        'api_token' => 'WHM API-token',
        'api_token_help' => 'WHM API-token van de gebruiker hieronder. Versleuteld opgeslagen en nooit opnieuw getoond.',
        'verify_tls' => 'TLS verifiëren',
        'verify_tls_help' => 'Laat dit aan staan. Productie weigert niet-geverifieerde TLS.',
        'timeout' => 'Request-timeout (seconden)',
        'timeout_help' => 'Een account aanmaken kan langer duren dan andere calls. Een onderbroken aanmaak wordt bij een nieuwe poging gecontroleerd, nooit blind herhaald.',
        'api_username' => 'WHM-gebruikersnaam',
        'api_username_help' => 'root of de reseller die eigenaar is van het API-token. Aangemaakte accounts zijn eigendom van deze gebruiker.',
    ],
    'product' => [
        'package' => 'Pakket',
        'package_help' => 'Naam van een bestaand WHM-pakket dat het API-token mag toewijzen.',
    ],
    'panel' => [
        'title' => 'Hostingaccount',
        'status' => 'Status',
        'username' => 'Gebruikersnaam',
        'package' => 'Pakket',
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
        'not_provisioned' => 'Er is geen door Agovena aangemaakt cPanel-account vastgelegd voor deze service.',
        'invalid_mapping' => 'De providerverbinding of het productpakket is ongeldig.',
        'action_unavailable' => 'Deze provideractie is niet beschikbaar.',
        'unauthorized' => 'De provider heeft de gegevens geweigerd.',
        'not_found' => 'De providerresource is niet gevonden.',
        'provider_failed' => 'De provider gaf een fout terug.',
        'timeout' => 'De providerrequest duurde te lang.',
        'unreachable' => 'De provider kon niet worden bereikt.',
        'malformed' => 'De provider gaf een ongeldige response terug.',
        'rejected' => 'WHM heeft het verzoek geweigerd.',
        'capacity_unsupported' => 'Deze provider heeft geen geverifieerde capaciteitscheck; checkout is daarom uitgeschakeld voor dit product.',
        'demo_disabled' => 'Providercalls zijn uitgeschakeld in de demo-omgeving.',
        'package_unavailable' => 'Het pakket bestaat niet op de server of het API-token mag het niet toewijzen.',
        'claimed' => 'Dit cPanel-account is al geclaimd door een andere service.',
        'conflict' => 'Het cPanel-account is tijdens deze actie gewijzigd. Laad opnieuw en probeer het nog eens.',
        'unverified' => 'Het cPanel-account kon niet worden geverifieerd. Handmatige controle is nodig.',
        'terminated' => 'Het cPanel-account is beëindigd.',
        'endpoint_changed' => 'De ingestelde WHM-server komt niet meer overeen met de server waarop dit account staat.',
    ],
];
