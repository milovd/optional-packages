<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use Agovena\Modules\Provisioning\Models\ServiceInstance;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProvisioningInstanceMutex
{
    public function run(ServiceInstance $instance, Closure $callback): mixed
    {
        $this->assertSharedLockDriver();

        $lock = Cache::lock('agovena:provisioning:instance:'.$instance->id, 900);
        $lock->block(10);

        try {
            $current = DB::transaction(fn (): ?ServiceInstance => ServiceInstance::query()->lockForUpdate()->find($instance->id));

            return $current instanceof ServiceInstance ? $callback($current) : $instance;
        } finally {
            $lock->release();
        }
    }

    public function assertSharedLockDriver(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $store = (string) config('cache.default');
        $driver = config('cache.stores.'.$store.'.driver');
        if (! is_string($driver) || ! in_array($driver, ['database', 'dynamodb', 'memcached', 'redis'], true)) {
            throw new RuntimeException('A shared cache lock driver is required for provisioning operations.');
        }
    }
}
