<?php

declare(strict_types=1);

namespace Agovena\Extensions\Tebex;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;

final class TebexExtension implements Extension
{
    public function id(): string
    {
        return 'tebex';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition('project_id', 'tebex::messages.settings.project_id', 'string', required: true, help: 'tebex::messages.settings.project_id_help'));
        $context->setting(new ExtensionSettingDefinition('secret_key', 'tebex::messages.settings.secret_key', 'string', secret: true, required: true, help: 'tebex::messages.settings.secret_key_help'));
        $context->setting(new ExtensionSettingDefinition('webhook_secret', 'tebex::messages.settings.webhook_secret', 'string', secret: true, required: true, help: 'tebex::messages.settings.webhook_secret_help'));
        $context->setting(new ExtensionSettingDefinition('package_map', 'tebex::messages.settings.package_map', 'string', required: true, help: 'tebex::messages.settings.package_map_help'));
        $context->paymentGateway(app(TebexPaymentGateway::class));
        $context->health(static fn () => app(TebexPaymentGateway::class)->health());
    }
}
