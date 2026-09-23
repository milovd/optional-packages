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
        $context->setting(new ExtensionSettingDefinition('client_token', 'paddle::messages.settings.client_token', 'string', required: true, help: 'paddle::messages.settings.client_token_help'));
        $context->setting(new ExtensionSettingDefinition('webhook_secret', 'paddle::messages.settings.webhook_secret', 'string', secret: true, required: true, help: 'paddle::messages.settings.webhook_secret_help'));
        $context->setting(new ExtensionSettingDefinition('sandbox', 'paddle::messages.settings.sandbox', 'boolean', required: true, help: 'paddle::messages.settings.sandbox_help'));
        $context->setting(new ExtensionSettingDefinition('enabled_methods', 'paddle::messages.settings.enabled_methods', 'payment_methods'));
        $context->paymentGateway(app(PaddlePaymentGateway::class));
        $context->health(static fn () => app(PaddlePaymentGateway::class)->health());
    }
}
