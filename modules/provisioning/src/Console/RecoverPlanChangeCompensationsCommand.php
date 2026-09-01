<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Console;

use Agovena\Modules\Provisioning\PlanChangeCompensationRecovery;
use Illuminate\Console\Command;

final class RecoverPlanChangeCompensationsCommand extends Command
{
    protected $signature = 'agovena:recover-plan-change-compensations {--limit=100}';

    protected $description = 'Recover interrupted plan-change provider operations';

    public function handle(PlanChangeCompensationRecovery $recovery): int
    {
        $recovered = $recovery->recover((int) $this->option('limit'));
        $this->info("Recovered plan-change compensations={$recovered}");

        return self::SUCCESS;
    }
}
