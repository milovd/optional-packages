<?php

declare(strict_types=1);

namespace Agovena\Extensions\Proxmox;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;

final class ProxmoxExtension implements Extension
{
    public function id(): string
    {
        return 'proxmox';
    }

    public function register(ExtensionContext $context): void
    {
        $context->provisioner(app(ProxmoxProvisioner::class));
        $context->health(static fn () => app(ProxmoxProvisioner::class)->health());
    }
}
