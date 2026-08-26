<?php

declare(strict_types=1);

namespace Agovena\Extensions\DirectAdmin;

use Agovena\Modules\Provisioning\Support\AbstractServerProvisioner;
use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Provisioning\ServiceInstanceInfo;

final class DirectAdminProvisioner extends AbstractServerProvisioner
{
    public function __construct(ExtensionSettingsRepository $settings, DirectAdminApi $api)
    {
        parent::__construct($settings, $api);
    }

    public function id(): string
    {
        return 'directadmin';
    }

    public function label(): string
    {
        return __('directadmin::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'directadmin::messages.settings.api_url', required: true, help: 'directadmin::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'directadmin::messages.settings.api_token', secret: true, required: true, help: 'directadmin::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('api_username', 'directadmin::messages.settings.api_username', required: true, help: 'directadmin::messages.settings.api_username_help'),
            new ExtensionSettingDefinition('verify_tls', 'directadmin::messages.settings.verify_tls', type: 'boolean', default: true, help: 'directadmin::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'directadmin::messages.settings.timeout', default: '20', help: 'directadmin::messages.settings.timeout_help'),
        ];
    }

    /** @return list<ExtensionSettingDefinition> */
    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('domain', 'directadmin::messages.product.domain', help: 'directadmin::messages.product.domain_help'),
            new ExtensionSettingDefinition('package', 'directadmin::messages.product.package', help: 'directadmin::messages.product.package_help'),
            new ExtensionSettingDefinition('username', 'directadmin::messages.product.username', help: 'directadmin::messages.product.username_help'),
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
