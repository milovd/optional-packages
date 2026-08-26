<?php

declare(strict_types=1);

namespace Agovena\Extensions\DirectAdmin;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;

final class DirectAdminExtension implements Extension
{
    public function id(): string
    {
        return 'directadmin';
    }

    public function register(ExtensionContext $context): void
    {
        $context->provisioner(app(DirectAdminProvisioner::class));
        $context->health(static fn () => app(DirectAdminProvisioner::class)->health());
    }
}
