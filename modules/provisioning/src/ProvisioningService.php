<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use Agovena\Modules\Provisioning\Enums\ServiceInstanceStatus;
use Agovena\Modules\Provisioning\Jobs\ProvisionServiceInstance;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use App\Agovena\Notifications\SendsCataloguedMail;
use App\Agovena\Provisioning\Contracts\ConfiguresProvisionedProducts;
use App\Agovena\Provisioning\Contracts\PollsProvisionedInstances;
use App\Agovena\Provisioning\ProvisionerRegistry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProvisioningServer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProvisioningService implements PollsProvisionedInstances
{
    public function __construct(
        private readonly ProvisionerRegistry $provisioners,
    ) {}

    public function createFromPaidOrder(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $product = Product::query()->with('capabilities')->find($item->product_id);
            if ($product === null || ! $product->hasCapability('provisionable')) {
                continue;
            }

            $exists = ServiceInstance::query()
                ->where('order_item_id', $item->id)
                ->exists();
            if ($exists) {
                continue;
            }

            $capability = $product->capability('provisionable');
            $config = $capability !== null ? ($capability->config ?? []) : [];
            $providerKey = isset($config['provider_key']) && is_string($config['provider_key']) && $config['provider_key'] !== ''
                ? $config['provider_key']
                : null;
            $serverSelectionProvided = array_key_exists('server_id', $config)
                && $config['server_id'] !== null
                && $config['server_id'] !== '';
            $serverId = is_numeric($config['server_id'] ?? null) ? (int) $config['server_id'] : null;
            $server = $serverId !== null
                ? ProvisioningServer::query()->where('is_active', true)->find($serverId)
                : null;
            $serverUnavailable = $serverSelectionProvided
                && ($server === null || ($providerKey !== null && $server->provider_key !== $providerKey));
            if ($serverUnavailable) {
                $providerKey = null;
            } elseif ($server !== null) {
                $providerKey = $server->provider_key;
            }
            $providerSettings = is_array($config['provider_settings'] ?? null)
                ? $config['provider_settings']
                : [];
            $providerSettings = $this->applyOptionOverrides($providerKey, $providerSettings, $item->options_snapshot ?? []);

            $subscriptionId = null;
            if (Schema::hasTable('subscriptions')) {
                $subscriptionId = DB::table('subscriptions')->where('order_item_id', $item->id)->value('id');
                $subscriptionId = is_numeric($subscriptionId) ? (int) $subscriptionId : null;
            }

            for ($i = 0; $i < $item->quantity; $i++) {
                ServiceInstance::query()->create([
                    'number' => $this->generateNumber(),
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'product_id' => $product->id,
                    'customer_id' => $order->customer_id,
                    'customer_email' => $order->customer_email,
                    'customer_name' => $order->customer_name,
                    'subscription_id' => $subscriptionId,
                    'status' => $serverUnavailable ? ServiceInstanceStatus::ManualReview : ServiceInstanceStatus::Pending,
                    'provider_key' => $providerKey,
                    'provisioning_server_id' => $server?->id,
                    'meta' => [
                        'label' => $item->label,
                        'unit_amount' => $item->unit_amount,
                        'currency' => $item->currency,
                        'options_snapshot' => $item->options_snapshot ?? [],
                        'provider_settings' => $providerSettings,
                    ],
                    'failed_at' => $serverUnavailable ? now() : null,
                    'failure_message' => $serverUnavailable ? __('provisioning::errors.server_unavailable') : null,
                ]);
            }
        }

        $this->provisionPendingForOrder($order);
    }

    /**
     * Product option keys that match provider-defined fields act as safe per-order overrides.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<array<string, mixed>>  $snapshot
     * @return array<string, mixed>
     */
    private function applyOptionOverrides(?string $providerKey, array $settings, array $snapshot): array
    {
        if ($providerKey === null) {
            return $settings;
        }
        $provider = $this->provisioners->get($providerKey);
        if (! $provider instanceof ConfiguresProvisionedProducts) {
            return $settings;
        }
        $allowed = collect($provider->productSettings())->pluck('key')->all();
        foreach ($snapshot as $option) {
            $key = isset($option['key']) ? (string) $option['key'] : '';
            $value = $option['value'] ?? null;
            if ($key !== '' && in_array($key, $allowed, true) && (is_scalar($value) || is_array($value))) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    public function provisionPendingForOrder(Order $order): void
    {
        $pending = ServiceInstance::query()
            ->where('order_id', $order->id)
            ->where('status', ServiceInstanceStatus::Pending)
            ->get();

        foreach ($pending as $instance) {
            ProvisionServiceInstance::dispatch($instance->id);
        }
    }

    public function pollProvisioning(): int
    {
        $orchestrator = app(ProvisioningOrchestrator::class);
        $instances = ServiceInstance::query()
            ->where('status', ServiceInstanceStatus::Provisioning)
            ->orderBy('id')
            ->limit(50)
            ->get();

        $synced = 0;
        foreach ($instances as $instance) {
            try {
                $orchestrator->sync($instance);
                $synced++;
            } catch (ValidationException) {
                continue;
            }
        }

        return $synced;
    }

    public function markProvisioning(ServiceInstance $instance): ServiceInstance
    {
        if (! in_array($instance->status, [ServiceInstanceStatus::Pending, ServiceInstanceStatus::Failed, ServiceInstanceStatus::ManualReview], true)) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.cannot_provision'),
            ]);
        }

        $instance->status = ServiceInstanceStatus::Provisioning;
        $instance->provisioning_at = now();
        $instance->failed_at = null;
        $instance->failure_message = null;
        $instance->save();

        return $instance->fresh() ?? $instance;
    }

    public function activate(ServiceInstance $instance, ?string $externalRef = null): ServiceInstance
    {
        if (! $instance->canActivate()) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.cannot_activate'),
            ]);
        }

        $instance->status = ServiceInstanceStatus::Active;
        $instance->activated_at = now();
        $instance->suspended_at = null;
        $instance->failed_at = null;
        $instance->failure_message = null;
        if ($externalRef !== null && $externalRef !== '') {
            $instance->external_ref = $externalRef;
        }
        $instance->save();

        return $this->notifyLifecycle($instance->fresh() ?? $instance, 'service_activated');
    }

    public function suspend(ServiceInstance $instance): ServiceInstance
    {
        if (! $instance->canSuspend()) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.cannot_suspend'),
            ]);
        }

        $instance->status = ServiceInstanceStatus::Suspended;
        $instance->suspended_at = now();
        $instance->save();

        return $this->notifyLifecycle($instance->fresh() ?? $instance, 'service_suspended');
    }

    public function unsuspend(ServiceInstance $instance): ServiceInstance
    {
        if ($instance->status !== ServiceInstanceStatus::Suspended) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.cannot_unsuspend'),
            ]);
        }

        $instance->status = ServiceInstanceStatus::Active;
        $instance->suspended_at = null;
        $instance->save();

        return $this->notifyLifecycle($instance->fresh() ?? $instance, 'service_activated');
    }

    public function terminate(ServiceInstance $instance): ServiceInstance
    {
        if (! $instance->canTerminate()) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.cannot_terminate'),
            ]);
        }

        $instance->status = ServiceInstanceStatus::Terminated;
        $instance->terminated_at = now();
        $instance->save();

        return $instance->fresh() ?? $instance;
    }

    public function fail(ServiceInstance $instance, string $message): ServiceInstance
    {
        $instance->status = ServiceInstanceStatus::Failed;
        $instance->failed_at = now();
        $instance->failure_message = $message;
        $instance->save();

        return $instance->fresh() ?? $instance;
    }

    public function markManualReview(ServiceInstance $instance, string $message): ServiceInstance
    {
        if (! in_array($instance->status, [ServiceInstanceStatus::Failed, ServiceInstanceStatus::Provisioning], true)) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.cannot_review'),
            ]);
        }

        $instance->status = ServiceInstanceStatus::ManualReview;
        $instance->failed_at = now();
        $instance->failure_message = $message;
        $instance->save();

        return $instance->fresh() ?? $instance;
    }

    public function updateTracking(ServiceInstance $instance, ?string $providerKey, ?string $externalRef): ServiceInstance
    {
        if ($providerKey !== null) {
            $instance->provider_key = $providerKey !== '' ? $providerKey : null;
        }
        if ($externalRef !== null) {
            $instance->external_ref = $externalRef !== '' ? $externalRef : null;
        }
        $instance->save();

        return $instance->fresh() ?? $instance;
    }

    private function generateNumber(): string
    {
        do {
            $number = 'SVC-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (ServiceInstance::query()->where('number', $number)->exists());

        return $number;
    }

    private function notifyLifecycle(ServiceInstance $instance, string $key): ServiceInstance
    {
        $route = $key === 'service_activated' || $key === 'service_suspended'
            ? (Route::has('customer.services.show')
                ? route('customer.services.show', $instance)
                : url('/'))
            : url('/');

        app(SendsCataloguedMail::class)->toOrderCustomer(
            $instance->customer_id,
            (string) $instance->customer_email,
            $key,
            [
                'name' => (string) ($instance->customer_name ?? $instance->customer_email),
                'number' => $instance->number,
                'detail' => $instance->number,
                'action_url' => $route,
                'action_label' => __('notifications.'.$key.'.action'),
            ],
        );

        return $instance;
    }
}
