<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapDomain;

use Agovena\Modules\Domains\DomainRegistrarRegistry;
use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;

final class NamecheapDomainExtension implements Extension
{
    public function id(): string
    {
        return 'namecheap-domain';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition(
            key: 'api_user',
            label: 'namecheap-domain::messages.settings.api_user',
            type: 'string',
            required: false,
            help: 'namecheap-domain::messages.settings.api_user_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'api_key',
            label: 'namecheap-domain::messages.settings.api_key',
            type: 'string',
            secret: true,
            required: false,
            help: 'namecheap-domain::messages.settings.api_key_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'username',
            label: 'namecheap-domain::messages.settings.username',
            type: 'string',
            required: false,
            help: 'namecheap-domain::messages.settings.username_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'client_ip',
            label: 'namecheap-domain::messages.settings.client_ip',
            type: 'string',
            required: false,
            help: 'namecheap-domain::messages.settings.client_ip_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'sandbox',
            label: 'namecheap-domain::messages.settings.sandbox',
            type: 'boolean',
            required: false,
            default: true,
            help: 'namecheap-domain::messages.settings.sandbox_help',
        ));

        app(DomainRegistrarRegistry::class)->register(app(NamecheapRegistrar::class));
    }
}
