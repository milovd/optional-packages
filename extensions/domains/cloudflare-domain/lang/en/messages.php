<?php

return [
    'settings' => [
        'account_id' => 'Cloudflare account ID',
        'account_id_help' => 'The Cloudflare account used for domain registration and DNS zones.',
        'api_token' => 'Cloudflare API token',
        'api_token_help' => 'Stored encrypted and never shown after saving. Scope it to the required Registrar and DNS permissions.',
    ],
    'health' => [
        'ok' => 'Cloudflare Registrar accepted the credentials and answered an availability check.',
        'unavailable' => 'Cloudflare Registrar is not configured or rejected the request. Check the account ID and API token.',
    ],
];
