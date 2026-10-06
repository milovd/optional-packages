<?php

declare(strict_types=1);

return [
    'name' => 'DirectAdmin',
    'settings' => [
        'api_url' => 'API URL',
        'api_url_help' => 'DirectAdmin panel address, for example https://server.example.com:2222. No path or credentials.',
        'api_username' => 'API username',
        'api_username_help' => 'Reseller or admin account that owns the created users. Use reseller|user only for impersonation.',
        'api_token' => 'Login key',
        'api_token_help' => 'Dedicated DirectAdmin Login Key for this account. Encrypted at rest and never shown again.',
        'ip' => 'Account IP address',
        'ip_help' => 'Free or shared IP address that DirectAdmin assigns to new users.',
        'verify_tls' => 'Verify TLS',
        'verify_tls_help' => 'Keep enabled. Disabling is only allowed in local development.',
        'timeout' => 'Request timeout (seconds)',
        'timeout_help' => 'Maximum time to wait for one DirectAdmin request.',
    ],
    'product' => [
        'package' => 'User package',
        'package_help' => 'Existing DirectAdmin user package of the API account.',
        'domain' => 'Domain',
        'domain_help' => 'Main domain of the created DirectAdmin user.',
    ],
    'panel' => [
        'title' => 'Hosting account',
        'status' => 'Status',
        'login_url' => 'Login URL',
        'username' => 'Username',
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
        'invalid_mapping' => 'The DirectAdmin connection settings are invalid.',
        'invalid_product' => 'The DirectAdmin product settings are incomplete or invalid.',
        'not_provisioned' => 'This service has no DirectAdmin account created by Agovena.',
        'invalid_state' => 'This action is not possible in the current account state.',
        'identifier_claimed' => 'This DirectAdmin username or domain is already claimed by another service.',
        'not_owned' => 'The DirectAdmin account was not created by this service and is left untouched.',
        'endpoint_changed' => 'The DirectAdmin server of this service has changed. Manual review is required.',
        'plan_unavailable' => 'The requested DirectAdmin package does not exist.',
        'stale_claim' => 'The account record changed during this action. Manual review is required.',
        'busy' => 'Another action for this service is still running.',
        'action_unavailable' => 'This provider action is unavailable.',
        'unauthorized' => 'The provider rejected the credentials.',
        'provider_rejected' => 'DirectAdmin refused the request.',
        'provider_failed' => 'The provider returned an error.',
        'timeout' => 'The provider request timed out.',
        'unreachable' => 'The provider could not be reached.',
        'malformed' => 'The provider returned an invalid response.',
        'capacity_unsupported' => 'This provider does not expose a verified capacity check, so checkout is disabled for this product.',
    ],
];
