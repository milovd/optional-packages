<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapDomain;

interface NamecheapApi
{
    /** @return array<string, mixed> */
    public function check(array $domains): array;

    /** @return array<string, mixed> */
    public function register(string $domain, int $years): array;

    /** @return array<string, mixed> */
    public function renew(string $domain, int $years): array;
}
