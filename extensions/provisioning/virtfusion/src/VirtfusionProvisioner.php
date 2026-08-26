<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtfusion;

use Agovena\Modules\Provisioning\Support\AbstractServerProvisioner;
use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Provisioning\ServiceInstanceInfo;

final class VirtfusionProvisioner extends AbstractServerProvisioner
{
    public function __construct(ExtensionSettingsRepository $settings, VirtfusionApi $api)
    {
        parent::__construct($settings, $api);
    }

    public function id(): string
    {
        return 'virtfusion';
    }

    public function label(): string
    {
        return __('virtfusion::messages.name');
    }

    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'virtfusion::messages.settings.api_url', required: true, help: 'virtfusion::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'virtfusion::messages.settings.api_token', secret: true, required: true, help: 'virtfusion::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('verify_tls', 'virtfusion::messages.settings.verify_tls', type: 'boolean', default: true, help: 'virtfusion::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'virtfusion::messages.settings.timeout', default: '20', help: 'virtfusion::messages.settings.timeout_help'),
        ];
    }

    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('plan_id', 'virtfusion::messages.product.plan_id', help: 'virtfusion::messages.product.plan_id_help'),
            new ExtensionSettingDefinition('template_id', 'virtfusion::messages.product.template_id', help: 'virtfusion::messages.product.template_id_help'),
            new ExtensionSettingDefinition('location_id', 'virtfusion::messages.product.location_id', help: 'virtfusion::messages.product.location_id_help'),
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
            'plan_id' => $providerSettings['plan_id'] ?? null,
            'template_id' => $providerSettings['template_id'] ?? null,
            'location_id' => $providerSettings['location_id'] ?? null,
        ];
    }
}
