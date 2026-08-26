<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains;

use Agovena\Modules\Domains\Contracts\DomainRegistrar;
use App\Agovena\Extensions\Contracts\ClearsRuntimeRegistry;
use InvalidArgumentException;

final class DomainRegistrarRegistry implements ClearsRuntimeRegistry
{
    /** @var array<string, DomainRegistrar> */
    private array $registrars = [];

    public function register(DomainRegistrar $registrar): void
    {
        $key = trim($registrar->key());
        if ($key === '') {
            throw new InvalidArgumentException('A domain registrar must have a non-empty key.');
        }

        $this->registrars[$key] = $registrar;
    }

    public function get(string $key): ?DomainRegistrar
    {
        return $this->registrars[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->registrars[$key]);
    }

    public function clear(): void
    {
        $this->registrars = [];
    }

    /** @return array<string, DomainRegistrar> */
    public function all(): array
    {
        return $this->registrars;
    }
}
