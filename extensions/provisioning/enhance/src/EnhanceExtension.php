<?php

declare(strict_types=1);

namespace Agovena\Extensions\Enhance;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;

final class EnhanceExtension implements Extension
{
    public function id(): string
    {
        return 'enhance';
    }

    public function register(ExtensionContext $context): void
    {
        $context->provisioner(app(EnhanceProvisioner::class));
        $context->health(static fn () => app(EnhanceProvisioner::class)->health());
    }
}
