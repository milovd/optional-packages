<?php

declare(strict_types=1);

namespace Agovena\Modules\DigitalDelivery;

use Agovena\Modules\DigitalDelivery\Models\DigitalSecretDelivery;
use Agovena\Modules\DigitalDelivery\Models\DigitalSecretItem;
use App\Agovena\Audit\AuditLogger;
use App\Agovena\Notifications\SendsCataloguedMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * Fulfils the `digital_secret` capability: hands out pooled keys/codes/credentials,
 * or records a pending manual/provider delivery for staff to complete.
 *
 * Plaintext secrets are never logged, never placed in mail bodies, and never
 * exposed through a URL - mail only links to the customer account page.
 */
final class DigitalSecretFulfillmentService
{
    public const CAPABILITY = 'digital_secret';

    public function __construct(
        private readonly SendsCataloguedMail $mail,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Refuse an order that would oversell a pool. Cart lines may repeat a product
     * (different purchase options), so quantities are summed per product first.
     *
     * @param  iterable<object{productId: int, quantity: int}>  $items
     */
    public function assertPoolAvailableForCart(iterable $items): void
    {
        /** @var array<int, int> $wanted */
        $wanted = [];

        foreach ($items as $item) {
            $productId = (int) $item->productId;
            $quantity = max(1, (int) $item->quantity);
            $wanted[$productId] = ($wanted[$productId] ?? 0) + $quantity;
        }

        foreach ($wanted as $productId => $quantity) {
            $product = Product::query()->with('capabilities')->find($productId);
            if ($product === null || ! $product->hasCapability(self::CAPABILITY)) {
                continue;
            }

            if ($this->sourceFor($product) !== DigitalSecretDelivery::SOURCE_POOL) {
                continue;
            }

            if ($this->availableCount($productId) < $quantity) {
                throw ValidationException::withMessages([
                    'product' => __('digital-delivery::errors.pool_exhausted', [
                        'product' => $product->name,
                    ]),
                ]);
            }
        }
    }

    /**
     * Deliver every digital_secret unit on a paid order. Idempotent per order item:
     * re-running only creates the shortfall, so webhook retries cannot double-allocate.
     */
    public function fulfillPaidOrder(Order $order): void
    {
        $delivered = DB::transaction(function () use ($order): int {
            $items = OrderItem::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->get();
            $delivered = 0;

            foreach ($items as $item) {
                if ($item->product_id === null) {
                    continue;
                }

                $product = Product::query()->with('capabilities')->find($item->product_id);
                if ($product === null || ! $product->hasCapability(self::CAPABILITY)) {
                    continue;
                }

                $source = $this->sourceFor($product);
                $providerId = $this->providerIdFor($product);
                $quantity = max(1, (int) $item->quantity);
                $existing = DigitalSecretDelivery::query()
                    ->where('order_id', $order->id)
                    ->where('order_item_id', $item->id)
                    ->count();

                for ($unit = $existing; $unit < $quantity; $unit++) {
                    if ($source === DigitalSecretDelivery::SOURCE_POOL) {
                        if ($this->deliverFromPool($order, $item, $product)) {
                            $delivered++;

                            continue;
                        }

                        $this->createPendingManual($order, $item, $product, DigitalSecretDelivery::SOURCE_POOL, null);

                        continue;
                    }

                    $this->createPendingManual($order, $item, $product, $source, $providerId);
                }
            }

            return $delivered;
        });

        if ($delivered > 0) {
            $this->notifyDelivered($order);
        }
    }

    /**
     * Staff completes a pending delivery with a value they hold. When that value is
     * already in the pool the matching pool item is consumed so it cannot be reused.
     */
    public function assignManual(DigitalSecretDelivery $delivery, string $plainValue, ?int $actorUserId = null): void
    {
        $normalized = DigitalSecretItem::normalize($plainValue);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'value' => __('digital-delivery::errors.value_required'),
            ]);
        }

        DB::transaction(function () use ($delivery, $normalized): void {
            /** @var DigitalSecretDelivery $locked */
            $locked = DigitalSecretDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isRevoked()) {
                throw ValidationException::withMessages([
                    'value' => __('digital-delivery::errors.delivery_revoked'),
                ]);
            }

            if ($locked->digital_secret_item_id === null && $locked->product_id !== null) {
                $poolItem = DigitalSecretItem::query()
                    ->where('product_id', $locked->product_id)
                    ->where('status', DigitalSecretItem::STATUS_AVAILABLE)
                    ->where('value_fingerprint', DigitalSecretItem::fingerprint($normalized))
                    ->lockForUpdate()
                    ->first();

                if ($poolItem instanceof DigitalSecretItem) {
                    $poolItem->status = DigitalSecretItem::STATUS_ALLOCATED;
                    $poolItem->allocated_at = now();
                    $poolItem->save();

                    $locked->digital_secret_item_id = $poolItem->id;
                }
            }

            $locked->setPlainValue($normalized);
            $locked->status = DigitalSecretDelivery::STATUS_DELIVERED;
            $locked->granted_at ??= now();
            $locked->save();

            $delivery->setRawAttributes($locked->getAttributes(), true);
        });

        $this->audit->log('digital_secret.assigned', $delivery, [
            'order_id' => $delivery->order_id,
            'actor_user_id' => $actorUserId,
        ]);

        $order = $delivery->order;
        if ($order instanceof Order) {
            $this->notifyDelivered($order);
        }
    }

    /**
     * Revoking withdraws customer access. The code is deliberately not returned to
     * the pool: a value a customer has already seen must be retired explicitly.
     */
    public function revoke(DigitalSecretDelivery $delivery): void
    {
        if ($delivery->isRevoked()) {
            return;
        }

        $delivery->status = DigitalSecretDelivery::STATUS_REVOKED;
        $delivery->revoked_at = now();
        $delivery->save();

        $this->audit->log('digital_secret.revoked', $delivery, [
            'order_id' => $delivery->order_id,
        ]);
    }

    /**
     * @return Builder<DigitalSecretDelivery>
     */
    public function deliveriesForCustomer(Customer $customer): Builder
    {
        return DigitalSecretDelivery::query()
            ->where(function (Builder $query) use ($customer): void {
                $query->where('customer_id', $customer->id)
                    ->orWhere(function (Builder $inner) use ($customer): void {
                        $inner->whereNull('customer_id')->where('customer_email', $customer->email);
                    });
            });
    }

    public function availableCount(int $productId): int
    {
        return DigitalSecretItem::query()
            ->where('product_id', $productId)
            ->where('status', DigitalSecretItem::STATUS_AVAILABLE)
            ->count();
    }

    public function allocatedCount(int $productId): int
    {
        return DigitalSecretItem::query()
            ->where('product_id', $productId)
            ->where('status', DigitalSecretItem::STATUS_ALLOCATED)
            ->count();
    }

    /**
     * Add merchant-supplied codes to a product pool, skipping values already present.
     *
     * @param  list<string>  $values
     * @return array{added: int, skipped: int}
     */
    public function addPoolItems(Product $product, array $values, ?string $label = null): array
    {
        $added = 0;
        $skipped = 0;
        $seen = [];

        foreach ($values as $value) {
            $normalized = DigitalSecretItem::normalize($value);
            if ($normalized === '') {
                continue;
            }

            $fingerprint = DigitalSecretItem::fingerprint($normalized);
            if (isset($seen[$fingerprint])) {
                $skipped++;

                continue;
            }
            $seen[$fingerprint] = true;

            $duplicate = DigitalSecretItem::query()
                ->where('product_id', $product->id)
                ->where('value_fingerprint', $fingerprint)
                ->exists();

            if ($duplicate) {
                $skipped++;

                continue;
            }

            $item = new DigitalSecretItem([
                'product_id' => $product->id,
                'label' => $label !== null && $label !== '' ? $label : null,
                'status' => DigitalSecretItem::STATUS_AVAILABLE,
            ]);
            $item->setPlainValue($normalized);
            $item->save();

            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    private function deliverFromPool(Order $order, OrderItem $item, Product $product): bool
    {
        return (bool) DB::transaction(function () use ($order, $item, $product): bool {
            $poolItem = DigitalSecretItem::query()
                ->where('product_id', $product->id)
                ->where('status', DigitalSecretItem::STATUS_AVAILABLE)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $poolItem instanceof DigitalSecretItem) {
                return false;
            }

            $plain = $poolItem->plainValue();
            if ($plain === null || $plain === '') {
                // Unreadable pool row (e.g. rotated APP_KEY): retire it rather than deliver nothing.
                $poolItem->status = DigitalSecretItem::STATUS_DISABLED;
                $poolItem->save();

                return false;
            }

            $poolItem->status = DigitalSecretItem::STATUS_ALLOCATED;
            $poolItem->allocated_at = now();
            $poolItem->save();

            $delivery = new DigitalSecretDelivery([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'product_id' => $product->id,
                'digital_secret_item_id' => $poolItem->id,
                'customer_id' => $order->customer_id,
                'customer_email' => (string) $order->customer_email,
                'source' => DigitalSecretDelivery::SOURCE_POOL,
                'status' => DigitalSecretDelivery::STATUS_DELIVERED,
                'granted_at' => now(),
            ]);
            $delivery->setPlainValue($plain);
            $delivery->save();

            return true;
        });
    }

    private function createPendingManual(
        Order $order,
        OrderItem $item,
        Product $product,
        string $source,
        ?string $providerId,
    ): void {
        DigitalSecretDelivery::query()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'customer_id' => $order->customer_id,
            'customer_email' => (string) $order->customer_email,
            'source' => $source,
            'status' => DigitalSecretDelivery::STATUS_PENDING_MANUAL,
            'provider_id' => $providerId,
        ]);
    }

    /**
     * Notification key `digital_secret_delivered` is a catalogued merchant template.
     * Core wires it into NotificationTemplateCatalog; placeholders stay non-sensitive
     * (name, number, action_url) because a secret must never travel by email.
     */
    private function notifyDelivered(Order $order): void
    {
        $this->mail->toOrderCustomer(
            $order->customer_id,
            (string) $order->customer_email,
            'digital_secret_delivered',
            [
                'name' => (string) $order->customer_name,
                'number' => $order->number,
                'detail' => $order->number,
                'action_url' => Route::has('customer.digital-secrets')
                    ? route('customer.digital-secrets')
                    : url('/'),
                'action_label' => __('digital-delivery::customer.mail_action'),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function capabilityConfig(Product $product): array
    {
        $config = $product->capability(self::CAPABILITY)?->config;

        return $config ?? [];
    }

    private function sourceFor(Product $product): string
    {
        $source = $this->capabilityConfig($product)['source'] ?? null;

        $known = [
            DigitalSecretDelivery::SOURCE_POOL,
            DigitalSecretDelivery::SOURCE_MANUAL,
            DigitalSecretDelivery::SOURCE_PROVIDER,
        ];

        return is_string($source) && in_array($source, $known, true)
            ? $source
            : DigitalSecretDelivery::SOURCE_POOL;
    }

    private function providerIdFor(Product $product): ?string
    {
        $providerId = $this->capabilityConfig($product)['provider_id'] ?? null;

        return is_string($providerId) && $providerId !== '' ? $providerId : null;
    }
}
