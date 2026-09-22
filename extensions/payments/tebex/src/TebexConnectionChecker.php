<?php

declare(strict_types=1);

namespace Agovena\Extensions\Tebex;

interface TebexConnectionChecker
{
    public function ping(): void;
}
