<?php

declare(strict_types=1);

namespace Agovena\Extensions\DomainDns;

interface CloudflareApi
{
    /** @return array<string, mixed> */
    public function check(array $domains): array;

    /** @return array<string, mixed> */
    public function register(string $domain, array $payload = []): array;
}
