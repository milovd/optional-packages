<?php

declare(strict_types=1);

namespace Agovena\Extensions\PayPal;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;

final class PayPalExtension implements Extension
{
    public function id(): string
    {
        return 'paypal';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition(
            key: 'client_id',
            label: 'paypal::messages.settings.client_id',
            type: 'string',
            required: true,
            help: 'paypal::messages.settings.client_id_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'client_secret',
            label: 'paypal::messages.settings.client_secret',
            type: 'string',
            secret: true,
            required: true,
            help: 'paypal::messages.settings.client_secret_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'webhook_id',
            label: 'paypal::messages.settings.webhook_id',
            type: 'string',
            required: true,
            help: 'paypal::messages.settings.webhook_id_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'sandbox',
            label: 'paypal::messages.settings.sandbox',
            type: 'boolean',
            default: true,
            help: 'paypal::messages.settings.sandbox_help',
        ));

        $context->paymentGateway(app(PayPalPaymentGateway::class));
        $context->health(static fn () => app(PayPalPaymentGateway::class)->health());
    }
}
