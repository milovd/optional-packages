<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareDomain;

use Agovena\Modules\Domains\DomainDnsProviderRegistry;
use Agovena\Modules\Domains\DomainRegistrarRegistry;
use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;

final class CloudflareDomainExtension implements Extension
{
    public function id(): string
    {
        return 'cloudflare-domain';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition(
            key: 'account_id',
            label: 'cloudflare-domain::messages.settings.account_id',
            type: 'string',
            required: false,
            help: 'cloudflare-domain::messages.settings.account_id_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'api_token',
            label: 'cloudflare-domain::messages.settings.api_token',
            type: 'string',
            secret: true,
            required: false,
            help: 'cloudflare-domain::messages.settings.api_token_help',
        ));

        app(DomainRegistrarRegistry::class)->register(app(CloudflareRegistrar::class));
        app(DomainDnsProviderRegistry::class)->register(app(CloudflareDnsProvider::class));
    }
}
