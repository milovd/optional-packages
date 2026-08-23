<?php

declare(strict_types=1);

namespace Agovena\Extensions\ManualPayment;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;
use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Payments\Gateways\DevelopmentPaymentGateway;
use App\Agovena\Payments\Gateways\ManualPaymentGateway;

final class ManualPaymentExtension implements Extension
{
    public function id(): string
    {
        return 'manual-payment';
    }

    public function register(ExtensionContext $context): void
    {
        $context->setting(new ExtensionSettingDefinition(
            key: 'instructions',
            label: 'admin.extensions.manual_payment.settings.instructions',
            type: 'text',
            secret: false,
        ));
        $context->setting(new ExtensionSettingDefinition(
            key: 'webhook_secret',
            label: 'admin.extensions.manual_payment.settings.webhook_secret',
            type: 'string',
            secret: true,
        ));

        $context->paymentGateway(new ManualPaymentGateway);

        if ((bool) config('agovena.payments.allow_development_instant_pay')) {
            $context->paymentGateway(app(DevelopmentPaymentGateway::class));
        }

        $context->health(static function () {
            return (new ManualPaymentGateway)->health();
        });
    }
}
