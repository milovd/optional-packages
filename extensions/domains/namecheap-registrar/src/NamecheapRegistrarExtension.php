<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapRegistrar;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;
use Agovena\Modules\Domains\DomainRegistrarRegistry;

final class NamecheapRegistrarExtension implements Extension
{
    public function id(): string
    {
        return 'namecheap-registrar';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition(
            key: 'api_user',
            label: 'namecheap-registrar::messages.settings.api_user',
            type: 'string',
            secret: false,
            required: true,
            help: 'namecheap-registrar::messages.settings.api_user_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'api_key',
            label: 'namecheap-registrar::messages.settings.api_key',
            type: 'string',
            secret: true,
            required: true,
            help: 'namecheap-registrar::messages.settings.api_key_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'username',
            label: 'namecheap-registrar::messages.settings.username',
            type: 'string',
            secret: false,
            required: true,
            help: 'namecheap-registrar::messages.settings.username_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'client_ip',
            label: 'namecheap-registrar::messages.settings.client_ip',
            type: 'string',
            secret: false,
            required: true,
            help: 'namecheap-registrar::messages.settings.client_ip_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'sandbox',
            label: 'namecheap-registrar::messages.settings.sandbox',
            type: 'boolean',
            secret: false,
            required: false,
            default: true,
            help: 'namecheap-registrar::messages.settings.sandbox_help',
        ));

        app(DomainRegistrarRegistry::class)->register(app(NamecheapRegistrar::class));
    }
}
