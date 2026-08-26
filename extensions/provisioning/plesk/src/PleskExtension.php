<?php

declare(strict_types=1);

namespace Agovena\Extensions\Plesk;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;

final class PleskExtension implements Extension
{
    public function id(): string
    {
        return 'plesk';
    }

    public function register(ExtensionContext $context): void
    {
        $context->provisioner(app(PleskProvisioner::class));
        $context->health(static fn () => app(PleskProvisioner::class)->health());
    }
}
