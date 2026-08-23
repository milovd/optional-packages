<?php

declare(strict_types=1);

return [
    'carrier' => [
        'label' => 'PostNL',
    ],
    'settings' => [
        'api_key' => 'API key',
        'api_key_help' => 'From the PostNL Mijn PostNL developer portal. Leave blank after saving to keep the stored key. Override with AGOVENA_EXT_POSTNL_API_KEY.',
        'customer_code' => 'Customer code',
        'customer_code_help' => 'Four-character PostNL customer code.',
        'customer_number' => 'Customer number',
        'customer_number_help' => 'PostNL customer number for labelling.',
        'collection_location' => 'Collection location',
        'collection_location_help' => 'Optional location code used when creating shipments.',
        'sandbox' => 'Use sandbox API',
        'sandbox_help' => 'When enabled, requests go to api-sandbox.postnl.nl.',
        'default_product_code' => 'Default product code',
        'default_product_code_help' => 'PostNL product code used when Admin creates a shipment without a live quote (3085 is Standard NL).',
    ],
    'services' => [
        '3085' => 'Standard parcel NL',
        '3089' => 'Mailbox packet NL',
        '4946' => 'EU parcel',
    ],
    'health' => [
        'ok' => 'Connected (:mode).',
        'missing_key' => 'API key is not configured.',
        'missing_customer' => 'Customer code and number are required.',
        'unreachable' => 'Could not reach PostNL. Check the API key and network.',
    ],
    'errors' => [
        'not_configured' => 'PostNL is not configured.',
        'invalid_address' => 'The shipping address is missing a street number that PostNL requires.',
        'unsupported_destination' => 'PostNL cannot ship to this destination with the selected service.',
        'rate_unavailable' => 'A live PostNL rate is not available for this order.',
        'create_failed' => 'The PostNL shipment could not be created.',
        'label_failed' => 'The PostNL label could not be stored.',
        'tracking_failed' => 'PostNL tracking could not be refreshed.',
        'cancel_unsupported' => 'This PostNL shipment cannot be cancelled at the provider.',
        'timeout' => 'The PostNL request timed out.',
    ],
];
