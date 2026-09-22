<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

interface PaddleConnectionChecker
{
    public function ping(): void;
}
