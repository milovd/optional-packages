<?php

declare(strict_types=1);

namespace Agovena\Extensions\Pterodactyl;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionContext;

final class PterodactylExtension implements Extension
{
    public function id(): string
    {
        return 'pterodactyl';
    }

    public function register(ExtensionContext $context): void
    {
        $context->provisioner(app(PterodactylProvisioner::class));
        $context->health(static fn () => app(PterodactylProvisioner::class)->health());
    }
}
