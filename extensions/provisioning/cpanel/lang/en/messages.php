<?php

declare(strict_types=1);

return [
    'name' => 'CPanel',
    'settings' => [
        'api_url' => 'WHM URL',
        'api_url_help' => 'Scheme, host and port of WHM only, for example https://server.example.com:2087. No path or credentials. Accounts stay bound to this server identity.',
        'api_token' => 'WHM API token',
        'api_token_help' => 'WHM API token of the user below. Encrypted at rest and never shown again.',
        'verify_tls' => 'Verify TLS',
        'verify_tls_help' => 'Keep enabled. Production refuses unverified TLS.',
        'timeout' => 'Request timeout (seconds)',
        'timeout_help' => 'Account creation can take longer than other calls. An interrupted create is reconciled on retry, never repeated blindly.',
        'api_username' => 'WHM username',
        'api_username_help' => 'root or the reseller that owns the API token. Created accounts are owned by this user.',
    ],
    'product' => [
        'package' => 'Package',
        'package_help' => 'Name of an existing WHM package that the API token may assign.',
    ],
    'panel' => [
        'title' => 'Hosting account',
        'status' => 'Status',
        'username' => 'Username',
        'package' => 'Package',
        'domain' => 'Domain',
    ],
    'health' => [
        'connected' => 'Connection verified',
        'not_configured' => 'Provider connection is not configured.',
    ],
    'status' => [
        'active' => 'Active',
        'suspended' => 'Suspended',
    ],
    'errors' => [
        'not_configured' => 'Provider connection is not configured.',
        'not_provisioned' => 'No cPanel account created by Agovena is recorded for this service.',
        'invalid_mapping' => 'The provider connection or product package is invalid.',
        'action_unavailable' => 'This provider action is unavailable.',
        'unauthorized' => 'The provider rejected the credentials.',
        'not_found' => 'The provider resource was not found.',
        'provider_failed' => 'The provider returned an error.',
        'timeout' => 'The provider request timed out.',
        'unreachable' => 'The provider could not be reached.',
        'malformed' => 'The provider returned an invalid response.',
        'rejected' => 'WHM rejected the request.',
        'capacity_unsupported' => 'This provider does not expose a verified capacity check, so checkout is disabled for this product.',
        'demo_disabled' => 'Provider calls are disabled in the demo environment.',
        'package_unavailable' => 'The package does not exist on the server or the API token may not assign it.',
        'claimed' => 'This cPanel account is already claimed by another service.',
        'conflict' => 'The cPanel account changed during this action. Reload and try again.',
        'unverified' => 'The cPanel account could not be verified. Manual review is required.',
        'terminated' => 'The cPanel account has been terminated.',
        'endpoint_changed' => 'The configured WHM server no longer matches the server that holds this account.',
    ],
];
