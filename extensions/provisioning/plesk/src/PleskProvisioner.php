<?php

declare(strict_types=1);

namespace Agovena\Extensions\Plesk;

use Agovena\Modules\Provisioning\Support\AbstractServerProvisioner;
use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Provisioning\ServiceInstanceInfo;

final class PleskProvisioner extends AbstractServerProvisioner
{
    public function __construct(ExtensionSettingsRepository $settings, PleskApi $api)
    {
        parent::__construct($settings, $api);
    }

    public function id(): string
    {
        return 'plesk';
    }

    public function label(): string
    {
        return __('plesk::messages.name');
    }

    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'plesk::messages.settings.api_url', required: true, help: 'plesk::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'plesk::messages.settings.api_token', secret: true, required: true, help: 'plesk::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('verify_tls', 'plesk::messages.settings.verify_tls', type: 'boolean', default: true, help: 'plesk::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'plesk::messages.settings.timeout', default: '20', help: 'plesk::messages.settings.timeout_help'),
        ];
    }

    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('domain', 'plesk::messages.product.domain', help: 'plesk::messages.product.domain_help'),
            new ExtensionSettingDefinition('service_plan', 'plesk::messages.product.service_plan', help: 'plesk::messages.product.service_plan_help'),
        ];
    }

    protected function requiredConnectionKeys(): array
    {
        return ['api_url', 'api_token'];
    }

    protected function buildCreatePayload(ServiceInstanceInfo $instance, array $providerSettings, string $externalId): array
    {
        return [
            'external_id' => $externalId,
            'name' => $instance->label,
            'domain' => $providerSettings['domain'] ?? null,
            'service_plan' => $providerSettings['service_plan'] ?? null,
        ];
    }
}
