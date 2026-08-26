<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtualizor;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;

final class VirtualizorExtension implements Extension
{
    public function id(): string
    {
        return 'virtualizor';
    }

    public function register(ExtensionContext $context): void
    {
        $context->provisioner(app(VirtualizorProvisioner::class));
        $context->health(static fn () => app(VirtualizorProvisioner::class)->health());
    }
}
