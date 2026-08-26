<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtualizor;

use Agovena\Modules\Provisioning\Support\AbstractServerProvisioner;
use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Provisioning\ServiceInstanceInfo;

final class VirtualizorProvisioner extends AbstractServerProvisioner
{
    public function __construct(ExtensionSettingsRepository $settings, VirtualizorApi $api)
    {
        parent::__construct($settings, $api);
    }

    public function id(): string
    {
        return 'virtualizor';
    }

    public function label(): string
    {
        return __('virtualizor::messages.name');
    }

    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'virtualizor::messages.settings.api_url', required: true, help: 'virtualizor::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'virtualizor::messages.settings.api_token', secret: true, required: true, help: 'virtualizor::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('api_secret', 'virtualizor::messages.settings.api_secret', secret: true, required: true, help: 'virtualizor::messages.settings.api_secret_help'),
            new ExtensionSettingDefinition('verify_tls', 'virtualizor::messages.settings.verify_tls', type: 'boolean', default: true, help: 'virtualizor::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'virtualizor::messages.settings.timeout', default: '20', help: 'virtualizor::messages.settings.timeout_help'),
        ];
    }

    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('plan_id', 'virtualizor::messages.product.plan_id', help: 'virtualizor::messages.product.plan_id_help'),
            new ExtensionSettingDefinition('location', 'virtualizor::messages.product.location', help: 'virtualizor::messages.product.location_help'),
        ];
    }

    protected function requiredConnectionKeys(): array
    {
        return ['api_url', 'api_token', 'api_secret'];
    }

    protected function buildCreatePayload(ServiceInstanceInfo $instance, array $providerSettings, string $externalId): array
    {
        return [
            'external_id' => $externalId,
            'name' => $instance->label,
            'plan_id' => $providerSettings['plan_id'] ?? null,
            'location' => $providerSettings['location'] ?? null,
        ];
    }
}
