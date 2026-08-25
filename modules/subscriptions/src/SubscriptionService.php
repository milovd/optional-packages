<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions;

use Agovena\Modules\Subscriptions\Enums\RenewalStatus;
use Agovena\Modules\Subscriptions\Enums\SubscriptionInterval;
use Agovena\Modules\Subscriptions\Enums\SubscriptionStatus;
use Agovena\Modules\Subscriptions\Events\SubscriptionCancelled;
use Agovena\Modules\Subscriptions\Events\SubscriptionEnded;
use Agovena\Modules\Subscriptions\Models\Subscription;
use Agovena\Modules\Subscriptions\Models\SubscriptionRenewal;
use App\Agovena\Billing\ConsolidatedBillingLine;
use App\Agovena\Billing\ConsolidatedRenewalOrderBuilder;
use App\Agovena\Invoices\IssueInvoiceFromOrder;
use App\Agovena\Notifications\SendsCataloguedMail;
use App\Agovena\Orders\CancelUnpaidOrder;
use App\Agovena\Orders\UnpaidOrderCancelSource;
use App\Agovena\Payments\ChargeRecurringPayment;
use App\Agovena\Payments\CheckoutPaymentSelection;
use App\Agovena\Payments\RecurringChargeOutcome;
use App\Agovena\Payments\RecurringChargeResult;
use App\Agovena\PlanChanges\ApplyPlanChange;
use App\Agovena\Settings\SettingsRepository;
use App\Agovena\Subscriptions\ProcessesSubscriptionRenewals;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductPlanChangeRequest;
use App\Notifications\SubscriptionCancelledNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SubscriptionService implements ProcessesSubscriptionRenewals
{
    public function __construct(
        private readonly IssueInvoiceFromOrder $issueInvoice,
        private readonly ConsolidatedRenewalOrderBuilder $consolidatedOrderBuilder,
        private readonly ApplyPlanChange $applyPlanChange,
        private readonly CancelUnpaidOrder $cancelUnpaidOrder,
        private readonly ChargeRecurringPayment $chargeRecurring,
        private readonly SettingsRepository $settings,
    ) {}

    public function createFromPaidOrder(Order $order): void
    {
        $order->loadMissing('items', 'payment');

        if (SubscriptionRenewal::query()->where('order_id', $order->id)->exists()) {
            return;
        }

        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $product = Product::query()->with('capabilities')->find($item->product_id);
            if ($product === null || ! $product->hasCapability('subscribable')) {
                continue;
            }

            $exists = Subscription::query()
                ->where('order_item_id', $item->id)
                ->exists();
            if ($exists) {
                continue;
            }

            $capability = $product->capability('subscribable');
            $config = $capability !== null ? ($capability->config ?? []) : [];
            $interval = SubscriptionInterval::tryFrom((string) ($config['interval'] ?? 'month'))
                ?? SubscriptionInterval::Month;
            $intervalCount = max(1, (int) ($config['interval_count'] ?? 1));
            $trialDays = max(0, (int) ($config['trial_days'] ?? 0));

            $now = CarbonImmutable::now();
            $trialEnds = $trialDays > 0 ? $now->addDays($trialDays) : null;
            $periodStart = $trialEnds ?? $now;
            $periodEnd = $this->addInterval($periodStart, $interval, $intervalCount);

            $attributes = [
                'number' => $this->generateNumber(),
                'customer_id' => $order->customer_id,
                'customer_email' => $order->customer_email,
                'customer_name' => $order->customer_name,
                'product_id' => $product->id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'status' => SubscriptionStatus::Active,
                'interval' => $interval,
                'interval_count' => $intervalCount,
                'price_amount' => $item->unit_amount,
                'currency' => $item->currency,
                'quantity' => $item->quantity,
                'trial_ends_at' => $trialEnds,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'next_billing_at' => $periodEnd,
                'cancel_at_period_end' => false,
            ];
            if (Schema::hasColumn('subscriptions', 'payment_gateway')) {
                $attributes['payment_gateway'] = $this->gatewayIdFromOrder($order);
            }

            $subscription = Subscription::query()->create($attributes);

            $this->linkServiceInstances((int) $subscription->id, $item->id);
        }
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd = true): Subscription
    {
        if (! $subscription->canCancel()) {
            throw ValidationException::withMessages([
                'subscription' => __('subscriptions::errors.cannot_cancel'),
            ]);
        }

        if ($atPeriodEnd && $subscription->status === SubscriptionStatus::Active) {
            $subscription->cancel_at_period_end = true;
            $subscription->cancelled_at = now();
            $subscription->save();
            $this->notifyCancellation($subscription, true);
            event(new SubscriptionCancelled($subscription, true));

            return $subscription->fresh() ?? $subscription;
        }

        $subscription->status = SubscriptionStatus::Cancelled;
        $subscription->cancel_at_period_end = false;
        $subscription->cancelled_at = now();
        $subscription->ended_at = now();
        $subscription->next_billing_at = null;
        $subscription->save();
        $this->notifyCancellation($subscription, false);
        $fresh = $subscription->fresh() ?? $subscription;
        event(new SubscriptionCancelled($fresh, false));
        event(new SubscriptionEnded($fresh));

        return $fresh;
    }

    public function resume(Subscription $subscription): Subscription
    {
        if (! $subscription->cancel_at_period_end || $subscription->status !== SubscriptionStatus::Active) {
            throw ValidationException::withMessages([
                'subscription' => __('subscriptions::errors.cannot_resume'),
            ]);
        }

        $subscription->cancel_at_period_end = false;
        $subscription->cancelled_at = null;
        $subscription->save();

        return $subscription->fresh() ?? $subscription;
    }

    /**
     * Create at most one renewal order per due period. Safe under overlapping scheduler runs.
     */
    public function processDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $processed = 0;
        $windowEnd = $now->addDays($this->consolidationWindowDays());

        $lock = Cache::lock('agovena.subscriptions.process-due', 120);
        if (! $lock->get()) {
            return 0;
        }

        try {
            $ids = Subscription::query()
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
                ->where(function ($query) use ($now, $windowEnd): void {
                    $query->where(function ($candidate) use ($windowEnd): void {
                        $candidate->whereNotNull('next_billing_at')
                            ->where('next_billing_at', '<=', $windowEnd);
                    })->orWhere(function ($overdue) use ($now): void {
                        $overdue->whereNotNull('current_period_end')
                            ->where('current_period_end', '<=', $now);
                    });
                })
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids as $id) {
                DB::transaction(function () use ($id): void {
                    /** @var Subscription|null $subscription */
                    $subscription = Subscription::query()->whereKey($id)->lockForUpdate()->first();
                    if ($subscription !== null) {
                        $this->applyDuePlanChanges($subscription);
                    }
                });
            }

            $subscriptions = Subscription::query()
                ->whereIn('id', $ids)
                ->with('product')
                ->get();
            $processedSubscriptionIds = [];

            foreach ($subscriptions->groupBy(fn (Subscription $subscription): string => $this->consolidationKey($subscription)) as $group) {
                /** @var EloquentCollection<int, Subscription> $group */
                $consolidated = $this->processConsolidatedGroup($group, $now, $windowEnd);
                if ($consolidated === []) {
                    continue;
                }

                $processedSubscriptionIds = array_merge($processedSubscriptionIds, $consolidated);
                $processed += count($consolidated);
            }

            foreach ($ids as $id) {
                if (in_array((int) $id, $processedSubscriptionIds, true)) {
                    continue;
                }

                DB::transaction(function () use ($id, $now, &$processed): void {
                    /** @var Subscription|null $subscription */
                    $subscription = Subscription::query()->whereKey($id)->lockForUpdate()->first();
                    if ($subscription === null) {
                        return;
                    }

                    if ($subscription->cancel_at_period_end) {
                        $periodEnd = $subscription->current_period_end;
                        if ($periodEnd !== null && CarbonImmutable::parse($periodEnd)->lessThanOrEqualTo($now)) {
                            $this->cancelPendingRenewals($subscription);
                            $this->end($subscription);
                            $processed++;
                        }

                        return;
                    }

                    $dueAt = $subscription->next_billing_at;
                    if ($dueAt !== null && CarbonImmutable::parse($dueAt)->lessThanOrEqualTo($now)) {
                        $created = ! $this->hasPendingRenewal($subscription);
                        $order = $this->ensureRenewalOrder($subscription);
                        $this->processRenewalCharge($subscription->fresh() ?? $subscription, $order, $created, $now);
                        $this->markPastDueIfUnpaid($subscription->fresh() ?? $subscription, $now);
                        $processed++;
                    }
                });
            }
        } finally {
            $lock->release();
        }

        return $processed;
    }

    /**
     * @param EloquentCollection<int, Subscription> $group
     * @return list<int>
     */
    private function processConsolidatedGroup(
        EloquentCollection $group,
        CarbonImmutable $now,
        CarbonImmutable $windowEnd,
    ): array {
        if ($group->count() < 2) {
            return [];
        }

        $ids = array_map('intval', $group->modelKeys());
        $result = DB::transaction(function () use ($ids, $now, $windowEnd): ?array {
            $locked = Subscription::query()
                ->whereIn('id', $ids)
                ->with('product')
                ->lockForUpdate()
                ->get();
            $eligible = $locked->filter(function (Subscription $subscription) use ($now, $windowEnd): bool {
                if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
                    return false;
                }

                if ($subscription->cancel_at_period_end || $subscription->next_billing_at === null) {
                    return false;
                }

                if (CarbonImmutable::parse($subscription->next_billing_at)->greaterThan($windowEnd)) {
                    return false;
                }

                return ! $this->hasPendingRenewal($subscription);
            })->values();
            $due = $eligible->filter(fn (Subscription $subscription): bool => $this->isDue($subscription, $now));

            if ($eligible->count() < 2 || $due->isEmpty()) {
                return null;
            }

            $earliestDue = null;
            foreach ($eligible as $subscription) {
                $dueAt = CarbonImmutable::parse($subscription->next_billing_at);
                if ($earliestDue === null || $dueAt->lessThan($earliestDue)) {
                    $earliestDue = $dueAt;
                }
            }

            if ($earliestDue === null) {
                return null;
            }

            $lines = [];
            $periodEnds = [];
            foreach ($eligible as $subscription) {
                $dueAt = CarbonImmutable::parse($subscription->next_billing_at);
                $periodEnd = $this->addInterval($earliestDue, $subscription->interval, $subscription->interval_count);
                $periodStart = $subscription->current_period_start !== null
                    ? CarbonImmutable::parse($subscription->current_period_start)
                    : $dueAt->subDays(max(1, (int) $dueAt->diffInDays($periodEnd)));
                $periodDays = max(1, (int) $periodStart->diffInDays(CarbonImmutable::parse($subscription->current_period_end ?? $dueAt)));
                $originItem = $subscription->order_item_id !== null
                    ? OrderItem::query()->find($subscription->order_item_id)
                    : null;
                $product = $subscription->product;

                $lines[] = new ConsolidatedBillingLine(
                    sourceType: 'subscription',
                    sourceId: (int) $subscription->id,
                    customerId: $subscription->customer_id,
                    customerName: (string) ($subscription->customer_name ?? $subscription->customer_email),
                    customerEmail: (string) $subscription->customer_email,
                    currency: strtoupper((string) $subscription->currency),
                    gatewayId: $this->gatewayIdFromSubscription($subscription),
                    productId: $subscription->product_id,
                    originOrderItemId: $subscription->order_item_id,
                    label: $product !== null ? $product->name : (string) __('subscriptions::admin.renewal_item'),
                    quantity: max(1, (int) $subscription->quantity),
                    unitAmount: max(0, (int) $subscription->price_amount),
                    dueAt: $dueAt,
                    nextPeriodEnd: $periodEnd,
                    periodDays: $periodDays,
                    daysAlreadyPaid: max(0, (int) $earliestDue->diffInDays($dueAt)),
                    optionsSnapshot: $originItem?->options_snapshot ?? [],
                );
                $periodEnds[(int) $subscription->id] = $periodEnd;
            }

            $order = $this->consolidatedOrderBuilder->create($lines);
            foreach ($eligible as $subscription) {
                SubscriptionRenewal::query()->firstOrCreate(
                    [
                        'subscription_id' => $subscription->id,
                        'period_start' => $earliestDue,
                    ],
                    [
                        'order_id' => $order->id,
                        'period_end' => $periodEnds[(int) $subscription->id],
                        'status' => RenewalStatus::Pending,
                    ],
                );
            }

            return [
                'subscription_ids' => array_map('intval', $eligible->modelKeys()),
                'order_id' => (int) $order->id,
            ];
        });

        if ($result === null) {
            return [];
        }

        $order = Order::query()->with('payment')->findOrFail($result['order_id']);
        $primary = Subscription::query()->findOrFail($result['subscription_ids'][0]);
        $this->processRenewalCharge($primary, $order, true, $now);
        foreach ($result['subscription_ids'] as $subscriptionId) {
            $subscription = Subscription::query()->find($subscriptionId);
            if ($subscription !== null) {
                $this->markPastDueIfUnpaid($subscription, $now);
            }
        }

        return $result['subscription_ids'];
    }

    private function isDue(Subscription $subscription, CarbonImmutable $now): bool
    {
        $nextBillingAt = $subscription->next_billing_at;
        if ($nextBillingAt !== null && CarbonImmutable::parse($nextBillingAt)->lessThanOrEqualTo($now)) {
            return true;
        }

        $periodEnd = $subscription->current_period_end;

        return $periodEnd !== null && CarbonImmutable::parse($periodEnd)->lessThanOrEqualTo($now);
    }

    private function consolidationKey(Subscription $subscription): string
    {
        return implode('|', [
            (string) $subscription->customer_id,
            strtolower((string) $subscription->customer_email),
            strtoupper((string) $subscription->currency),
            $this->gatewayIdFromSubscription($subscription),
            $subscription->interval->value,
            (string) $subscription->interval_count,
        ]);
    }

    public function ensureRenewalOrder(Subscription $subscription): Order
    {
        $pending = SubscriptionRenewal::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', RenewalStatus::Pending)
            ->first();

        if ($pending !== null && $pending->order_id !== null) {
            $existing = Order::query()->with(['items', 'payment'])->find($pending->order_id);
            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->createRenewalOrder($subscription);
    }

    public function markPastDue(Subscription $subscription): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::Active) {
            throw ValidationException::withMessages([
                'subscription' => __('subscriptions::errors.cannot_mark_past_due'),
            ]);
        }

        $subscription->status = SubscriptionStatus::PastDue;
        $subscription->save();

        $fresh = $subscription->fresh() ?? $subscription;
        app(SendsCataloguedMail::class)->toOrderCustomer(
            $fresh->customer_id,
            (string) $fresh->customer_email,
            'subscription_past_due',
            [
                'name' => (string) ($fresh->customer_name ?? $fresh->customer_email),
                'number' => $fresh->number,
                'detail' => $fresh->number,
                'action_url' => Route::has('customer.subscriptions') ? route('customer.subscriptions') : url('/'),
                'action_label' => __('notifications.subscription_past_due.action'),
            ],
        );

        return $fresh;
    }

    public function end(Subscription $subscription): Subscription
    {
        if ($subscription->status === SubscriptionStatus::Ended) {
            return $subscription;
        }

        $subscription->status = SubscriptionStatus::Ended;
        $subscription->ended_at = now();
        $subscription->next_billing_at = null;
        $subscription->save();

        $fresh = $subscription->fresh() ?? $subscription;
        event(new SubscriptionEnded($fresh));

        return $fresh;
    }

    /**
     * Create a pending renewal Order for the next billing period.
     */
    public function createRenewalOrder(Subscription $subscription): Order
    {
        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
            throw ValidationException::withMessages([
                'subscription' => __('subscriptions::errors.cannot_renew'),
            ]);
        }

        if ($subscription->cancel_at_period_end) {
            throw ValidationException::withMessages([
                'subscription' => __('subscriptions::errors.renew_cancelled'),
            ]);
        }

        $pending = SubscriptionRenewal::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', RenewalStatus::Pending)
            ->exists();
        if ($pending) {
            throw ValidationException::withMessages([
                'subscription' => __('subscriptions::errors.renewal_pending'),
            ]);
        }

        $periodStart = CarbonImmutable::parse($subscription->current_period_end ?? now());
        $periodEnd = $this->addInterval($periodStart, $subscription->interval, $subscription->interval_count);

        $existingPeriod = SubscriptionRenewal::query()
            ->where('subscription_id', $subscription->id)
            ->where('period_start', $periodStart)
            ->first();
        if ($existingPeriod !== null && $existingPeriod->order_id !== null) {
            $existingOrder = Order::query()->with(['items', 'payment'])->find($existingPeriod->order_id);
            if ($existingOrder !== null) {
                return $existingOrder;
            }
        }

        $lineTotal = $subscription->price_amount * $subscription->quantity;
        $gatewayId = $this->gatewayIdFromSubscription($subscription);

        try {
            return DB::transaction(function () use ($subscription, $periodStart, $periodEnd, $lineTotal, $gatewayId): Order {
                $order = Order::query()->create([
                    'number' => $this->generateOrderNumber(),
                    'status' => OrderStatus::Pending,
                    'customer_id' => $subscription->customer_id,
                    'customer_name' => $subscription->customer_name ?? $subscription->customer_email,
                    'customer_email' => $subscription->customer_email,
                    'currency' => $subscription->currency,
                    'subtotal_amount' => $lineTotal,
                    'shipping_amount' => 0,
                    'total_amount' => $lineTotal,
                    'shipping_same_as_billing' => true,
                ]);

                $product = $subscription->product;
                $label = $product !== null
                    ? $product->name
                    : (string) __('subscriptions::admin.renewal_item');
                $originItem = $subscription->order_item_id !== null
                    ? OrderItem::query()->find($subscription->order_item_id)
                    : null;
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $subscription->product_id,
                    'label' => $label,
                    'quantity' => $subscription->quantity,
                    'unit_amount' => $subscription->price_amount,
                    'line_total_amount' => $lineTotal,
                    'currency' => $subscription->currency,
                    'options_snapshot' => $originItem->options_snapshot ?? [],
                ]);

                Payment::query()->create([
                    'order_id' => $order->id,
                    'method' => $gatewayId,
                    'status' => PaymentStatus::Pending,
                    'amount' => $lineTotal,
                    'currency' => $subscription->currency,
                ]);

                SubscriptionRenewal::query()->create([
                    'subscription_id' => $subscription->id,
                    'order_id' => $order->id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'status' => RenewalStatus::Pending,
                ]);

                $created = $order->fresh(['items', 'payment']) ?? $order;
                $this->issueInvoice->handle($created);

                return $created;
            });
        } catch (UniqueConstraintViolationException $e) {
            $winner = SubscriptionRenewal::query()
                ->where('subscription_id', $subscription->id)
                ->where('period_start', $periodStart)
                ->first();
            if ($winner?->order_id !== null) {
                $existingOrder = Order::query()->with(['items', 'payment'])->find($winner->order_id);
                if ($existingOrder !== null) {
                    return $existingOrder;
                }
            }

            throw $e;
        }
    }

    public function processRenewalCharge(Subscription $subscription, Order $order, bool $isNewOrder, ?CarbonImmutable $now = null): RecurringChargeResult
    {
        $now ??= CarbonImmutable::now();
        $renewal = SubscriptionRenewal::query()
            ->where('subscription_id', $subscription->id)
            ->where('order_id', $order->id)
            ->where('status', RenewalStatus::Pending)
            ->first();

        if ($renewal === null) {
            return RecurringChargeResult::skipped('no_pending_renewal');
        }

        $order->loadMissing('payment');
        if ($order->payment?->status === PaymentStatus::Paid) {
            return RecurringChargeResult::charged();
        }

        if (! $this->autoChargeEnabled()) {
            $this->markManualPaymentRequiredForOrder($order);
            if ($isNewOrder) {
                $this->notifyRenewalPayableForOrder($order);
            }

            return RecurringChargeResult::skipped('auto_charge_disabled');
        }

        if ($renewal->require_manual_payment) {
            if ($isNewOrder) {
                $this->notifyRenewalPayableForOrder($order);
            }

            return RecurringChargeResult::skipped('manual_required');
        }

        $maxAttempts = $this->retryMax();
        if ((int) $renewal->charge_attempts >= $maxAttempts) {
            $this->applyRetriesExhaustedForOrder($order);

            return RecurringChargeResult::skipped('retries_exhausted');
        }

        $waiting = $renewal->next_retry_at !== null && CarbonImmutable::parse($renewal->next_retry_at)->greaterThan($now);
        if ($waiting) {
            if ($this->hasReconcileableAttempt($order)) {
                $result = $this->chargeRecurring->handle($order);
                $this->applyChargeResult($subscription, $order, $renewal->fresh() ?? $renewal, $result, false, $now);

                return $result;
            }

            return RecurringChargeResult::skipped('retry_waiting');
        }

        $result = $this->chargeRecurring->handle($order);
        $this->applyChargeResult($subscription, $order, $renewal->fresh() ?? $renewal, $result, $isNewOrder, $now);

        return $result;
    }

    public function applyPaidRenewal(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $renewals = SubscriptionRenewal::query()
                ->where('order_id', $order->id)
                ->where('status', RenewalStatus::Pending)
                ->lockForUpdate()
                ->get();

            if ($renewals->isEmpty()) {
                return;
            }

            $autoCharged = $this->wasAutoCharged($order);
            foreach ($renewals as $renewal) {
                /** @var Subscription $subscription */
                $subscription = Subscription::query()->whereKey($renewal->subscription_id)->firstOrFail();

                $renewal->status = RenewalStatus::Paid;
                $renewal->save();

                $subscription->status = SubscriptionStatus::Active;
                $subscription->current_period_start = $renewal->period_start;
                $subscription->current_period_end = $renewal->period_end;
                $subscription->next_billing_at = $renewal->period_end;
                $subscription->save();

                if ($autoCharged) {
                    $this->notifyRenewalPaid($subscription, $order);
                }
            }
        });
    }

    private function wasAutoCharged(Order $order): bool
    {
        $payment = $order->payment;
        if ($payment === null) {
            return false;
        }

        return PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->where('status', PaymentAttemptStatus::Succeeded)
            ->get()
            ->contains(static function (PaymentAttempt $attempt): bool {
                return ($attempt->request_meta['purpose'] ?? null) === 'recurring';
            });
    }

    public function addInterval(CarbonImmutable $from, SubscriptionInterval $interval, int $count): CarbonImmutable
    {
        return match ($interval) {
            SubscriptionInterval::Day => $from->addDays($count),
            SubscriptionInterval::Week => $from->addWeeks($count),
            SubscriptionInterval::Month => $from->addMonthsNoOverflow($count),
            SubscriptionInterval::Year => $from->addYearsNoOverflow($count),
        };
    }

    private function generateNumber(): string
    {
        do {
            $number = 'SUB-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (Subscription::query()->where('number', $number)->exists());

        return $number;
    }

    private function generateOrderNumber(): string
    {
        do {
            $number = 'REN-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (Order::query()->where('number', $number)->exists());

        return $number;
    }

    private function notifyCancellation(Subscription $subscription, bool $atPeriodEnd): void
    {
        $customer = $subscription->customer;
        $name = $customer !== null
            ? (string) $customer->name
            : (string) ($subscription->customer_name ?? '');
        $notification = new SubscriptionCancelledNotification(
            $subscription->number,
            $atPeriodEnd,
            $name,
        );

        if ($customer !== null) {
            $customer->notify($notification);

            return;
        }

        Notification::route('mail', $subscription->customer_email)->notify($notification);
    }

    private function applyDuePlanChanges(Subscription $subscription): void
    {
        $pending = ProductPlanChangeRequest::query()
            ->where('subscription_id', $subscription->id)
            ->where('timing', 'next_period')
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        foreach ($pending as $request) {
            $this->applyPlanChange->handle($request);
        }

        $subscription->refresh();
    }

    private function cancelPendingRenewals(Subscription $subscription): void
    {
        $renewals = SubscriptionRenewal::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', RenewalStatus::Pending)
            ->get();

        foreach ($renewals as $renewal) {
            $renewal->status = RenewalStatus::Cancelled;
            $renewal->save();

            if ($renewal->order_id === null) {
                continue;
            }

            $order = Order::query()->with(['invoice', 'payment'])->whereKey($renewal->order_id)->lockForUpdate()->first();
            if ($order !== null && $order->isAwaitingPayment()) {
                $this->cancelUnpaidOrder->handle($order, UnpaidOrderCancelSource::Scheduler);
            }
        }
    }

    private function markPastDueIfUnpaid(Subscription $subscription, CarbonImmutable $now): void
    {
        if ($subscription->status !== SubscriptionStatus::Active) {
            return;
        }

        $periodEnd = $subscription->current_period_end;
        if ($periodEnd === null || CarbonImmutable::parse($periodEnd)->greaterThan($now)) {
            return;
        }

        $unpaid = SubscriptionRenewal::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', RenewalStatus::Pending)
            ->exists();

        if ($unpaid) {
            $order = $this->pendingRenewalOrder($subscription);
            if ($order === null || ! $this->hasReconcileableAttempt($order)) {
                $this->markPastDue($subscription);
            }
        }
    }

    private function pendingRenewalOrder(Subscription $subscription): ?Order
    {
        $renewal = SubscriptionRenewal::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', RenewalStatus::Pending)
            ->first();

        if ($renewal?->order_id === null) {
            return null;
        }

        return Order::query()->with('payment')->find($renewal->order_id);
    }

    private function hasPendingRenewal(Subscription $subscription): bool
    {
        return SubscriptionRenewal::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', RenewalStatus::Pending)
            ->exists();
    }

    private function hasReconcileableAttempt(Order $order): bool
    {
        if ($order->payment === null) {
            return false;
        }

        return PaymentAttempt::query()
            ->where('payment_id', $order->payment->id)
            ->whereIn('status', [PaymentAttemptStatus::Pending, PaymentAttemptStatus::Processing])
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->exists();
    }

    private function pendingRenewalsForOrder(Order $order): EloquentCollection
    {
        return SubscriptionRenewal::query()
            ->where('order_id', $order->id)
            ->where('status', RenewalStatus::Pending)
            ->get();
    }

    private function markManualPaymentRequiredForOrder(Order $order, ?string $message = null): void
    {
        foreach ($this->pendingRenewalsForOrder($order) as $renewal) {
            $this->markManualPaymentRequired($renewal, $message);
        }
    }

    private function applyRetriesExhaustedForOrder(Order $order): void
    {
        foreach ($this->pendingRenewalsForOrder($order) as $renewal) {
            /** @var Subscription $subscription */
            $subscription = Subscription::query()->whereKey($renewal->subscription_id)->firstOrFail();
            $this->applyRetriesExhausted($subscription, $renewal);
        }
    }

    private function notifyRenewalPayableForOrder(Order $order): void
    {
        foreach ($this->pendingRenewalsForOrder($order) as $renewal) {
            $subscription = Subscription::query()->whereKey($renewal->subscription_id)->first();
            if ($subscription !== null) {
                $this->notifyRenewalPayable($subscription, $order);
            }
        }
    }

    private function applyChargeResult(
        Subscription $subscription,
        Order $order,
        SubscriptionRenewal $renewal,
        RecurringChargeResult $result,
        bool $isNewOrder,
        CarbonImmutable $now,
    ): void {
        $renewals = $this->pendingRenewalsForOrder($order);
        if ($renewals->isEmpty()) {
            $renewals = new EloquentCollection([$renewal]);
        }

        foreach ($renewals as $currentRenewal) {
            $currentRenewal = $currentRenewal->fresh() ?? $currentRenewal;
            $currentRenewal->last_charged_at = $now;

            if ($result->outcome === RecurringChargeOutcome::Charged) {
                $currentRenewal->auto_charge_attempted = true;
                $currentRenewal->last_error = null;
                $currentRenewal->next_retry_at = null;
                $currentRenewal->save();

                continue;
            }

            if ($result->outcome === RecurringChargeOutcome::Pending) {
                $currentRenewal->auto_charge_attempted = true;
                $currentRenewal->save();

                continue;
            }

            $currentSubscription = Subscription::query()->whereKey($currentRenewal->subscription_id)->firstOrFail();
            if ($result->authorizationMissing || $result->outcome === RecurringChargeOutcome::Skipped) {
                $this->markManualPaymentRequired($currentRenewal, $result->message);
                if ($isNewOrder) {
                    $this->notifyRenewalPayable($currentSubscription, $order);
                }

                continue;
            }

            $currentRenewal->auto_charge_attempted = true;
            $currentRenewal->charge_attempts = (int) $currentRenewal->charge_attempts + 1;
            $currentRenewal->last_error = $this->safeError($result->message);
            if ((int) $currentRenewal->charge_attempts < $this->retryMax()) {
                $currentRenewal->next_retry_at = $now->addHours($this->retryHours());
                $currentRenewal->save();
            } else {
                $currentRenewal->next_retry_at = null;
                $currentRenewal->require_manual_payment = true;
                $currentRenewal->save();
                $this->applyRetriesExhaustedPolicy($currentSubscription);
            }

            if ($currentRenewal->failure_notified_at === null) {
                $this->notifyRenewalFailed($currentSubscription, $order);
                $currentRenewal->failure_notified_at = $now;
                $currentRenewal->save();
            }
        }
    }

    private function applyRetriesExhausted(
        Subscription $subscription,
        SubscriptionRenewal $renewal,
    ): void {
        $renewal = $renewal->fresh() ?? $renewal;
        if (! $renewal->require_manual_payment) {
            $this->markManualPaymentRequired($renewal);
        }

        $this->applyRetriesExhaustedPolicy($subscription);
    }

    private function applyRetriesExhaustedPolicy(Subscription $subscription): void
    {
        if ($this->retryExhaustedAction() !== 'cancel_at_period_end') {
            return;
        }

        $subscription = $subscription->fresh() ?? $subscription;
        if ($subscription->cancel_at_period_end) {
            return;
        }

        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
            return;
        }

        $subscription->cancel_at_period_end = true;
        $subscription->cancelled_at = $subscription->cancelled_at ?? now();
        $subscription->save();
        $this->notifyCancellation($subscription, true);
        event(new SubscriptionCancelled($subscription, true));
    }

    private function markManualPaymentRequired(SubscriptionRenewal $renewal, ?string $message = null): void
    {
        $renewal->require_manual_payment = true;
        $renewal->next_retry_at = null;
        if ($message !== null && $message !== '') {
            $renewal->last_error = $this->safeError($message);
        }
        $renewal->save();
    }

    private function notifyRenewalPayable(Subscription $subscription, Order $order): void
    {
        app(SendsCataloguedMail::class)->toOrderCustomer(
            $subscription->customer_id,
            (string) $subscription->customer_email,
            'subscription_renewal',
            [
                'name' => (string) ($subscription->customer_name ?? $subscription->customer_email),
                'number' => $subscription->number,
                'detail' => $order->number,
                'action_url' => route('customer.orders.show', $order),
                'action_label' => __('notifications.subscription_renewal.action'),
            ],
        );
    }

    private function notifyRenewalFailed(Subscription $subscription, Order $order): void
    {
        app(SendsCataloguedMail::class)->toOrderCustomer(
            $subscription->customer_id,
            (string) $subscription->customer_email,
            'subscription_renewal_failed',
            [
                'name' => (string) ($subscription->customer_name ?? $subscription->customer_email),
                'number' => $subscription->number,
                'detail' => $order->number,
                'action_url' => route('customer.orders.show', $order),
                'action_label' => __('notifications.subscription_renewal_failed.action'),
            ],
        );
    }

    private function notifyRenewalPaid(Subscription $subscription, Order $order): void
    {
        app(SendsCataloguedMail::class)->toOrderCustomer(
            $subscription->customer_id,
            (string) $subscription->customer_email,
            'subscription_renewal_paid',
            [
                'name' => (string) ($subscription->customer_name ?? $subscription->customer_email),
                'number' => $subscription->number,
                'detail' => $order->number,
                'action_url' => route('customer.orders.show', $order),
                'action_label' => __('notifications.subscription_renewal_paid.action'),
            ],
        );
    }

    private function gatewayIdFromOrder(Order $order): string
    {
        $payment = $order->payment;
        $method = $payment !== null ? (string) $payment->method : 'manual';
        $gatewayId = CheckoutPaymentSelection::parse($method)->gatewayId;

        return $gatewayId !== '' ? $gatewayId : 'manual';
    }

    private function gatewayIdFromSubscription(Subscription $subscription): string
    {
        $stored = trim((string) ($subscription->payment_gateway ?? ''));
        if ($stored !== '') {
            return CheckoutPaymentSelection::parse($stored)->gatewayId;
        }

        if ($subscription->order_id !== null) {
            $origin = Order::query()->with('payment')->find($subscription->order_id);
            if ($origin !== null) {
                return $this->gatewayIdFromOrder($origin);
            }
        }

        return 'manual';
    }

    private function consolidationWindowDays(): int
    {
        return max(1, min(31, (int) $this->settings->get('store', 'subscription_consolidation_window_days', 31)));
    }

    private function autoChargeEnabled(): bool
    {
        return (bool) $this->settings->get('store', 'subscription_auto_charge', true);
    }

    private function retryMax(): int
    {
        return max(1, (int) $this->settings->get('store', 'subscription_retry_max', 3));
    }

    private function retryHours(): int
    {
        return max(1, (int) $this->settings->get('store', 'subscription_retry_hours', 24));
    }

    private function retryExhaustedAction(): string
    {
        $action = (string) $this->settings->get('store', 'subscription_retry_exhausted', 'manual');

        return in_array($action, ['manual', 'cancel_at_period_end'], true)
            ? $action
            : 'manual';
    }

    private function safeError(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }

        $trimmed = trim($message);
        if (strlen($trimmed) > 255) {
            $trimmed = substr($trimmed, 0, 252).'...';
        }

        return $trimmed;
    }

    private function linkServiceInstances(int $subscriptionId, int $orderItemId): void
    {
        if (! Schema::hasTable('service_instances')) {
            return;
        }

        DB::table('service_instances')
            ->where('order_item_id', $orderItemId)
            ->whereNull('subscription_id')
            ->update([
                'subscription_id' => $subscriptionId,
                'updated_at' => now(),
            ]);
    }
}
