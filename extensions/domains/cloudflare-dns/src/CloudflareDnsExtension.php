<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareDns;

use Agovena\Modules\Domains\DomainDnsProviderRegistry;
use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;

final class CloudflareDnsExtension implements Extension
{
    public function id(): string
    {
        return 'cloudflare-dns';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition(
            key: 'account_id',
            label: 'cloudflare-dns::messages.settings.account_id',
            type: 'string',
            secret: false,
            required: true,
            help: 'cloudflare-dns::messages.settings.account_id_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'api_token',
            label: 'cloudflare-dns::messages.settings.api_token',
            type: 'string',
            secret: true,
            required: true,
            help: 'cloudflare-dns::messages.settings.api_token_help',
        ));

        app(DomainDnsProviderRegistry::class)->register(app(CloudflareDnsProvider::class));
    }
}
