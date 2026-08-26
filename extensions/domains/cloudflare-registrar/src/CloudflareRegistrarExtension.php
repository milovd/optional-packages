<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareRegistrar;

use Agovena\Modules\Domains\Contracts\DomainRegistrar;
use Agovena\Modules\Domains\DomainRegistrarRegistry;
use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;

final class CloudflareRegistrarExtension implements Extension
{
    public function id(): string
    {
        return 'cloudflare-registrar';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition(
            key: 'account_id',
            label: 'cloudflare-registrar::messages.settings.account_id',
            type: 'string',
            secret: false,
            required: true,
            help: 'cloudflare-registrar::messages.settings.account_id_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'api_token',
            label: 'cloudflare-registrar::messages.settings.api_token',
            type: 'string',
            secret: true,
            required: true,
            help: 'cloudflare-registrar::messages.settings.api_token_help',
        ));

        app(DomainRegistrarRegistry::class)->register(app(CloudflareRegistrar::class));
    }
}
