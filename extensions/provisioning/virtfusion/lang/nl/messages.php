<?php

declare(strict_types=1);

return [
    'name' => 'VirtFusion',
    'settings' => [
        'api_url' => 'Panel-URL',
        'api_url_help' => 'Adres van het VirtFusion-panel als scheme://host[:poort] zonder pad, bijvoorbeeld https://cp.example.com. Agovena roept daarop /api/v1 aan.',
        'api_token' => 'API-token',
        'api_token_help' => 'Een VirtFusion API-token. Het wordt versleuteld opgeslagen en nooit opnieuw getoond.',
        'verify_tls' => 'TLS verifiëren',
        'verify_tls_help' => 'Laat dit aan. Productie weigert ongeverifieerde TLS.',
        'timeout' => 'Request-timeout (seconden)',
        'timeout_help' => 'Maximale duur van één VirtFusion API-request.',
    ],
    'product' => [
        'package_id' => 'Pakket-ID',
        'package_id_help' => 'ID van een ingeschakeld VirtFusion-pakket. Aanmaken wordt geweigerd als het ontbreekt of uitgeschakeld is.',
        'hypervisor_group_id' => 'Hypervisorgroep-ID',
        'hypervisor_group_id_help' => 'ID van de VirtFusion-hypervisorgroep waarop nieuwe servers komen.',
        'template_id' => 'Besturingssysteemtemplate-ID',
        'template_id_help' => 'ID van de VirtFusion-besturingssysteemtemplate die de build installeert.',
    ],
    'panel' => [
        'title' => 'Server',
        'status' => 'Status',
        'server_id' => 'Server-ID',
        'package' => 'Pakket-ID',
        'name' => 'Servernaam',
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
        'not_provisioned' => 'Voor deze service is geen door Agovena aangemaakte VirtFusion-server vastgelegd.',
        'invalid_mapping' => 'De providerverbinding, product-ID\'s of het e-mailadres van de klant zijn ongeldig.',
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
        'package_unavailable' => 'Het VirtFusion-pakket bestaat niet of is uitgeschakeld.',
        'claimed' => 'Deze VirtFusion-server is al door een andere service geclaimd.',
        'conflict' => 'Het VirtFusion-serverrecord is tijdens deze actie gewijzigd. Laad opnieuw en probeer het nog eens.',
        'unverified' => 'De VirtFusion-server kon niet worden geverifieerd. Handmatige controle is nodig.',
        'terminated' => 'De VirtFusion-server is beëindigd.',
        'endpoint_changed' => 'Het ingestelde VirtFusion-panel komt niet meer overeen met het panel waarop deze server staat.',
        'build_failed' => 'VirtFusion meldt dat de serverbuild is mislukt. Handmatige controle is nodig.',
    ],
];
