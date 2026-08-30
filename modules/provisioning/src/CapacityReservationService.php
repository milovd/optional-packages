<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use Agovena\Modules\Provisioning\Models\CapacityReservation;
use Agovena\Modules\Provisioning\Models\ServiceInstance;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CapacityReservationService
{
    public function lock(string $capacityKey): void
    {
        DB::table('provisioning_capacity_locks')->insertOrIgnore([
            'lock_key' => $capacityKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('provisioning_capacity_locks')
            ->where('lock_key', $capacityKey)
            ->lockForUpdate()
            ->first();
    }

    public function heldQuantity(string $capacityKey): int
    {
        CapacityReservation::query()
            ->where('capacity_key', $capacityKey)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        return (int) CapacityReservation::query()
            ->where('capacity_key', $capacityKey)
            ->sum('quantity');
    }

    /** @return array<string, int|float> */
    public function heldRequirements(string $capacityKey): array
    {
        $this->purgeExpired($capacityKey);
        $aggregate = [];
        foreach (CapacityReservation::query()->where('capacity_key', $capacityKey)->where('quantity', '>', 0)->get() as $reservation) {
            foreach ($this->normalizeRequirements(is_array($reservation->requirements) ? $reservation->requirements : []) as $key => $value) {
                if (! is_numeric($value)) {
                    continue;
                }
                $aggregate[$key] = ($aggregate[$key] ?? 0) + ((float) $value * (int) $reservation->quantity);
            }
        }
        foreach ($aggregate as $key => $value) {
            if (is_float($value) && floor($value) === $value) {
                $aggregate[$key] = (int) $value;
            }
        }
        ksort($aggregate);

        return $aggregate;
    }

    public function reserve(
        Order $order,
        Product $product,
        string $providerKey,
        string $capacityKey,
        int $quantity,
        array $requirements = [],
        ?int $expectedHeldQuantity = null,
        bool $allowMixedRequirements = false,
        ?array $expectedHeldRequirements = null,
        ?int $orderItemId = null,
    ): void {
        if ($quantity < 1) {
            return;
        }

        $normalizedRequirements = $this->normalizeRequirements($requirements);
        $requirementsFingerprint = $this->requirementsFingerprint($normalizedRequirements);

        DB::transaction(function () use ($order, $product, $providerKey, $capacityKey, $quantity, $normalizedRequirements, $expectedHeldQuantity, $allowMixedRequirements, $requirementsFingerprint, $expectedHeldRequirements, $orderItemId): void {
            $this->lock($capacityKey);
            $this->purgeExpired($capacityKey);
            if ($expectedHeldQuantity !== null && $this->heldQuantity($capacityKey) !== $expectedHeldQuantity) {
                throw ValidationException::withMessages([
                    'cart' => __('provisioning::errors.capacity_changed'),
                ]);
            }
            if ($expectedHeldRequirements !== null
                && $this->requirementsFingerprint($this->heldRequirements($capacityKey)) !== $this->requirementsFingerprint($expectedHeldRequirements)
            ) {
                throw ValidationException::withMessages([
                    'cart' => __('provisioning::errors.capacity_changed'),
                ]);
            }
            if (! $allowMixedRequirements) {
                $this->assertPoolRequirementsCompatible($capacityKey, $normalizedRequirements);
            }
            $reservationQuery = CapacityReservation::query()
                ->where('order_id', $order->id)
                ->where('product_id', $product->id)
                ->where('provider_key', $providerKey)
                ->where('capacity_key', $capacityKey);
            if ($orderItemId !== null) {
                $reservationQuery->where('order_item_id', $orderItemId);
            }
            if ($allowMixedRequirements) {
                $reservationQuery->where('requirements_fingerprint', $requirementsFingerprint);
            }
            $reservation = $reservationQuery->lockForUpdate()->first();

            if ($reservation !== null) {
                if (! $allowMixedRequirements) {
                    $this->assertReservationRequirementsCompatible($reservation, $normalizedRequirements);
                }
                $existingQuantity = (int) $reservation->quantity;
                $reservation->quantity = $existingQuantity + $quantity;
                $reservation->save();

                return;
            }

            CapacityReservation::query()->create([
                'order_id' => $order->id,
                'order_item_id' => $orderItemId,
                'product_id' => $product->id,
                'provider_key' => $providerKey,
                'capacity_key' => $capacityKey,
                'quantity' => $quantity,
                'requirements' => $normalizedRequirements === [] ? null : $normalizedRequirements,
                'requirements_fingerprint' => $requirementsFingerprint,
                'expires_at' => now()->addMinutes(max(1, (int) config('provisioning.capacity_reservation_ttl_minutes', 30))),
            ]);
        });
    }

    /**
     * Revalidate one paid instance immediately before provider provisioning.
     *
     * Provider checks run outside the database transaction. The observed
     * snapshot and the final reservation re-check are serialized by the pool lock.
     */
    public function revalidateAndReserveForInstance(
        ServiceInstance $instance,
        Closure $assertAvailable,
        array $requirements = [],
        bool $allowMixedRequirements = false,
        ?string $capacityKeyOverride = null,
        ?string $previousCapacityKeyOverride = null,
        ?array $previousRequirementsOverride = null,
        ?int $previousProductIdOverride = null,
        ?string $previousProviderKeyOverride = null,
    ): bool {
        $capacityKey = $capacityKeyOverride ?? $this->capacityKey($instance);
        if ($capacityKey === null || $instance->order_id === null || $instance->product_id === null || $instance->provider_key === null) {
            return false;
        }

        $normalizedRequirements = $this->normalizeRequirements($requirements);
        $requirementsFingerprint = $this->requirementsFingerprint($normalizedRequirements);
        $previousCapacityKey = $previousCapacityKeyOverride;
        $previousRequirementsFingerprint = $previousRequirementsOverride !== null
            ? $this->requirementsFingerprint($previousRequirementsOverride)
            : null;
        $previousProductId = $previousProductIdOverride ?? $instance->product_id;
        $previousProviderKey = $previousProviderKeyOverride ?? $instance->provider_key;
        $preservesPreviousReservation = $previousCapacityKey !== null
            && $previousRequirementsOverride !== null
            && $previousCapacityKey === $capacityKey
            && $previousRequirementsFingerprint === $requirementsFingerprint
            && $previousProductId === $instance->product_id
            && $previousProviderKey === $instance->provider_key;
        $observed = DB::transaction(function () use ($instance, $capacityKey, $allowMixedRequirements, $requirementsFingerprint, $previousCapacityKey, $previousRequirementsFingerprint, $previousProductId, $previousProviderKey, $preservesPreviousReservation): array {
            $this->lock($capacityKey);
            $this->purgeExpired($capacityKey);
            $reservationQuery = CapacityReservation::query()
                ->where('order_id', $instance->order_id)
                ->where('product_id', $instance->product_id)
                ->where('provider_key', $instance->provider_key)
                ->where('capacity_key', $capacityKey)
                ->where('quantity', '>', 0);
            $reservationQuery = $this->scopeOrderItem($reservationQuery, $instance->order_item_id);
            if ($allowMixedRequirements) {
                $requirementsFingerprint === null
                    ? $reservationQuery->whereNull('requirements_fingerprint')
                    : $reservationQuery->where('requirements_fingerprint', $requirementsFingerprint);
            }
            $reservation = $reservationQuery
                ->lockForUpdate()
                ->first();
            $previousReservation = null;
            if ($previousCapacityKey === $capacityKey
                && ! $preservesPreviousReservation
                && $previousProductId !== null
                && $previousProviderKey !== null
            ) {
                $previousReservation = CapacityReservation::query()
                    ->where('order_id', $instance->order_id)
                    ->where('product_id', $previousProductId)
                    ->where('provider_key', $previousProviderKey)
                    ->where('capacity_key', $capacityKey)
                    ->where('quantity', '>', 0);
                $previousReservation = $this->scopeOrderItem($previousReservation, $instance->order_item_id)
                    ->where(function ($query) use ($previousRequirementsFingerprint): void {
                        if ($previousRequirementsFingerprint === null) {
                            $query->whereNull('requirements_fingerprint');
                        } else {
                            $query->where('requirements_fingerprint', $previousRequirementsFingerprint);
                        }
                    })
                    ->lockForUpdate()
                    ->first();
            }
            $totalHeld = (int) CapacityReservation::query()->where('capacity_key', $capacityKey)->sum('quantity');
            $heldRequirements = $this->heldRequirements($capacityKey);
            $ownReservations = array_values(array_filter([$reservation, $previousReservation]));
            $ownRequirements = [];
            foreach ($ownReservations as $ownReservation) {
                foreach ($this->normalizeRequirements(is_array($ownReservation->requirements) ? $ownReservation->requirements : []) as $key => $value) {
                    $ownRequirements[$key] = ($ownRequirements[$key] ?? 0)
                        + ((float) $value * max(0, (int) $ownReservation->quantity));
                }
            }
            $otherRequirements = $this->subtractRequirements($heldRequirements, $ownRequirements);
            $ownQuantity = array_sum(array_map(
                static fn (CapacityReservation $reservation): int => max(0, (int) $reservation->quantity),
                $ownReservations,
            ));

            return [
                'quantity' => max(0, $totalHeld - $ownQuantity),
                'requirements' => $otherRequirements,
                'requirements_fingerprint' => $this->requirementsFingerprint($otherRequirements),
                'total' => $totalHeld,
            ];
        });

        $assertAvailable((int) $observed['quantity'], is_array($observed['requirements']) ? $observed['requirements'] : []);

        DB::transaction(function () use ($instance, $capacityKey, $normalizedRequirements, $requirementsFingerprint, $allowMixedRequirements, $observed, $previousCapacityKey, $previousRequirementsFingerprint, $previousProductId, $previousProviderKey, $preservesPreviousReservation): void {
            $this->lock($capacityKey);
            $this->purgeExpired($capacityKey);
            $reservationQuery = CapacityReservation::query()
                ->where('order_id', $instance->order_id)
                ->where('product_id', $instance->product_id)
                ->where('provider_key', $instance->provider_key)
                ->where('capacity_key', $capacityKey)
                ->where('quantity', '>', 0);
            $reservationQuery = $this->scopeOrderItem($reservationQuery, $instance->order_item_id);
            if ($allowMixedRequirements) {
                $requirementsFingerprint === null
                    ? $reservationQuery->whereNull('requirements_fingerprint')
                    : $reservationQuery->where('requirements_fingerprint', $requirementsFingerprint);
            }
            $reservation = $reservationQuery
                ->lockForUpdate()
                ->first();
            $previousReservation = null;
            if ($previousCapacityKey === $capacityKey
                && ! $preservesPreviousReservation
                && $previousProductId !== null
                && $previousProviderKey !== null
            ) {
                $previousReservation = CapacityReservation::query()
                    ->where('order_id', $instance->order_id)
                    ->where('product_id', $previousProductId)
                    ->where('provider_key', $previousProviderKey)
                    ->where('capacity_key', $capacityKey)
                    ->where('quantity', '>', 0);
                $previousReservation = $this->scopeOrderItem($previousReservation, $instance->order_item_id)
                    ->where(function ($query) use ($previousRequirementsFingerprint): void {
                        if ($previousRequirementsFingerprint === null) {
                            $query->whereNull('requirements_fingerprint');
                        } else {
                            $query->where('requirements_fingerprint', $previousRequirementsFingerprint);
                        }
                    })
                    ->lockForUpdate()
                    ->first();
            }
            $currentTotal = (int) CapacityReservation::query()->where('capacity_key', $capacityKey)->sum('quantity');
            $currentOwnTotal = ($reservation !== null ? max(0, (int) $reservation->quantity) : 0)
                + ($previousReservation !== null ? max(0, (int) $previousReservation->quantity) : 0);
            $currentOtherTotal = max(0, $currentTotal - $currentOwnTotal);
            $currentHeldRequirements = $this->heldRequirements($capacityKey);
            $currentOwnRequirements = [];
            foreach (array_values(array_filter([$reservation, $previousReservation])) as $ownReservation) {
                foreach ($this->normalizeRequirements(is_array($ownReservation->requirements) ? $ownReservation->requirements : []) as $key => $value) {
                    $currentOwnRequirements[$key] = ($currentOwnRequirements[$key] ?? 0)
                        + ((float) $value * max(0, (int) $ownReservation->quantity));
                }
            }
            $currentOtherRequirements = $this->subtractRequirements($currentHeldRequirements, $currentOwnRequirements);
            if ($currentOtherTotal !== (int) $observed['quantity']
                || $this->requirementsFingerprint($currentOtherRequirements) !== ($observed['requirements_fingerprint'] ?? null)
            ) {
                throw ValidationException::withMessages([
                    'instance' => __('provisioning::errors.capacity_changed'),
                ]);
            }
            if ($reservation === null) {
                $fingerprint = $normalizedRequirements === []
                    ? null
                    : hash('sha256', json_encode($normalizedRequirements, JSON_THROW_ON_ERROR));
                CapacityReservation::query()->create([
                    'order_id' => $instance->order_id,
                    'order_item_id' => $instance->order_item_id,
                    'product_id' => $instance->product_id,
                    'provider_key' => $instance->provider_key,
                    'capacity_key' => $capacityKey,
                    'quantity' => 1,
                    'requirements' => $normalizedRequirements === [] ? null : $normalizedRequirements,
                    'requirements_fingerprint' => $fingerprint,
                    'expires_at' => null,
                ]);
            } elseif (! $allowMixedRequirements) {
                $this->assertReservationRequirementsCompatible($reservation, $normalizedRequirements);
            }
        });

        return true;
    }

    public function releaseForInstance(
        ServiceInstance $instance,
        ?string $capacityKeyOverride = null,
        ?array $requirementsOverride = null,
    ): void {
        $capacityKey = $capacityKeyOverride ?? $this->capacityKey($instance);
        if ($capacityKey === null || $instance->order_id === null || $instance->product_id === null || $instance->provider_key === null) {
            return;
        }

        $this->release(
            orderId: $instance->order_id,
            productId: $instance->product_id,
            providerKey: $instance->provider_key,
            capacityKey: $capacityKey,
            quantity: 1,
            orderItemId: $instance->order_item_id,
            requirementsFingerprint: $requirementsOverride !== null
                ? $this->requirementsFingerprint($requirementsOverride)
                : $this->instanceRequirementsFingerprint($instance),
        );
    }

    public function releaseForOrderProduct(
        Order $order,
        Product $product,
        ?string $providerKey = null,
        int $quantity = 1,
        ?int $orderItemId = null,
        ?string $requirementsFingerprint = null,
    ): void {
        $this->releaseForOrderProductId($order, $product->id, $providerKey, $quantity, $orderItemId, $requirementsFingerprint);
    }

    public function releaseForOrderProductId(
        Order $order,
        int $productId,
        ?string $providerKey = null,
        int $quantity = 1,
        ?int $orderItemId = null,
        ?string $requirementsFingerprint = null,
    ): void {
        if ($quantity < 1) {
            return;
        }

        DB::transaction(function () use ($order, $productId, $providerKey, $quantity, $orderItemId, $requirementsFingerprint): void {
            $keyQuery = CapacityReservation::query()
                ->where('order_id', $order->id)
                ->where('product_id', $productId);
            if ($providerKey !== null) {
                $keyQuery->where('provider_key', $providerKey);
            }
            if ($orderItemId !== null) {
                $keyQuery->where('order_item_id', $orderItemId);
            }
            $keyQuery = $this->scopeRequirementsFingerprint($keyQuery, $requirementsFingerprint);
            $capacityKeys = $keyQuery->distinct()->pluck('capacity_key')
                ->filter(static fn (mixed $key): bool => is_string($key) && $key !== '')
                ->unique()
                ->sort()
                ->values();
            foreach ($capacityKeys as $capacityKey) {
                $this->lock($capacityKey);
            }

            $query = CapacityReservation::query()
                ->where('order_id', $order->id)
                ->where('product_id', $productId)
                ->orderBy('id')
                ->lockForUpdate();
            if ($providerKey !== null) {
                $query->where('provider_key', $providerKey);
            }
            if ($orderItemId !== null) {
                $query->where('order_item_id', $orderItemId);
            }
            $query = $this->scopeRequirementsFingerprint($query, $requirementsFingerprint);

            $remaining = $quantity;
            foreach ($query->get() as $reservation) {
                $released = min($remaining, (int) $reservation->quantity);
                if ($released >= (int) $reservation->quantity) {
                    $reservation->delete();
                } else {
                    $reservation->decrement('quantity', $released);
                }
                $remaining -= $released;
                if ($remaining === 0) {
                    break;
                }
            }
        });
    }

    public function releaseAllForOrderItem(
        Order $order,
        int $productId,
        int $orderItemId,
        bool $deferAmbiguousFailure = false,
    ): bool {
        $ambiguousLegacy = false;
        DB::transaction(function () use ($order, $productId, $orderItemId, &$ambiguousLegacy): void {
            $legacy = CapacityReservation::query()
                ->where('order_id', $order->id)
                ->where('product_id', $productId)
                ->whereNull('order_item_id')
                ->lockForUpdate()
                ->get();
            if ($legacy->isNotEmpty()) {
                $candidateItemIds = DB::table('order_items')
                    ->where('order_id', $order->id)
                    ->where('product_id', $productId)
                    ->orderBy('id')
                    ->pluck('id');
                if ($candidateItemIds->count() === 1 && (int) $candidateItemIds->first() === $orderItemId) {
                    $legacy->each(static function (CapacityReservation $reservation) use ($orderItemId): void {
                        $reservation->order_item_id = $orderItemId;
                        $reservation->save();
                    });
                } else {
                    $ambiguousLegacy = true;
                    $this->recordAmbiguousLegacyCleanup(
                        $order,
                        $productId,
                        $orderItemId,
                        $candidateItemIds->count(),
                        $legacy->count(),
                    );
                }
            }

            $capacityKeys = CapacityReservation::query()
                ->where('order_id', $order->id)
                ->where('product_id', $productId)
                ->where('order_item_id', $orderItemId)
                ->distinct()
                ->pluck('capacity_key')
                ->filter(static fn (mixed $key): bool => is_string($key) && $key !== '')
                ->unique()
                ->sort()
                ->values();
            foreach ($capacityKeys as $capacityKey) {
                $this->lock($capacityKey);
            }

            CapacityReservation::query()
                ->where('order_id', $order->id)
                ->where('product_id', $productId)
                ->where('order_item_id', $orderItemId)
                ->lockForUpdate()
                ->get()
                ->each(static fn (CapacityReservation $reservation): bool => $reservation->delete());
        });
        if ($ambiguousLegacy && ! $deferAmbiguousFailure) {
            throw ValidationException::withMessages([
                'capacity' => __('provisioning::errors.provider_unavailable'),
            ]);
        }

        return $ambiguousLegacy;
    }

    private function recordAmbiguousLegacyCleanup(
        Order $order,
        int $productId,
        int $orderItemId,
        int $candidateItemCount,
        int $legacyReservationCount,
    ): void {
        $item = OrderItem::query()
            ->whereKey($orderItemId)
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();
        if ($item === null) {
            return;
        }

        $options = $this->removeEncryptedSnapshotKeys(
            is_array($item->options_snapshot) ? $item->options_snapshot : [],
        );
        $options['provisioning_recovery'] = [
            'state' => 'manual_review',
            'reason' => 'ambiguous_legacy_reservation_cleanup',
            'order_id' => $order->id,
            'order_item_id' => $orderItemId,
            'product_id' => $productId,
            'candidate_item_count' => $candidateItemCount,
            'legacy_reservation_count' => $legacyReservationCount,
            'recorded_at' => now()->toIso8601String(),
        ];
        $item->options_snapshot = $options;
        $item->save();
    }

    /** @return array<string|int, mixed> */
    private function removeEncryptedSnapshotKeys(array $snapshot): array
    {
        $sanitized = [];
        foreach ($snapshot as $key => $value) {
            if (is_string($key) && str_ends_with(strtolower($key), '_encrypted')) {
                continue;
            }
            $sanitized[$key] = is_array($value)
                ? $this->removeEncryptedSnapshotKeys($value)
                : $value;
        }

        return $sanitized;
    }

    public function restoreForInstance(
        ServiceInstance $instance,
        ?string $capacityKey,
        ?array $requirements,
        ?int $productId,
        ?string $providerKey,
    ): void {
        if ($capacityKey === null || $capacityKey === '' || $instance->order_id === null || $productId === null || $providerKey === null || $providerKey === '') {
            return;
        }

        $fingerprint = $requirements !== null ? $this->requirementsFingerprint($requirements) : null;
        DB::transaction(function () use ($instance, $capacityKey, $requirements, $fingerprint, $productId, $providerKey): void {
            $this->lock($capacityKey);
            $query = CapacityReservation::query()
                ->where('order_id', $instance->order_id)
                ->where('product_id', $productId)
                ->where('provider_key', $providerKey)
                ->where('capacity_key', $capacityKey)
                ->where('quantity', '>', 0);
            $query = $this->scopeOrderItem($query, $instance->order_item_id);
            $query = $this->scopeRequirementsFingerprint($query, $fingerprint);
            $reservation = $query->lockForUpdate()->first();
            if ($reservation !== null) {
                $reservation->quantity = (int) $reservation->quantity + 1;
                $reservation->expires_at = null;
                $reservation->save();

                return;
            }

            CapacityReservation::query()->create([
                'order_id' => $instance->order_id,
                'order_item_id' => $instance->order_item_id,
                'product_id' => $productId,
                'provider_key' => $providerKey,
                'capacity_key' => $capacityKey,
                'quantity' => 1,
                'requirements' => $requirements === null || $requirements === [] ? null : $this->normalizeRequirements($requirements),
                'requirements_fingerprint' => $fingerprint,
                'expires_at' => null,
            ]);
        });
    }

    public function release(
        int $orderId,
        int $productId,
        string $providerKey,
        string $capacityKey,
        int $quantity = 1,
        ?int $orderItemId = null,
        ?string $requirementsFingerprint = null,
    ): void {
        if ($quantity < 1) {
            return;
        }

        DB::transaction(function () use ($orderId, $productId, $providerKey, $capacityKey, $quantity, $orderItemId, $requirementsFingerprint): void {
            $this->lock($capacityKey);
            $reservationQuery = CapacityReservation::query()
                ->where('order_id', $orderId)
                ->where('product_id', $productId)
                ->where('provider_key', $providerKey)
                ->where('capacity_key', $capacityKey);
            $reservationQuery = $this->scopeOrderItem($reservationQuery, $orderItemId);
            $reservationQuery = $this->scopeRequirementsFingerprint($reservationQuery, $requirementsFingerprint);
            $remaining = $quantity;
            foreach ($reservationQuery->orderBy('id')->lockForUpdate()->get() as $reservation) {
                $released = min($remaining, (int) $reservation->quantity);
                if ($released >= (int) $reservation->quantity) {
                    $reservation->delete();
                } else {
                    $reservation->decrement('quantity', $released);
                }
                $remaining -= $released;
                if ($remaining === 0) {
                    break;
                }
            }
        });
    }

    public function commitForInstance(
        ServiceInstance $instance,
        ?string $capacityKeyOverride = null,
        ?array $requirementsOverride = null,
        ?string $previousCapacityKeyOverride = null,
        ?array $previousRequirementsOverride = null,
        ?int $previousProductIdOverride = null,
        ?string $previousProviderKeyOverride = null,
    ): void {
        $capacityKey = $capacityKeyOverride ?? $this->capacityKey($instance);
        if ($capacityKey === null || $instance->order_id === null || $instance->product_id === null || $instance->provider_key === null) {
            return;
        }

        $requirementsFingerprint = $requirementsOverride !== null
            ? $this->requirementsFingerprint($requirementsOverride)
            : $this->instanceRequirementsFingerprint($instance);
        $previousRequirementsFingerprint = $previousRequirementsOverride !== null
            ? $this->requirementsFingerprint($previousRequirementsOverride)
            : null;
        $previousProductId = $previousProductIdOverride ?? $instance->product_id;
        $previousProviderKey = $previousProviderKeyOverride ?? $instance->provider_key;
        if ($previousCapacityKeyOverride !== null
            && $previousProductId !== null
            && $previousProviderKey !== null
            && $previousRequirementsOverride === null
        ) {
            throw ValidationException::withMessages([
                'instance' => __('provisioning::errors.provider_failed'),
            ]);
        }
        DB::transaction(function () use ($instance, $capacityKey, $requirementsFingerprint, $previousCapacityKeyOverride, $previousRequirementsFingerprint, $previousRequirementsOverride, $previousProductId, $previousProviderKey): void {
            $capacityKeys = array_values(array_unique(array_filter([$capacityKey, $previousCapacityKeyOverride])));
            sort($capacityKeys, SORT_STRING);
            foreach ($capacityKeys as $key) {
                $this->lock($key);
            }
            $reservationQuery = CapacityReservation::query()
                ->where('order_id', $instance->order_id)
                ->where('product_id', $instance->product_id)
                ->where('provider_key', $instance->provider_key)
                ->where('capacity_key', $capacityKey)
                ->where('quantity', '>', 0);
            $reservationQuery = $this->scopeOrderItem($reservationQuery, $instance->order_item_id);
            $reservationQuery = $this->scopeRequirementsFingerprint($reservationQuery, $requirementsFingerprint);
            $remainingTarget = 1;
            foreach ($reservationQuery->lockForUpdate()->get() as $reservation) {
                $consumed = min($remainingTarget, (int) $reservation->quantity);
                if ($consumed >= (int) $reservation->quantity) {
                    $reservation->delete();
                } else {
                    $reservation->decrement('quantity', $consumed);
                }
                $remainingTarget -= $consumed;
                if ($remainingTarget === 0) {
                    break;
                }
            }

            if ($remainingTarget !== 0) {
                throw ValidationException::withMessages([
                    'instance' => __('provisioning::errors.provider_failed'),
                ]);
            }

            if ($previousCapacityKeyOverride === null
                || $previousProductId === null
                || $previousProviderKey === null
                || ($previousCapacityKeyOverride === $capacityKey
                    && $previousRequirementsFingerprint === $requirementsFingerprint
                    && $previousRequirementsOverride !== null
                    && $previousProductId === $instance->product_id
                    && $previousProviderKey === $instance->provider_key)
            ) {
                return;
            }

            $previousQuery = CapacityReservation::query()
                ->where('order_id', $instance->order_id)
                ->where('product_id', $previousProductId)
                ->where('provider_key', $previousProviderKey)
                ->where('capacity_key', $previousCapacityKeyOverride)
                ->where('quantity', '>', 0);
            $previousQuery = $this->scopeOrderItem($previousQuery, $instance->order_item_id);
            $previousQuery = $this->scopeRequirementsFingerprint($previousQuery, $previousRequirementsFingerprint);
            $remaining = 1;
            foreach ($previousQuery->lockForUpdate()->get() as $previousReservation) {
                $released = min($remaining, (int) $previousReservation->quantity);
                if ($released >= (int) $previousReservation->quantity) {
                    $previousReservation->delete();
                } else {
                    $previousReservation->decrement('quantity', $released);
                }
                $remaining -= $released;
                if ($remaining === 0) {
                    break;
                }
            }

            if ($remaining !== 0) {
                throw ValidationException::withMessages([
                    'instance' => __('provisioning::errors.provider_failed'),
                ]);
            }
        });
    }

    public function releaseForOrder(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $capacityKeys = CapacityReservation::query()
                ->where('order_id', $order->id)
                ->distinct()
                ->pluck('capacity_key')
                ->filter(static fn (mixed $key): bool => is_string($key) && $key !== '')
                ->unique()
                ->sort()
                ->values();
            foreach ($capacityKeys as $capacityKey) {
                $this->lock($capacityKey);
            }

            CapacityReservation::query()->where('order_id', $order->id)->delete();
        });
    }

    public function assertPoolRequirementsCompatible(string $capacityKey, array $requirements): void
    {
        $this->purgeExpired($capacityKey);
        $normalizedRequirements = $this->normalizeRequirements($requirements);
        if ($normalizedRequirements === []) {
            return;
        }

        foreach (CapacityReservation::query()->where('capacity_key', $capacityKey)->where('quantity', '>', 0)->get() as $reservation) {
            $heldRequirements = $this->normalizeRequirements(is_array($reservation->requirements) ? $reservation->requirements : []);
            if ($heldRequirements !== $normalizedRequirements) {
                throw ValidationException::withMessages([
                    'cart' => __('provisioning::errors.provider_unavailable'),
                ]);
            }
        }
    }

    private function scopeOrderItem(mixed $query, ?int $orderItemId): mixed
    {
        if ($orderItemId === null) {
            return $query->whereNull('order_item_id');
        }

        return $query->where('order_item_id', $orderItemId);
    }

    private function scopeRequirementsFingerprint(mixed $query, ?string $fingerprint): mixed
    {
        return $fingerprint === null
            ? $query->whereNull('requirements_fingerprint')
            : $query->where('requirements_fingerprint', $fingerprint);
    }

    /** @param array<string, int|float> $total @param array<string, int|float|string> $subtract @return array<string, int|float> */
    private function subtractRequirements(array $total, array $subtract): array
    {
        foreach ($subtract as $key => $value) {
            $remaining = max(0, (float) ($total[$key] ?? 0) - (float) $value);
            $total[$key] = is_float($remaining) && floor($remaining) === $remaining ? (int) $remaining : $remaining;
        }
        ksort($total);

        return array_filter($total, static fn (int|float $value): bool => $value > 0);
    }

    private function assertReservationRequirementsCompatible(CapacityReservation $reservation, array $requirements): void
    {
        $heldRequirements = $this->normalizeRequirements(is_array($reservation->requirements) ? $reservation->requirements : []);
        if ($requirements !== [] && $heldRequirements !== $requirements) {
            throw ValidationException::withMessages([
                'cart' => __('provisioning::errors.provider_unavailable'),
            ]);
        }
    }

    /** @param array<string, int|float|string> $requirements */
    private function normalizeRequirements(array $requirements): array
    {
        $normalized = [];
        foreach ($requirements as $key => $value) {
            if (! is_string($key) || $key === '' || is_bool($value) || $value === null || is_array($value) || is_object($value)) {
                throw ValidationException::withMessages([
                    'capacity' => __('provisioning::errors.provider_unavailable'),
                ]);
            }
            if (is_string($value)) {
                $value = trim($value);
                if (! is_numeric($value)) {
                    if (! in_array($key, ['cpu_type', 'storage'], true)) {
                        throw ValidationException::withMessages([
                            'capacity' => __('provisioning::errors.provider_failed'),
                        ]);
                    }
                    $normalized[$key] = $value;

                    continue;
                }
            }
            if (! is_finite((float) $value) || (float) $value < 0) {
                throw ValidationException::withMessages([
                    'capacity' => __('provisioning::errors.provider_unavailable'),
                ]);
            }
            $normalized[$key] = is_int($value) || (is_string($value) && preg_match('/^\d+$/D', $value) === 1)
                ? (int) $value
                : (float) $value;
        }
        ksort($normalized);

        return $normalized;
    }

    public function requirementsFingerprint(array $requirements): ?string
    {
        $normalized = $this->normalizeRequirements($requirements);

        return $normalized === []
            ? null
            : hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $requirements */
    private function instanceRequirementsFingerprint(ServiceInstance $instance): ?string
    {
        $requirements = is_array($instance->meta)
            ? $instance->meta['provisioning_capacity_requirements'] ?? null
            : null;

        return is_array($requirements) ? $this->requirementsFingerprint($requirements) : null;
    }

    private function capacityKey(ServiceInstance $instance): ?string
    {
        $key = is_array($instance->meta) ? $instance->meta['provisioning_capacity_key'] ?? null : null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function purgeExpired(string $capacityKey): void
    {
        CapacityReservation::query()
            ->where('capacity_key', $capacityKey)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();
    }
}
