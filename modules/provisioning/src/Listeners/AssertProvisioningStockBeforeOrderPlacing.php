<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Listeners;

use Agovena\Modules\Provisioning\CapacityReservationService;
use App\Agovena\Catalog\Options\ProductOptionPricer;
use App\Agovena\Provisioning\Contracts\ChecksProvisioningStock;
use App\Agovena\Provisioning\Contracts\ChecksProvisioningStockVector;
use App\Agovena\Provisioning\Contracts\ConfiguresProvisionedProducts;
use App\Agovena\Provisioning\Contracts\ProvidesProvisioningCapacityRequirements;
use App\Agovena\Provisioning\ProvisionerRegistry;
use App\Agovena\Provisioning\ProvisioningStockContext;
use App\Events\OrderPlacing;
use App\Events\OrderPreflight;
use App\Models\Product;
use App\Models\ProvisioningServer;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AssertProvisioningStockBeforeOrderPlacing
{
    public function __construct(
        private readonly ProvisionerRegistry $provisioners,
        private readonly ProductOptionPricer $options,
        private readonly CapacityReservationService $reservations,
    ) {}

    public function preflight(OrderPreflight $event): void
    {
        $groups = [];
        foreach ($event->lines as $index => $line) {
            $product = Product::query()->with('capabilities')->find($line->productId);
            if ($product === null || ! $product->hasCapability('provisionable')) {
                $event->checks[$index] = [
                    'provisionable' => false,
                    'product_id' => $line->productId,
                ];

                continue;
            }

            [$providerKey, $serverSettings, $serverId, $providerSettings] = $this->configuration($product, $line->selections);
            if ($providerKey === null || $providerKey === 'manual') {
                $event->checks[$index] = [
                    'provisionable' => false,
                    'product_id' => $line->productId,
                    'provider_key' => $providerKey,
                    'server_id' => $serverId,
                    'server_settings' => $serverSettings,
                    'provider_settings' => $providerSettings,
                ];

                continue;
            }

            $provider = $this->provisioners->get($providerKey);
            if (! $provider instanceof ChecksProvisioningStock) {
                throw ValidationException::withMessages([
                    'cart' => __('provisioning::errors.provider_unavailable'),
                ]);
            }
            $context = new ProvisioningStockContext(
                product: $product,
                line: $line,
                providerKey: $providerKey,
                providerSettings: $providerSettings,
                serverSettings: $serverSettings,
                serverId: $serverId,
                serverSettingsRequired: $serverId !== null,
            );
            $capacityKey = $provider->capacityKey($context);
            if ($capacityKey === '') {
                throw ValidationException::withMessages([
                    'cart' => __('provisioning::errors.provider_unavailable'),
                ]);
            }
            $requirements = $provider instanceof ProvidesProvisioningCapacityRequirements
                ? $provider->capacityRequirements($providerSettings, $serverSettings)
                : [];
            $groupKey = $providerKey.'|'.$capacityKey;
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'provider' => $provider,
                    'provider_key' => $providerKey,
                    'capacity_key' => $capacityKey,
                    'context' => $context,
                    'indexes' => [],
                    'quantity' => 0,
                    'requirements' => [],
                    'unit_requirements' => [],
                    'non_numeric_requirements' => [],
                ];
            }
            $group = &$groups[$groupKey];
            $group['indexes'][] = $index;
            $group['quantity'] += $context->quantity();
            foreach ($requirements as $key => $value) {
                if (is_numeric($value)) {
                    $group['requirements'][$key] = ($group['requirements'][$key] ?? 0) + ((float) $value * $context->quantity());
                    $group['unit_requirements'][$key] = max(
                        (float) ($group['unit_requirements'][$key] ?? 0),
                        (float) $value,
                    );
                } elseif (array_key_exists($key, $group['non_numeric_requirements'])
                    && $group['non_numeric_requirements'][$key] !== $value
                ) {
                    throw ValidationException::withMessages([
                        'cart' => __('provisioning::errors.provider_unavailable'),
                    ]);
                } else {
                    $group['non_numeric_requirements'][$key] = $value;
                }
            }
            $group['line_checks'][$index] = [
                'product_id' => $line->productId,
                'provider_settings' => $providerSettings,
                'server_settings' => $serverSettings,
                'server_id' => $serverId,
                'requirements' => $requirements,
                'quantity' => $context->quantity(),
            ];
            unset($group);
        }

        foreach ($groups as $group) {
            $provider = $group['provider'];
            $capacityKey = (string) $group['capacity_key'];
            $observedHeldQuantity = $this->reservations->heldQuantity($capacityKey);
            $vectorCapable = $provider instanceof ChecksProvisioningStockVector;
            $observedHeldRequirements = $vectorCapable ? $this->reservations->heldRequirements($capacityKey) : [];
            $context = $group['context'];
            if ($vectorCapable) {
                $unitSettings = $context->providerSettings;
                foreach ($group['unit_requirements'] as $key => $value) {
                    $unitSettings[$key] = $value;
                }
                $context = new ProvisioningStockContext(
                    product: $context->product,
                    line: $context->line,
                    providerKey: $context->providerKey,
                    providerSettings: $unitSettings,
                    serverSettings: $context->serverSettings,
                    serverId: $context->serverId,
                    quantityOverride: (int) $group['quantity'],
                    serverSettingsRequired: $context->serverSettingsRequired,
                );
            } else {
                $context = new ProvisioningStockContext(
                    product: $context->product,
                    line: $context->line,
                    providerKey: $context->providerKey,
                    providerSettings: $context->providerSettings,
                    serverSettings: $context->serverSettings,
                    serverId: $context->serverId,
                    quantityOverride: (int) $group['quantity'],
                    serverSettingsRequired: $context->serverSettingsRequired,
                );
            }

            try {
                if ($vectorCapable) {
                    $provider->assertStockVector($context, $observedHeldRequirements);
                } else {
                    $provider->assertStock($context, $observedHeldQuantity);
                }
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages([
                    'cart' => __('provisioning::errors.provider_unavailable'),
                ]);
            }

            foreach ($group['line_checks'] as $index => $lineCheck) {
                $event->checks[$index] = [
                    'provisionable' => true,
                    'product_id' => $lineCheck['product_id'],
                    'provider_key' => $group['provider_key'],
                    'provider_settings' => $lineCheck['provider_settings'],
                    'server_settings' => $lineCheck['server_settings'],
                    'server_id' => $lineCheck['server_id'],
                    'capacity_key' => $capacityKey,
                    'requirements' => $lineCheck['requirements'],
                    'vector_capable' => $vectorCapable,
                    'quantity' => $lineCheck['quantity'],
                    'observed_held_quantity' => $observedHeldQuantity,
                    'observed_held_requirements' => $observedHeldRequirements,
                ];
            }
        }
    }

    public function handle(OrderPlacing $event): void
    {
        $order = $event->order;
        if ($order === null || $event->preflight === null) {
            return;
        }
        $reservations = [];
        $orderItems = $order->items()->orderBy('id')->get();
        foreach ($event->lines as $index => $line) {
            $check = $event->preflight->checks[$index] ?? null;
            if (! is_array($check) || ($check['provisionable'] ?? false) !== true) {
                continue;
            }
            $product = Product::query()->find($line->productId);
            if ($product === null) {
                throw ValidationException::withMessages([
                    'cart' => __('provisioning::errors.provider_unavailable'),
                ]);
            }
            $orderItem = $orderItems->get($index);
            $orderItemId = $orderItem?->id;
            if (! is_int($orderItemId) || (int) $orderItem->product_id !== $line->productId) {
                throw ValidationException::withMessages([
                    'cart' => __('provisioning::errors.provider_unavailable'),
                ]);
            }
            $reservations[] = [$product, $check];
            $reservations[array_key_last($reservations)][] = $orderItemId;
        }

        $capacityKeys = collect($reservations)
            ->map(static fn (array $reservation): string => (string) $reservation[1]['capacity_key'])
            ->unique()
            ->sort()
            ->values();
        foreach ($capacityKeys as $capacityKey) {
            $this->reservations->lock($capacityKey);
        }

        $observed = [];
        foreach ($reservations as [$product, $check, $orderItemId]) {
            $capacityKey = (string) $check['capacity_key'];
            $isFirstReservation = ! isset($observed[$capacityKey]);
            $expectedHeld = $isFirstReservation ? (int) $check['observed_held_quantity'] : null;
            $observed[$capacityKey] = true;
            $this->reservations->reserve(
                order: $order,
                product: $product,
                providerKey: (string) $check['provider_key'],
                capacityKey: $capacityKey,
                quantity: (int) $check['quantity'],
                requirements: is_array($check['requirements'] ?? null) ? $check['requirements'] : [],
                expectedHeldQuantity: $expectedHeld,
                allowMixedRequirements: ($check['vector_capable'] ?? false) === true,
                expectedHeldRequirements: $isFirstReservation
                    ? (is_array($check['observed_held_requirements'] ?? null) ? $check['observed_held_requirements'] : [])
                    : null,
                orderItemId: $orderItemId,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $selections
     * @return array{0: string|null, 1: array<string, mixed>|null, 2: int|null, 3: array<string, mixed>}
     */
    private function configuration(Product $product, array $selections): array
    {
        $capability = $product->capability('provisionable');
        if ($capability !== null && $capability->hasCorruptConfig()) {
            throw ValidationException::withMessages([
                'cart' => __('provisioning::errors.provider_unavailable'),
            ]);
        }
        $config = $capability?->runtimeConfig() ?? [];
        $providerKey = isset($config['provider_key']) && is_string($config['provider_key']) && trim($config['provider_key']) !== ''
            ? trim($config['provider_key'])
            : null;
        $providerSettings = is_array($config['provider_settings'] ?? null) ? $config['provider_settings'] : [];
        $rawServerId = $config['server_id'] ?? null;
        $serverId = null;
        if (array_key_exists('server_id', $config) && $rawServerId !== null && $rawServerId !== '') {
            if (! (is_int($rawServerId) && $rawServerId > 0)
                && ! (is_string($rawServerId) && ctype_digit(trim($rawServerId)) && (int) $rawServerId > 0)
            ) {
                throw ValidationException::withMessages([
                    'cart' => __('provisioning::errors.server_unavailable'),
                ]);
            }
            $serverId = (int) $rawServerId;
        }
        $serverSettings = null;

        if ($serverId !== null) {
            $server = ProvisioningServer::query()->where('is_active', true)->find($serverId);
            if ($server === null || ($providerKey !== null && $server->provider_key !== $providerKey)) {
                throw ValidationException::withMessages([
                    'cart' => __('provisioning::errors.server_unavailable'),
                ]);
            }

            $providerKey = $server->provider_key;
            $serverSettings = is_array($server->settings) ? $server->settings : [];
        }

        $provider = $providerKey !== null ? $this->provisioners->get($providerKey) : null;
        if ($provider instanceof ConfiguresProvisionedProducts) {
            $definitions = [];
            foreach ($provider->productSettings() as $definition) {
                $definitions[$definition->key] = $definition->secret;
            }
            foreach ($this->options->snapshot($product, $selections) as $option) {
                $key = isset($option['key']) ? (string) $option['key'] : '';
                try {
                    $value = $this->options->runtimeValueForSelection($product, $key, $selections);
                } catch (Throwable) {
                    throw ValidationException::withMessages([
                        'cart' => __('provisioning::errors.provider_unavailable'),
                    ]);
                }
                if ($key !== '' && array_key_exists($key, $definitions) && (is_scalar($value) || is_array($value))) {
                    $providerSettings[$key] = $value;
                }
            }
        }

        return [$providerKey, $serverSettings, $serverId, $providerSettings];
    }
}
