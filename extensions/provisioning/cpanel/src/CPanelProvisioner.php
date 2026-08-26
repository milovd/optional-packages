<?php

declare(strict_types=1);

namespace Agovena\Extensions\CPanel;

use Agovena\Modules\Provisioning\Support\AbstractServerProvisioner;
use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Provisioning\ServiceInstanceInfo;

final class CPanelProvisioner extends AbstractServerProvisioner
{
    public function __construct(ExtensionSettingsRepository $settings, CPanelApi $api)
    {
        parent::__construct($settings, $api);
    }

    public function id(): string
    {
        return 'cpanel';
    }

    public function label(): string
    {
        return __('cpanel::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'cpanel::messages.settings.api_url', required: true, help: 'cpanel::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'cpanel::messages.settings.api_token', secret: true, required: true, help: 'cpanel::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('api_username', 'cpanel::messages.settings.api_username', required: true, help: 'cpanel::messages.settings.api_username_help'),
            new ExtensionSettingDefinition('verify_tls', 'cpanel::messages.settings.verify_tls', type: 'boolean', default: true, help: 'cpanel::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'cpanel::messages.settings.timeout', default: '20', help: 'cpanel::messages.settings.timeout_help'),
        ];
    }

    /** @return list<ExtensionSettingDefinition> */
    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('domain', 'cpanel::messages.product.domain', help: 'cpanel::messages.product.domain_help'),
            new ExtensionSettingDefinition('package', 'cpanel::messages.product.package', help: 'cpanel::messages.product.package_help'),
            new ExtensionSettingDefinition('username', 'cpanel::messages.product.username', help: 'cpanel::messages.product.username_help'),
        ];
    }

    /** @return list<string> */
    protected function requiredConnectionKeys(): array
    {
        return ['api_url', 'api_token', 'api_username'];
    }

    /** @param array<string, mixed> $providerSettings @return array<string, mixed> */
    protected function buildCreatePayload(ServiceInstanceInfo $instance, array $providerSettings, string $externalId): array
    {
        return [
            'external_id' => $externalId,
            'name' => $instance->label,
            'domain' => $providerSettings['domain'] ?? null,
            'package' => $providerSettings['package'] ?? null,
            'username' => $providerSettings['username'] ?? null,
        ];
    }
}
