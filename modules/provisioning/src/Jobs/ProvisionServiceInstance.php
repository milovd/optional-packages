<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Jobs;

use Agovena\Modules\Provisioning\Enums\ServiceInstanceStatus;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use Agovena\Modules\Provisioning\ProvisioningOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProvisionServiceInstance implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 180;

    public function __construct(public int $instanceId) {}

    public function uniqueId(): string
    {
        return (string) $this->instanceId;
    }

    public function handle(ProvisioningOrchestrator $orchestrator): void
    {
        $instance = ServiceInstance::query()->find($this->instanceId);
        if ($instance === null) {
            return;
        }

        if (! in_array($instance->status, [
            ServiceInstanceStatus::Pending,
            ServiceInstanceStatus::Failed,
            ServiceInstanceStatus::Provisioning,
        ], true)) {
            return;
        }

        $orchestrator->provision($instance);
    }
}
