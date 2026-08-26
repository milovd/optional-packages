<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains;

use Agovena\Modules\Domains\Contracts\DomainDnsProvider;
use App\Agovena\Extensions\Contracts\ClearsRuntimeRegistry;
use InvalidArgumentException;

final class DomainDnsProviderRegistry implements ClearsRuntimeRegistry
{
    /** @var array<string, DomainDnsProvider> */
    private array $providers = [];

    public function register(DomainDnsProvider $provider): void
    {
        $key = trim($provider->key());
        if ($key === '') {
            throw new InvalidArgumentException('A DNS provider must have a non-empty key.');
        }

        $this->providers[$key] = $provider;
    }

    public function get(string $key): ?DomainDnsProvider
    {
        return $this->providers[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function clear(): void
    {
        $this->providers = [];
    }

    /** @return array<string, DomainDnsProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
