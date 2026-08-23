<?php

declare(strict_types=1);

return [
    'carrier' => [
        'label' => 'PostNL',
    ],
    'settings' => [
        'api_key' => 'API-sleutel',
        'api_key_help' => 'Uit het PostNL Mijn PostNL-ontwikkelaarsportaal. Laat leeg na opslaan om de opgeslagen sleutel te houden. Override met AGOVENA_EXT_POSTNL_API_KEY.',
        'customer_code' => 'Klantcode',
        'customer_code_help' => 'PostNL-klantcode van vier tekens.',
        'customer_number' => 'Klantnummer',
        'customer_number_help' => 'PostNL-klantnummer voor labels.',
        'collection_location' => 'Afhaallocatie',
        'collection_location_help' => 'Optionele locatiecode bij het aanmaken van zendingen.',
        'sandbox' => 'Sandbox-API gebruiken',
        'sandbox_help' => 'Indien ingeschakeld gaan verzoeken naar api-sandbox.postnl.nl.',
        'default_product_code' => 'Standaard productcode',
        'default_product_code_help' => 'PostNL-productcode wanneer Admin een zending maakt zonder live quote (3085 is Standaard NL).',
    ],
    'services' => [
        '3085' => 'Standaardpakket NL',
        '3089' => 'Brievenbuspakje NL',
        '4946' => 'EU-pakket',
    ],
    'health' => [
        'ok' => 'Verbonden (:mode).',
        'missing_key' => 'API-sleutel is niet geconfigureerd.',
        'missing_customer' => 'Klantcode en klantnummer zijn verplicht.',
        'unreachable' => 'PostNL is niet bereikbaar. Controleer de API-sleutel en het netwerk.',
    ],
    'errors' => [
        'not_configured' => 'PostNL is niet geconfigureerd.',
        'invalid_address' => 'Het verzendadres mist een huisnummer dat PostNL vereist.',
        'unsupported_destination' => 'PostNL kan met deze service niet naar deze bestemming verzenden.',
        'rate_unavailable' => 'Er is geen live PostNL-tarief beschikbaar voor deze bestelling.',
        'create_failed' => 'De PostNL-zending kon niet worden aangemaakt.',
        'label_failed' => 'Het PostNL-label kon niet worden opgeslagen.',
        'tracking_failed' => 'PostNL-tracking kon niet worden vernieuwd.',
        'cancel_unsupported' => 'Deze PostNL-zending kan bij de vervoerder niet worden geannuleerd.',
        'timeout' => 'Het PostNL-verzoek is verlopen.',
    ],
];
