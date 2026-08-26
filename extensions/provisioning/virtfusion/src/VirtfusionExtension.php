<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtfusion;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;

final class VirtfusionExtension implements Extension
{
    public function id(): string
    {
        return 'virtfusion';
    }

    public function register(ExtensionContext $context): void
    {
        $context->provisioner(app(VirtfusionProvisioner::class));
        $context->health(static fn () => app(VirtfusionProvisioner::class)->health());
    }
}
