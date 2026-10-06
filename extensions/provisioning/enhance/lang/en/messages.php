<?php

declare(strict_types=1);

return [
    'name' => 'Enhance',
    'settings' => [
        'api_url' => 'Control panel URL',
        'api_url_help' => 'Enhance control panel URL as scheme://host[:port] without a path, for example https://cp.example.com. Agovena calls its /api endpoints.',
        'api_token' => 'Access token',
        'api_token_help' => 'Enhance access token with the Super Admin role of the reseller organization. Encrypted at rest and never shown again.',
        'verify_tls' => 'Verify TLS',
        'verify_tls_help' => 'Keep enabled. Production refuses unverified TLS and plain HTTP.',
        'timeout' => 'Request timeout (seconds)',
        'timeout_help' => 'Maximum time for one Enhance API request.',
        'account_id' => 'Reseller organization id',
        'account_id_help' => 'UUID of the Enhance organization that owns the plans and the customers Agovena creates (shown as Org ID on the Access Tokens page).',
    ],
    'product' => [
        'domain' => 'Domain',
        'domain_help' => 'Domain of the website Agovena creates in the new subscription. One service per domain and control panel.',
        'plan' => 'Plan id',
        'plan_help' => 'Numeric id of a plan the reseller organization offers. Creating and plan changes are refused when it does not exist.',
    ],
    'panel' => [
        'title' => 'Hosting',
        'status' => 'Status',
        'domain' => 'Domain',
        'plan' => 'Plan id',
        'subscription_id' => 'Subscription id',
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
        'not_provisioned' => 'No Enhance subscription created by Agovena is recorded for this service.',
        'invalid_mapping' => 'The provider connection, reseller organization id, plan id or domain is invalid.',
        'action_unavailable' => 'This provider action is unavailable.',
        'unauthorized' => 'The provider rejected the credentials.',
        'not_found' => 'The provider resource was not found.',
        'provider_failed' => 'The provider returned an error.',
        'timeout' => 'The provider request timed out.',
        'unreachable' => 'The provider could not be reached.',
        'malformed' => 'The provider returned an invalid response.',
        'capacity_unsupported' => 'This provider does not expose a verified capacity check, so checkout is disabled for this product.',
        'out_of_stock' => 'This product is temporarily unavailable because the requested capacity is full.',
        'demo_disabled' => 'Provider calls are disabled in the demo environment.',
        'plan_unavailable' => 'The reseller organization does not offer this Enhance plan.',
        'claimed' => 'This domain or Enhance subscription is already claimed by another service.',
        'conflict' => 'The Enhance record changed during this action. Reload and try again.',
        'unverified' => 'The Enhance subscription could not be verified. Manual review is required.',
        'terminated' => 'The Enhance subscription has been terminated.',
        'endpoint_changed' => 'The configured Enhance control panel no longer matches the panel that holds this subscription.',
    ],
];
