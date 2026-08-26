<?php

declare(strict_types=1);

namespace Agovena\Extensions\Convoy;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;

final class ConvoyExtension implements Extension
{
    public function id(): string
    {
        return 'convoy';
    }

    public function register(ExtensionContext $context): void
    {
        $context->provisioner(app(ConvoyProvisioner::class));
        $context->health(static fn () => app(ConvoyProvisioner::class)->health());
    }
}
