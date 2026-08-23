<?php

declare(strict_types=1);

namespace Agovena\Extensions\Postnl;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;

final class PostnlExtension implements Extension
{
    public function id(): string
    {
        return 'postnl';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition(
            key: 'api_key',
            label: 'postnl::messages.settings.api_key',
            type: 'string',
            secret: true,
            required: true,
            help: 'postnl::messages.settings.api_key_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'customer_code',
            label: 'postnl::messages.settings.customer_code',
            type: 'string',
            required: true,
            help: 'postnl::messages.settings.customer_code_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'customer_number',
            label: 'postnl::messages.settings.customer_number',
            type: 'string',
            required: true,
            help: 'postnl::messages.settings.customer_number_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'collection_location',
            label: 'postnl::messages.settings.collection_location',
            type: 'string',
            help: 'postnl::messages.settings.collection_location_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'sandbox',
            label: 'postnl::messages.settings.sandbox',
            type: 'boolean',
            default: true,
            help: 'postnl::messages.settings.sandbox_help',
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'default_product_code',
            label: 'postnl::messages.settings.default_product_code',
            type: 'string',
            default: '3085',
            help: 'postnl::messages.settings.default_product_code_help',
        ));

        $context->shippingCarrier(app(PostnlCarrier::class));
        $context->health(static fn () => app(PostnlCarrier::class)->health());
    }
}
