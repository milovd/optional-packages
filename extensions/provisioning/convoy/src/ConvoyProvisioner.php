<?php

declare(strict_types=1);

namespace Agovena\Extensions\Convoy;

use Agovena\Modules\Provisioning\Support\AbstractServerProvisioner;
use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Provisioning\ServiceInstanceInfo;

final class ConvoyProvisioner extends AbstractServerProvisioner
{
    public function __construct(ExtensionSettingsRepository $settings, ConvoyApi $api)
    {
        parent::__construct($settings, $api);
    }

    public function id(): string
    {
        return 'convoy';
    }

    public function label(): string
    {
        return __('convoy::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'convoy::messages.settings.api_url', required: true, help: 'convoy::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'convoy::messages.settings.api_token', secret: true, required: true, help: 'convoy::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('organization_id', 'convoy::messages.settings.organization_id', required: true, help: 'convoy::messages.settings.organization_id_help'),
            new ExtensionSettingDefinition('verify_tls', 'convoy::messages.settings.verify_tls', type: 'boolean', default: true, help: 'convoy::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'convoy::messages.settings.timeout', default: '20', help: 'convoy::messages.settings.timeout_help'),
        ];
    }

    /** @return list<ExtensionSettingDefinition> */
    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('template_id', 'convoy::messages.product.template_id', help: 'convoy::messages.product.template_id_help'),
            new ExtensionSettingDefinition('region', 'convoy::messages.product.region', help: 'convoy::messages.product.region_help'),
            new ExtensionSettingDefinition('plan', 'convoy::messages.product.plan', help: 'convoy::messages.product.plan_help'),
        ];
    }

    /** @return list<string> */
    protected function requiredConnectionKeys(): array
    {
        return ['api_url', 'api_token', 'organization_id'];
    }

    /** @param array<string, mixed> $providerSettings @return array<string, mixed> */
    protected function buildCreatePayload(ServiceInstanceInfo $instance, array $providerSettings, string $externalId): array
    {
        return [
            'external_id' => $externalId,
            'name' => $instance->label,
            'template_id' => $providerSettings['template_id'] ?? null,
            'region' => $providerSettings['region'] ?? null,
            'plan' => $providerSettings['plan'] ?? null,
        ];
    }
}
