<?php

return [
    'settings' => [
        'cloudflare_account_id' => 'Cloudflare account ID',
        'cloudflare_account_id_help' => 'The Cloudflare account used for domain registration and DNS zones.',
        'cloudflare_api_token' => 'Cloudflare API token',
        'cloudflare_api_token_help' => 'Stored encrypted and never shown after saving. Scope it to the required Registrar and DNS permissions.',
        'namecheap_api_user' => 'Namecheap API user',
        'namecheap_api_user_help' => 'The API user allowed to access this Namecheap account.',
        'namecheap_api_key' => 'Namecheap API key',
        'namecheap_api_key_help' => 'Stored encrypted and never shown again after saving.',
        'namecheap_username' => 'Namecheap account username',
        'namecheap_username_help' => 'The Namecheap username used for API requests.',
        'namecheap_client_ip' => 'Allowlisted client IP',
        'namecheap_client_ip_help' => 'The public IP address allowlisted in Namecheap API settings.',
        'namecheap_sandbox' => 'Use Namecheap sandbox',
        'namecheap_sandbox_help' => 'Use the Namecheap sandbox endpoint until a real provider flow is approved.',
    ],
];
