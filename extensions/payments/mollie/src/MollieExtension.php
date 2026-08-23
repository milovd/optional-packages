<?php

declare(strict_types=1);

namespace Agovena\Extensions\Mollie;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;

final class MollieExtension implements Extension
{
    public function id(): string
    {
        return 'mollie';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition(
            key: 'api_key',
            label: 'mollie::messages.settings.api_key',
            type: 'string',
            secret: true,
            required: true,
            help: 'mollie::messages.settings.api_key_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'enabled_methods',
            label: 'mollie::messages.settings.enabled_methods',
            type: 'string',
            secret: false,
            help: 'mollie::messages.settings.enabled_methods_help',
        ));

        $context->paymentGateway(app(MolliePaymentGateway::class));
        $context->health(static fn () => app(MolliePaymentGateway::class)->health());
    }
}
