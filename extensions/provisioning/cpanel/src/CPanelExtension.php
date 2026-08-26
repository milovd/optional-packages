<?php

declare(strict_types=1);

namespace Agovena\Extensions\CPanel;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;

final class CPanelExtension implements Extension
{
    public function id(): string
    {
        return 'cpanel';
    }

    public function register(ExtensionContext $context): void
    {
        $context->provisioner(app(CPanelProvisioner::class));
        $context->health(static fn () => app(CPanelProvisioner::class)->health());
    }
}
