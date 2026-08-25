<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;

final class PaddleExtension implements Extension
{
    public function id(): string
    {
        return 'paddle';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition('api_key', 'paddle::messages.settings.api_key', 'string', secret: true, required: true, help: 'paddle::messages.settings.api_key_help'));
        $context->setting(new ExtensionSettingDefinition('webhook_secret', 'paddle::messages.settings.webhook_secret', 'string', secret: true, required: true, help: 'paddle::messages.settings.webhook_secret_help'));
        $context->setting(new ExtensionSettingDefinition('price_map', 'paddle::messages.settings.price_map', 'string', required: true, help: 'paddle::messages.settings.price_map_help'));
        $context->setting(new ExtensionSettingDefinition('sandbox', 'paddle::messages.settings.sandbox', 'boolean', required: true, help: 'paddle::messages.settings.sandbox_help'));
        $context->paymentGateway(app(PaddlePaymentGateway::class));
        $context->health(static fn () => app(PaddlePaymentGateway::class)->health());
    }
}
