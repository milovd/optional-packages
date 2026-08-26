<?php

declare(strict_types=1);

namespace Agovena\Extensions\Enhance;

use Agovena\Modules\Provisioning\Support\AbstractServerProvisioner;
use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Provisioning\ServiceInstanceInfo;

final class EnhanceProvisioner extends AbstractServerProvisioner
{
    public function __construct(ExtensionSettingsRepository $settings, EnhanceApi $api)
    {
        parent::__construct($settings, $api);
    }

    public function id(): string
    {
        return 'enhance';
    }

    public function label(): string
    {
        return __('enhance::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'enhance::messages.settings.api_url', required: true, help: 'enhance::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'enhance::messages.settings.api_token', secret: true, required: true, help: 'enhance::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('account_id', 'enhance::messages.settings.account_id', required: true, help: 'enhance::messages.settings.account_id_help'),
            new ExtensionSettingDefinition('verify_tls', 'enhance::messages.settings.verify_tls', type: 'boolean', default: true, help: 'enhance::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'enhance::messages.settings.timeout', default: '20', help: 'enhance::messages.settings.timeout_help'),
        ];
    }

    /** @return list<ExtensionSettingDefinition> */
    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('domain', 'enhance::messages.product.domain', help: 'enhance::messages.product.domain_help'),
            new ExtensionSettingDefinition('plan', 'enhance::messages.product.plan', help: 'enhance::messages.product.plan_help'),
        ];
    }

    /** @return list<string> */
    protected function requiredConnectionKeys(): array
    {
        return ['api_url', 'api_token', 'account_id'];
    }

    /** @param array<string, mixed> $providerSettings @return array<string, mixed> */
    protected function buildCreatePayload(ServiceInstanceInfo $instance, array $providerSettings, string $externalId): array
    {
        return [
            'external_id' => $externalId,
            'name' => $instance->label,
            'domain' => $providerSettings['domain'] ?? null,
            'plan' => $providerSettings['plan'] ?? null,
        ];
    }
}
