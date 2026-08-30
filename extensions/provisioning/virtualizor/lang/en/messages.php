<?php

declare(strict_types=1);

return [
    'name' => 'Virtualizor',
    'settings' => [
        'api_url' => 'API URL',
        'api_url_help' => 'Configure the provider connection. Secrets are encrypted at rest and never shown again.',
        'api_token' => 'API key',
        'api_token_help' => 'Configure the provider connection. Secrets are encrypted at rest and never shown again.',
        'verify_tls' => 'Verify TLS',
        'verify_tls_help' => 'Configure the provider connection. Secrets are encrypted at rest and never shown again.',
        'timeout' => 'Request timeout (seconds)',
        'timeout_help' => 'Configure the provider connection. Secrets are encrypted at rest and never shown again.',
        'api_secret' => 'API secret',
        'api_secret_help' => 'Configure the provider connection. Secrets are encrypted at rest and never shown again.',
    ],
    'product' => [
        'plan_id' => 'Plan id',
        'plan_id_help' => 'Provider mapping used for this product.',
        'location' => 'Location',
        'location_help' => 'Provider mapping used for this product.',
    ],
    'actions' => [
        'start' => 'Start',
        'stop' => 'Stop',
        'restart' => 'Restart',
    ],
    'panel' => [
        'title' => 'Server',
        'status' => 'Status',
        'external_ref' => 'External reference',
        'management_url' => 'Management URL',
    ],
    'health' => [
        'connected' => 'Connection verified',
        'not_configured' => 'Provider connection is not configured.',
    ],
    'status' => [
        'unknown' => 'Unknown',
        'active' => 'Active',
        'provisioning' => 'Provisioning',
        'suspended' => 'Suspended',
        'terminated' => 'Terminated',
        'failed' => 'Failed',
    ],
    'errors' => [
        'not_configured' => 'Provider connection is not configured.',
        'not_provisioned' => 'The service has no provider mapping yet.',
        'action_unavailable' => 'This provider action is unavailable.',
        'unauthorized' => 'The provider rejected the credentials.',
        'not_found' => 'The provider resource was not found.',
        'provider_failed' => 'The provider returned an error.',
        'timeout' => 'The provider request timed out.',
        'unreachable' => 'The provider could not be reached.',
        'malformed' => 'The provider returned an invalid response.',
        'capacity_unsupported' => 'This provider does not expose a verified capacity check, so checkout is disabled for this product.',
        'out_of_stock' => 'This product is temporarily unavailable because the requested capacity is full.',
    ],
];
