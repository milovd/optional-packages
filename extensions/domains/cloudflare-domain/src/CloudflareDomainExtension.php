<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareDomain;

use Agovena\Modules\Domains\DomainDnsProviderRegistry;
use Agovena\Modules\Domains\DomainRegistrarRegistry;
use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Payments\HealthResult;
use Throwable;

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

        $context->health(static function (): HealthResult {
            try {
                app(CloudflareApi::class)->check(['example.com']);
            } catch (Throwable) {
                return HealthResult::fail(__('cloudflare-domain::messages.health.unavailable'));
            }

            return HealthResult::ok(__('cloudflare-domain::messages.health.ok'));
        });

        app(DomainRegistrarRegistry::class)->register(app(CloudflareRegistrar::class));
        app(DomainDnsProviderRegistry::class)->register(app(CloudflareDnsProvider::class));
    }
}
