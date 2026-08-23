<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Listeners;

use Agovena\Modules\Provisioning\Enums\ServiceInstanceStatus;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use Agovena\Modules\Provisioning\ProvisioningOrchestrator;
use App\Events\PlanChangeApplied;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

final class ApplyPlanChangeToService
{
    public function handle(PlanChangeApplied $event): void
    {
        $subscriptionId = $event->request->subscription_id;
        if ($subscriptionId === null) {
            return;
        }

        $to = Product::query()->with('capabilities')->find($event->request->to_product_id);
        if ($to === null) {
            return;
        }

        $capability = $to->capability('provisionable');
        $config = $capability !== null ? ($capability->config ?? []) : [];
        $providerSettings = is_array($config['provider_settings'] ?? null) ? $config['provider_settings'] : [];
        $serverId = is_numeric($config['server_id'] ?? null) ? (int) $config['server_id'] : null;
        $providerKey = isset($config['provider_key']) && is_string($config['provider_key']) && $config['provider_key'] !== ''
            ? $config['provider_key']
            : null;

        $instances = ServiceInstance::query()
            ->where('subscription_id', $subscriptionId)
            ->where('status', '!=', ServiceInstanceStatus::Terminated->value)
            ->get();

        $orchestrator = app(ProvisioningOrchestrator::class);

        foreach ($instances as $instance) {
            $meta = $instance->meta ?? [];
            $meta['plan_change'] = [
                'from_product_id' => $event->request->from_product_id,
                'to_product_id' => $to->id,
                'applied_at' => now()->toIso8601String(),
            ];
            if ($providerSettings !== []) {
                $meta['provider_settings'] = $providerSettings;
            }
            $instance->product_id = $to->id;
            $instance->provisioning_server_id = $serverId;
            if ($providerKey !== null) {
                $instance->provider_key = $providerKey;
            }
            $instance->failure_message = null;
            $instance->meta = $meta;
            $instance->save();

            try {
                $orchestrator->changePlan($instance->fresh() ?? $instance, (string) $to->id);
            } catch (ValidationException $exception) {
                $failed = $instance->fresh() ?? $instance;
                $failed->failure_message = $exception->errors()['instance'][0] ?? __('provisioning::errors.provider_failed');
                $failed->save();
            }
        }
    }
}
