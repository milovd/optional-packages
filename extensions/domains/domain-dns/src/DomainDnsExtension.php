<?php

declare(strict_types=1);

namespace Agovena\Extensions\DomainDns;

use Agovena\Modules\Domains\DomainDnsProviderRegistry;
use Agovena\Modules\Domains\DomainRegistrarRegistry;
use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;

final class DomainDnsExtension implements Extension
{
    public function id(): string
    {
        return 'domain-dns';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition(
            key: 'cloudflare_account_id',
            label: 'domain-dns::messages.settings.cloudflare_account_id',
            type: 'string',
            required: false,
            help: 'domain-dns::messages.settings.cloudflare_account_id_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'cloudflare_api_token',
            label: 'domain-dns::messages.settings.cloudflare_api_token',
            type: 'string',
            secret: true,
            required: false,
            help: 'domain-dns::messages.settings.cloudflare_api_token_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'namecheap_api_user',
            label: 'domain-dns::messages.settings.namecheap_api_user',
            type: 'string',
            required: false,
            help: 'domain-dns::messages.settings.namecheap_api_user_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'namecheap_api_key',
            label: 'domain-dns::messages.settings.namecheap_api_key',
            type: 'string',
            secret: true,
            required: false,
            help: 'domain-dns::messages.settings.namecheap_api_key_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'namecheap_username',
            label: 'domain-dns::messages.settings.namecheap_username',
            type: 'string',
            required: false,
            help: 'domain-dns::messages.settings.namecheap_username_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'namecheap_client_ip',
            label: 'domain-dns::messages.settings.namecheap_client_ip',
            type: 'string',
            required: false,
            help: 'domain-dns::messages.settings.namecheap_client_ip_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'namecheap_sandbox',
            label: 'domain-dns::messages.settings.namecheap_sandbox',
            type: 'boolean',
            required: false,
            default: true,
            help: 'domain-dns::messages.settings.namecheap_sandbox_help',
        ));

        $registrars = app(DomainRegistrarRegistry::class);
        $registrars->register(app(CloudflareRegistrar::class));
        $registrars->register(app(NamecheapRegistrar::class));
        app(DomainDnsProviderRegistry::class)->register(app(CloudflareDnsProvider::class));
    }
}
