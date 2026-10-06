<?php

declare(strict_types=1);

return [
    'name' => 'VirtFusion',
    'settings' => [
        'api_url' => 'Panel URL',
        'api_url_help' => 'VirtFusion panel address as scheme://host[:port] without a path, for example https://cp.example.com. Agovena calls /api/v1 on it.',
        'api_token' => 'API token',
        'api_token_help' => 'A VirtFusion API token. It is encrypted at rest and never shown again.',
        'verify_tls' => 'Verify TLS',
        'verify_tls_help' => 'Keep enabled. Production refuses unverified TLS.',
        'timeout' => 'Request timeout (seconds)',
        'timeout_help' => 'Maximum time for one VirtFusion API request.',
    ],
    'product' => [
        'package_id' => 'Package ID',
        'package_id_help' => 'ID of an enabled VirtFusion package. Creation is refused when it is missing or disabled.',
        'hypervisor_group_id' => 'Hypervisor group ID',
        'hypervisor_group_id_help' => 'ID of the VirtFusion hypervisor group that hosts new servers.',
        'template_id' => 'Operating system template ID',
        'template_id_help' => 'ID of the VirtFusion operating system template installed by the build.',
    ],
    'panel' => [
        'title' => 'Server',
        'status' => 'Status',
        'server_id' => 'Server ID',
        'package' => 'Package ID',
        'name' => 'Server name',
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
        'not_provisioned' => 'No VirtFusion server created by Agovena is recorded for this service.',
        'invalid_mapping' => 'The provider connection, product IDs or customer email address are invalid.',
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
        'package_unavailable' => 'The VirtFusion package does not exist or is disabled.',
        'claimed' => 'This VirtFusion server is already claimed by another service.',
        'conflict' => 'The VirtFusion server record changed during this action. Reload and try again.',
        'unverified' => 'The VirtFusion server could not be verified. Manual review is required.',
        'terminated' => 'The VirtFusion server has been terminated.',
        'endpoint_changed' => 'The configured VirtFusion panel no longer matches the panel that holds this server.',
        'build_failed' => 'VirtFusion reports that the server build failed. Manual review is required.',
    ],
];
