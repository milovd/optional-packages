<?php

declare(strict_types=1);

namespace Agovena\Extensions\Stripe;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;

final class StripeExtension implements Extension
{
    public function id(): string
    {
        return 'stripe';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition(
            key: 'secret_key',
            label: 'stripe::messages.settings.secret_key',
            type: 'string',
            secret: true,
            required: true,
            help: 'stripe::messages.settings.secret_key_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'webhook_secret',
            label: 'stripe::messages.settings.webhook_secret',
            type: 'string',
            secret: true,
            required: true,
            help: 'stripe::messages.settings.webhook_secret_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'enabled_methods',
            label: 'stripe::messages.settings.enabled_methods',
            type: 'string',
            secret: false,
            help: 'stripe::messages.settings.enabled_methods_help',
        ));

        $context->paymentGateway(app(StripePaymentGateway::class));
        $context->health(static fn () => app(StripePaymentGateway::class)->health());
    }
}
