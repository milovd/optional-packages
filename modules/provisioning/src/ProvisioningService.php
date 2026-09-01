<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use Agovena\Modules\Provisioning\Enums\ServiceInstanceStatus;
use Agovena\Modules\Provisioning\Jobs\ProvisionServiceInstance;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use App\Agovena\Catalog\Options\ProductOptionPricer;
use App\Agovena\Notifications\SendsCataloguedMail;
use App\Agovena\Provisioning\Contracts\ChecksProvisioningStock;
use App\Agovena\Provisioning\Contracts\ConfiguresProvisionedProducts;
use App\Agovena\Provisioning\Contracts\PollsProvisionedInstances;
use App\Agovena\Provisioning\Contracts\ProvidesProvisioningCapacityRequirements;
use App\Agovena\Provisioning\ProvisionerRegistry;
use App\Agovena\Security\OrderItemRuntimeSecretStore;
use Agovena\Modules\Provisioning\ServiceInstanceRuntimeSecretStore;
use App\Events\OrderPreflight;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProvisioningServer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ProvisioningService implements PollsProvisionedInstances
{
    public function __construct(
        private readonly ProvisionerRegistry $provisioners,
        private readonly CapacityReservationService $reservations,
        private readonly ProductOptionPricer $options,
        private readonly OrderItemRuntimeSecretStore $runtimeSecrets,
        private readonly ServiceInstanceRuntimeSecretStore $serviceRuntimeSecrets,
    ) {}

    public function snapshotOrderConfiguration(Order $order, ?OrderPreflight $preflight = null): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $itemIndex => $item) {
            if ($item->product_id === null) {
                continue;
            }

            $preflightCheck = $preflight?->checks[$itemIndex] ?? null;
            if ($preflight !== null && (! is_array($preflightCheck)
                || (int) ($preflightCheck['product_id'] ?? -1) !== (int) $item->product_id
            )) {
                throw ValidationException::withMessages([
                    'order' => __('provisioning::errors.provider_unavailable'),
                ]);
            }
            if ($preflight !== null) {
                if (($preflightCheck['provisionable'] ?? false) !== true) {
                    if (($preflightCheck['provider_key'] ?? null) === 'manual') {
                        $this->persistOrderProvisioningSnapshot(
                            $item,
                            'manual',
                            null,
                            null,
                            [],
                            null,
                            [],
                            [],
                        );
                    }

                    continue;
                }

                $providerKey = is_string($preflightCheck['provider_key'] ?? null) && $preflightCheck['provider_key'] !== ''
                    ? $preflightCheck['provider_key']
                    : null;
                if ($providerKey === null) {
                    throw ValidationException::withMessages([
                        'cart' => __('provisioning::errors.provider_unavailable'),
                    ]);
                }
                $providerSettings = is_array($preflightCheck['provider_settings'] ?? null)
                    ? $preflightCheck['provider_settings']
                    : [];
                $serverSettings = is_array($preflightCheck['server_settings'] ?? null)
                    ? $preflightCheck['server_settings']
                    : null;
                $rawServerId = $preflightCheck['server_id'] ?? null;
                $serverId = is_int($rawServerId) && $rawServerId > 0
                    ? $rawServerId
                    : (is_string($rawServerId) && preg_match('/^[1-9][0-9]*$/D', $rawServerId) === 1
                        ? filter_var($rawServerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                        : null);
                $provider = $this->provisioners->get($providerKey);
                $this->persistOrderProvisioningSnapshot(
                    $item,
                    $providerKey,
                    $serverId,
                    $serverSettings,
                    $providerSettings,
                    is_string($preflightCheck['capacity_key'] ?? null) && $preflightCheck['capacity_key'] !== ''
                        ? $preflightCheck['capacity_key']
                        : null,
                    is_array($preflightCheck['requirements'] ?? null) ? $preflightCheck['requirements'] : [],
                    $this->immutableProviderSettings($provider, $providerSettings),
                );

                continue;
            }

            $product = Product::query()->with('capabilities')->find($item->product_id);
            if ($product === null || ! $product->hasCapability('provisionable')) {
                continue;
            }

            $capability = $product->capability('provisionable');
            if ($capability !== null && $capability->hasCorruptConfig()) {
                throw ValidationException::withMessages([
                    'order' => __('provisioning::errors.provider_unavailable'),
                ]);
            }
            $config = $capability?->runtimeConfig() ?? [];
            $providerKey = isset($config['provider_key']) && is_string($config['provider_key']) && $config['provider_key'] !== ''
                ? $config['provider_key']
                : null;
            $rawServerId = $config['server_id'] ?? null;
            $serverId = (is_int($rawServerId) && $rawServerId > 0)
                || (is_string($rawServerId) && ctype_digit(trim($rawServerId)) && (int) $rawServerId > 0)
                ? (int) $rawServerId
                : null;
            $server = $serverId !== null ? ProvisioningServer::query()->find($serverId) : null;
            $serverSettingsSnapshot = $server !== null
                && $server->is_active
                && ($providerKey === null || $server->provider_key === $providerKey)
                && is_array($server->settings)
                ? $server->settings
                : null;
            $providerSettings = is_array($config['provider_settings'] ?? null)
                ? $config['provider_settings']
                : [];
            $optionsSnapshot = is_array($item->options_snapshot) ? $item->options_snapshot : [];
            $providerSettings = $this->applyOptionOverrides($providerKey, $providerSettings, $optionsSnapshot, $item->id);
            $provider = $providerKey !== null ? $this->provisioners->get($providerKey) : null;
            $providerSettingsSnapshot = $this->immutableProviderSettings($provider, $providerSettings);
            $capacityKey = $provider instanceof ChecksProvisioningStock
                ? $provider->capacityKeyForSettings($providerSettings, $serverId, $server?->settings)
                : '';
            $requirements = $provider instanceof ProvidesProvisioningCapacityRequirements
                ? $provider->capacityRequirements($providerSettings, $server?->settings)
                : [];
            $optionsSnapshot['__provisioning'] = [
                'provider_key' => $providerKey,
                'server_id' => $serverId,
                'provider_settings' => $providerSettingsSnapshot,
                'capacity_key' => $capacityKey !== '' ? $capacityKey : null,
                'requirements' => $requirements,
            ];
            $optionsSnapshot = $this->sanitizeSnapshotForStorage($optionsSnapshot, $provider);
            $item->options_snapshot = $optionsSnapshot;
            $item->provisioning_server_settings_snapshot = null;
            $item->provisioning_provider_settings_snapshot = null;
            $item->save();
            $this->storeRuntimeProvisioningSettings($item, $serverSettingsSnapshot, $providerSettings);
        }
    }

    /** @param array<string, mixed> $providerSettingsSnapshot @param array<string, mixed> $requirements */
    private function persistOrderProvisioningSnapshot(
        object $item,
        ?string $providerKey,
        ?int $serverId,
        ?array $serverSettings,
        array $providerSettings,
        ?string $capacityKey,
        array $requirements,
        array $providerSettingsSnapshot,
    ): void {
        $optionsSnapshot = is_array($item->options_snapshot) ? $item->options_snapshot : [];
        $optionsSnapshot['__provisioning'] = [
            'provider_key' => $providerKey,
            'server_id' => $serverId,
            'provider_settings' => $providerSettingsSnapshot,
            'capacity_key' => $capacityKey,
            'requirements' => $requirements,
        ];
        $optionsSnapshot = $this->sanitizeSnapshotForStorage($optionsSnapshot);
        $item->options_snapshot = $optionsSnapshot;
        $item->provisioning_server_settings_snapshot = null;
        $item->provisioning_provider_settings_snapshot = null;
        $item->save();
        $this->storeRuntimeProvisioningSettings($item, $serverSettings, $providerSettings);
    }

    private function storeRuntimeProvisioningSettings(
        object $item,
        ?array $serverSettings,
        array $providerSettings,
    ): void {
        $orderItemId = (int) ($item->id ?? 0);
        if ($orderItemId < 1) {
            return;
        }

        if ($serverSettings === null || $serverSettings === []) {
            $this->runtimeSecrets->forget($orderItemId, 'provisioning_server_settings');
        } else {
            $this->runtimeSecrets->put($orderItemId, 'provisioning_server_settings', $serverSettings);
        }
        if ($providerSettings === []) {
            $this->runtimeSecrets->forget($orderItemId, 'provisioning_provider_settings');
        } else {
            $this->runtimeSecrets->put($orderItemId, 'provisioning_provider_settings', $providerSettings);
        }
    }

    /** @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null} */
    private function runtimeProvisioningSettings(object $item): array
    {
        $orderItemId = (int) ($item->id ?? 0);
        $serverSettings = $orderItemId > 0
            ? $this->runtimeSecrets->get($orderItemId, 'provisioning_server_settings')
            : null;
        $providerSettings = $orderItemId > 0
            ? $this->runtimeSecrets->get($orderItemId, 'provisioning_provider_settings')
            : null;

        return [
            is_array($serverSettings) ? $serverSettings : null,
            is_array($providerSettings) ? $providerSettings : null,
        ];
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function immutableProviderSettings(?object $provider, array $settings): array
    {
        if ($provider instanceof ConfiguresProvisionedProducts) {
            $definitions = [];
            foreach ($provider->productSettings() as $definition) {
                $definitions[$definition->key] = $definition->secret;
            }
            $filtered = [];
            foreach ($settings as $key => $value) {
                if (! array_key_exists((string) $key, $definitions)) {
                    $filtered[$key] = '[REDACTED]';
                } elseif ($definitions[(string) $key] === true) {
                    $filtered[$key] = '[REDACTED]';
                } else {
                    $filtered[$key] = $value;
                }
            }

            return $filtered;
        }

        return $this->sanitizeProvisioningSettings($settings);
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    public function providerSettingsSnapshot(?object $provider, array $settings): array
    {
        return $this->sanitizeSnapshotForStorage(
            $this->immutableProviderSettings($provider, $settings),
            $provider,
        );
    }

    public function encryptedProviderSettings(array $settings): ?string
    {
        return $this->encryptProviderSettings($settings);
    }

    private function sanitizeSnapshotForStorage(mixed $value, ?object $provider = null, ?string $key = null): mixed
    {
        if ($key !== null && strtolower($key) === 'environment') {
            return '[REDACTED]';
        }
        if ($key !== null && strtolower($key) === 'provider_settings' && is_array($value)) {
            return $this->sanitizeSnapshotForStorage($this->immutableProviderSettings($provider, $value), $provider);
        }
        if (! is_array($value)) {
            return $value;
        }

        if (is_string($value['key'] ?? null)
            && array_key_exists('value', $value)
            && $this->isSensitiveSnapshotKey($value['key'])
        ) {
            $value['value'] = '[REDACTED]';
            if (array_key_exists('display', $value)) {
                $value['display'] = '[REDACTED]';
            }
        }

        $sanitized = [];
        foreach ($value as $nestedKey => $nestedValue) {
            if (is_string($nestedKey) && str_ends_with(strtolower($nestedKey), '_encrypted')) {
                continue;
            }
            if (is_array($nestedValue)
                && is_string($nestedValue['key'] ?? null)
                && array_key_exists('value', $nestedValue)
                && $this->isSensitiveSnapshotKey($nestedValue['key'])
            ) {
                $nestedValue['value'] = '[REDACTED]';
                if (array_key_exists('display', $nestedValue)) {
                    $nestedValue['display'] = '[REDACTED]';
                }
            }
            $sanitized[$nestedKey] = $this->sanitizeSnapshotForStorage(
                $nestedValue,
                $provider,
                is_string($nestedKey) ? $nestedKey : null,
            );
        }

        return $sanitized;
    }

    private function isSensitiveSnapshotKey(string $key): bool
    {
        $normalizedKey = strtolower(trim($key));

        return $normalizedKey === 'environment'
            || preg_match('/(?:api[_-]?key|access[_-]?key|token|secret|password|passwd|credential|authorization|private[_-]?key|connection|string|dsn)/', $normalizedKey) === 1;
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function sanitizeProvisioningSettings(array $settings): array
    {
        $sanitized = [];
        foreach ($settings as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if ($normalizedKey === 'environment'
                || str_ends_with($normalizedKey, '_encrypted')
                || preg_match('/(?:api[_-]?key|access[_-]?key|token|secret|password|passwd|credential|authorization|private[_-]?key|connection|string|dsn)/', $normalizedKey) === 1) {
                if (str_ends_with($normalizedKey, '_encrypted')) {
                    continue;
                }
                $sanitized[$key] = '[REDACTED]';

                continue;
            }
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeProvisioningSettings($value);

                continue;
            }
            if (is_string($value) && (str_contains($value, '://') && str_contains($value, '@'))) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function encryptProviderSettings(array $settings): ?string
    {
        if ($settings === []) {
            return null;
        }

        return Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    private function decryptProviderSettings(mixed $encrypted): ?array
    {
        if (! is_string($encrypted) || trim($encrypted) === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    public function createFromPaidOrder(Order $order): void
    {
        $properties = is_array($order->custom_properties_snapshot) ? $order->custom_properties_snapshot : [];
        if (($properties['origin'] ?? null) === 'plan_change_surcharge') {
            return;
        }

        if (Schema::hasTable('subscription_renewals')
            && DB::table('subscription_renewals')->where('order_id', $order->id)->exists()) {
            return;
        }

        $ambiguousLegacy = false;
        DB::transaction(function () use ($order, &$ambiguousLegacy): void {
            $items = $order->items()->orderBy('id')->lockForUpdate()->get();
            foreach ($items as $item) {
                $ambiguousLegacy = $this->createPaidItemInstances($order, $item) || $ambiguousLegacy;
            }
        });

        if ($ambiguousLegacy) {
            throw ValidationException::withMessages([
                'capacity' => __('provisioning::errors.provider_unavailable'),
            ]);
        }

        $this->provisionPendingForOrder($order->fresh() ?? $order);
    }

    private function createPaidItemInstances(Order $order, object $item): bool
    {
        if ($item->product_id === null || $item->quantity < 1) {
            return false;
        }

        $product = Product::query()->find($item->product_id);
        if ($product === null || ! $product->hasCapability('provisionable')) {
            return $this->reservations->releaseAllForOrderItem(
                order: $order,
                productId: (int) $item->product_id,
                orderItemId: (int) $item->id,
                deferAmbiguousFailure: true,
            );
        }

        $snapshot = $this->orderProvisioningSnapshot($item);
        $existing = ServiceInstance::query()
            ->where('order_item_id', $item->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $usedUnits = [];
        foreach ($existing as $instance) {
            if ($instance->unit_index !== null) {
                $usedUnits[(int) $instance->unit_index] = true;
            }
        }
        $nextUnit = 0;
        foreach ($existing as $instance) {
            if ($instance->unit_index !== null) {
                continue;
            }
            while (isset($usedUnits[$nextUnit])) {
                $nextUnit++;
            }
            $instance->unit_index = $nextUnit;
            $instance->save();
            $usedUnits[$nextUnit] = true;
            $nextUnit++;
        }

        $missingUnits = [];
        for ($unit = 0; $unit < (int) $item->quantity; $unit++) {
            if (! isset($usedUnits[$unit])) {
                $missingUnits[] = $unit;
            }
        }
        if ($missingUnits === []) {
            return false;
        }

        $snapshot ??= [];
        $providerKey = is_string($snapshot['provider_key'] ?? null) && $snapshot['provider_key'] !== ''
            ? $snapshot['provider_key']
            : null;
        $rawServerId = $snapshot['server_id'] ?? null;
        $serverId = is_int($rawServerId) && $rawServerId > 0
            ? $rawServerId
            : (is_string($rawServerId) && ctype_digit(trim($rawServerId)) && (int) $rawServerId > 0
                ? (int) $rawServerId
                : null);
        [$runtimeServerSettings, $runtimeProviderSettings] = $this->runtimeProvisioningSettings($item);
        $serverSettings = $runtimeServerSettings ?? (is_array($item->provisioning_server_settings_snapshot)
            ? $item->provisioning_server_settings_snapshot
            : null);
        $serverUnavailable = $serverId !== null && ($serverSettings === null || $serverSettings === []);
        $providerSettings = $runtimeProviderSettings ?? (is_array($item->provisioning_provider_settings_snapshot)
            ? $item->provisioning_provider_settings_snapshot
            : $this->decryptProviderSettings($snapshot['provider_settings_encrypted'] ?? null));
        $providerSettings = $providerSettings ?? [];
        $provider = $providerKey !== null ? $this->provisioners->get($providerKey) : null;
        $providerSettingsUnavailable = $providerKey !== null
            && $provider instanceof ConfiguresProvisionedProducts
            && $providerSettings === [];
        $capacityKey = is_string($snapshot['capacity_key'] ?? null) && $snapshot['capacity_key'] !== ''
            ? $snapshot['capacity_key']
            : null;
        $capacityRequirements = is_array($snapshot['requirements'] ?? null)
            ? $snapshot['requirements']
            : [];
        $storedProviderSettingsSnapshot = $this->providerSettingsSnapshot($provider, $providerSettings);
        $storedSnapshot = $this->sanitizeSnapshotForStorage($snapshot, $provider);
        unset($storedSnapshot['provider_settings_encrypted']);
        $storedSnapshot['provider_settings'] = $storedProviderSettingsSnapshot;
        $storedOptionsSnapshot = $this->sanitizeSnapshotForStorage($item->options_snapshot ?? [], $provider);
        if (is_array($storedOptionsSnapshot['__provisioning'] ?? null)) {
            unset($storedOptionsSnapshot['__provisioning']['provider_settings_encrypted']);
            $storedOptionsSnapshot['__provisioning']['provider_settings'] = $storedProviderSettingsSnapshot;
        }
        if ($storedOptionsSnapshot !== ($item->options_snapshot ?? [])) {
            $item->options_snapshot = $storedOptionsSnapshot;
            $item->save();
        }
        foreach ($existing as $existingInstance) {
            $existingMeta = is_array($existingInstance->meta) ? $existingInstance->meta : [];
            $existingChanged = false;
            if ($existingInstance->server_settings_snapshot !== null || $existingInstance->provider_settings_snapshot !== null) {
                $existingInstance->server_settings_snapshot = null;
                $existingInstance->provider_settings_snapshot = null;
                $existingChanged = true;
            }
            foreach ([
                'label' => $item->label,
                'options_snapshot' => $storedOptionsSnapshot,
                'provisioning_snapshot' => $storedSnapshot,
                'provider_settings' => $storedProviderSettingsSnapshot,
                'provisioning_capacity_key' => $capacityKey,
                'provisioning_capacity_requirements' => $capacityRequirements,
            ] as $metaKey => $metaValue) {
                if (! array_key_exists($metaKey, $existingMeta)) {
                    $existingMeta[$metaKey] = $metaValue;
                    $existingChanged = true;
                }
            }
            if ($existingChanged) {
                $existingInstance->meta = $existingMeta;
                $existingInstance->save();
            }
            if ($providerSettings === [] && ($serverSettings === null || $serverSettings === [])) {
                $this->serviceRuntimeSecrets->forget($existingInstance->id);
            } else {
                $this->serviceRuntimeSecrets->put($existingInstance->id, $serverSettings, $providerSettings);
            }
        }
        $providerUnavailable = $providerKey !== null && $provider === null;
        $manualReview = $snapshot === [] || $providerKey === null || $serverUnavailable || $providerUnavailable
            || $providerSettingsUnavailable;
        $failureMessage = $serverUnavailable
            ? __('provisioning::errors.server_unavailable')
            : __('provisioning::errors.provider_unavailable');
        $subscriptionId = Schema::hasTable('subscriptions')
            ? DB::table('subscriptions')->where('order_item_id', $item->id)->value('id')
            : null;
        $subscriptionId = is_numeric($subscriptionId) ? (int) $subscriptionId : null;

        foreach ($missingUnits as $unitIndex) {
            $instance = ServiceInstance::query()->create([
                'number' => $this->generateNumber(),
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'unit_index' => $unitIndex,
                'product_id' => $product?->id,
                'customer_id' => $order->customer_id,
                'customer_email' => $order->customer_email,
                'customer_name' => $order->customer_name,
                'subscription_id' => $subscriptionId,
                'status' => $manualReview ? ServiceInstanceStatus::ManualReview : ServiceInstanceStatus::Pending,
                'provider_key' => $providerKey,
                'provisioning_server_id' => $serverId,
                'server_settings_snapshot' => null,
                'provider_settings_snapshot' => null,
                'meta' => [
                    'label' => $item->label,
                    'unit_index' => $unitIndex,
                    'unit_amount' => $item->unit_amount,
                    'currency' => $item->currency,
                    'options_snapshot' => $storedOptionsSnapshot,
                    'provisioning_snapshot' => $storedSnapshot,
                    'provider_settings' => $storedProviderSettingsSnapshot,
                    'provisioning_capacity_key' => $capacityKey,
                    'provisioning_capacity_requirements' => $capacityRequirements,
                ],
                'failed_at' => $manualReview ? now() : null,
                'failure_message' => $manualReview ? $failureMessage : null,
            ]);
            $this->serviceRuntimeSecrets->put($instance->id, $serverSettings, $providerSettings);
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function orderProvisioningSnapshot(object $item): ?array
    {
        $options = is_array($item->options_snapshot) ? $item->options_snapshot : [];
        $snapshot = $options['__provisioning'] ?? null;

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * Product option keys that match provider-defined fields act as safe per-order overrides.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<array<string, mixed>>  $snapshot
     * @return array<string, mixed>
     */
    private function applyOptionOverrides(?string $providerKey, array $settings, array $snapshot, int $orderItemId): array
    {
        if ($providerKey === null) {
            return $settings;
        }
        $provider = $this->provisioners->get($providerKey);
        if (! $provider instanceof ConfiguresProvisionedProducts) {
            return $settings;
        }
        $allowed = [];
        foreach ($provider->productSettings() as $definition) {
            $allowed[$definition->key] = $definition->secret;
        }
        foreach ($snapshot as $option) {
            $key = isset($option['key']) ? (string) $option['key'] : '';
            try {
                $value = $this->options->runtimeValue($option, $orderItemId);
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'order' => __('provisioning::errors.provider_unavailable'),
                ]);
            }
            if ($key !== '' && array_key_exists($key, $allowed) && (is_scalar($value) || is_array($value))) {
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
            ProvisionServiceInstance::dispatch($instance->id)->afterCommit();
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
        if (! in_array($instance->status, [
            ServiceInstanceStatus::Active,
            ServiceInstanceStatus::Suspended,
            ServiceInstanceStatus::Failed,
            ServiceInstanceStatus::Provisioning,
        ], true)) {
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
