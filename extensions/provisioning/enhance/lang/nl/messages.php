<?php

declare(strict_types=1);

return [
    'name' => 'Enhance',
    'settings' => [
        'api_url' => 'URL van het controlepaneel',
        'api_url_help' => 'URL van het Enhance-controlepaneel als scheme://host[:poort] zonder pad, bijvoorbeeld https://cp.example.com. Agovena roept de /api-endpoints aan.',
        'api_token' => 'Toegangstoken',
        'api_token_help' => 'Enhance-toegangstoken met de rol Super Admin van de reseller-organisatie. Wordt versleuteld opgeslagen en nooit opnieuw getoond.',
        'verify_tls' => 'TLS verifiëren',
        'verify_tls_help' => 'Laat dit aan. Productie weigert ongeverifieerde TLS en gewone HTTP.',
        'timeout' => 'Request-timeout (seconden)',
        'timeout_help' => 'Maximale duur van één Enhance-API-request.',
        'account_id' => 'ID van de reseller-organisatie',
        'account_id_help' => 'UUID van de Enhance-organisatie die de plannen en de door Agovena aangemaakte klanten bezit (te zien als Org ID op de pagina Access Tokens).',
    ],
    'product' => [
        'domain' => 'Domein',
        'domain_help' => 'Domein van de website die Agovena in het nieuwe abonnement aanmaakt. Eén service per domein en controlepaneel.',
        'plan' => 'Plan-ID',
        'plan_help' => 'Numerieke ID van een plan dat de reseller-organisatie aanbiedt. Aanmaken en planwijzigingen worden geweigerd als het niet bestaat.',
    ],
    'panel' => [
        'title' => 'Hosting',
        'status' => 'Status',
        'domain' => 'Domein',
        'plan' => 'Plan-ID',
        'subscription_id' => 'Abonnements-ID',
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
        'not_provisioned' => 'Voor deze service is geen door Agovena aangemaakt Enhance-abonnement vastgelegd.',
        'invalid_mapping' => 'De providerverbinding, de ID van de reseller-organisatie, de plan-ID of het domein is ongeldig.',
        'action_unavailable' => 'Deze provideractie is niet beschikbaar.',
        'unauthorized' => 'De provider heeft de gegevens geweigerd.',
        'not_found' => 'De providerresource is niet gevonden.',
        'provider_failed' => 'De provider gaf een fout terug.',
        'timeout' => 'De providerrequest duurde te lang.',
        'unreachable' => 'De provider kon niet worden bereikt.',
        'malformed' => 'De provider gaf een ongeldige response terug.',
        'capacity_unsupported' => 'Deze provider heeft geen geverifieerde capaciteitscheck; checkout is daarom uitgeschakeld voor dit product.',
        'out_of_stock' => 'Dit product is tijdelijk niet beschikbaar omdat de gevraagde capaciteit vol zit.',
        'demo_disabled' => 'Provideraanroepen zijn uitgeschakeld in de demo-omgeving.',
        'plan_unavailable' => 'De reseller-organisatie biedt dit Enhance-plan niet aan.',
        'claimed' => 'Dit domein of Enhance-abonnement is al door een andere service geclaimd.',
        'conflict' => 'Het Enhance-record is tijdens deze actie gewijzigd. Laad opnieuw en probeer het nog eens.',
        'unverified' => 'Het Enhance-abonnement kon niet worden geverifieerd. Handmatige controle is nodig.',
        'terminated' => 'Het Enhance-abonnement is beëindigd.',
        'endpoint_changed' => 'Het ingestelde Enhance-controlepaneel komt niet meer overeen met het paneel waarop dit abonnement staat.',
    ],
];
