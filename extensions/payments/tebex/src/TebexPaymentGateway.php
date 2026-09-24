<?php

declare(strict_types=1);

namespace Agovena\Extensions\Tebex;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Support\MoneyFormatter;
use App\Agovena\Payments\ApplyNormalizedPaymentStatus;
use App\Agovena\Payments\ApplyProviderRefundEvent;
use App\Agovena\Payments\CheckoutPaymentMethod;
use App\Agovena\Payments\Contracts\HandlesProviderRefundEvents;
use App\Agovena\Payments\Contracts\ManagesProviderSubscriptions;
use App\Agovena\Payments\Contracts\OffersCheckoutMethods;
use App\Agovena\Payments\Contracts\PaymentGateway;
use App\Agovena\Payments\Contracts\ResolvesWebhookAttempts;
use App\Agovena\Payments\Contracts\SynchronizesPayments;
use App\Agovena\Payments\Contracts\ValidatesWebhookPayload;
use App\Agovena\Payments\HealthResult;
use App\Agovena\Payments\PaymentGatewayCapabilities;
use App\Agovena\Payments\PaymentInitiation;
use App\Agovena\Payments\PaymentInitiationResult;
use App\Agovena\Payments\ProviderRefundEvent;
use App\Agovena\Payments\ProviderSubscriptionEvent;
use App\Agovena\Payments\RefundRequest;
use App\Agovena\Payments\RefundResult;
use App\Agovena\Payments\WebhookPayload;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class TebexPaymentGateway implements HandlesProviderRefundEvents, ManagesProviderSubscriptions, OffersCheckoutMethods, PaymentGateway, ResolvesWebhookAttempts, SynchronizesPayments, ValidatesWebhookPayload
{
    public const ID = 'tebex';

    /** @var array<string, mixed>|null */
    private ?array $verifiedEvent = null;

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
        private readonly ApplyNormalizedPaymentStatus $applyStatus,
        private readonly ApplyProviderRefundEvent $applyRefundEvent,
        private readonly ?TebexApi $api = null,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'tebex::messages.gateway.label';
    }

    public function capabilities(): PaymentGatewayCapabilities
    {
        return new PaymentGatewayCapabilities(
            refunds: true,
            partialRefunds: false,
            recurring: true,
            webhooks: true,
            redirect: true,
            statusSync: true,
        );
    }

    public function checkoutMethods(): array
    {
        return [new CheckoutPaymentMethod(self::ID, self::ID.':tebex', $this->label())];
    }

    public function initiate(PaymentInitiation $request): PaymentInitiationResult
    {
        $api = $this->client();
        if ($api === null) {
            return PaymentInitiationResult::failed(__('tebex::messages.errors.not_configured'));
        }

        if ($this->renewalModeFor($request->order) === 'automatic' && ! $this->supportsRecurringOrder($request->order)) {
            return PaymentInitiationResult::failed(__('tebex::messages.errors.subscription_checkout_unsupported'));
        }

        $ident = null;
        try {
            $checkout = $api->createCheckout([
                'basket' => [
                    'first_name' => (string) $request->order->customer_name,
                    'email' => (string) $request->order->customer_email,
                    'return_url' => $request->cancelUrl,
                    'complete_url' => $request->returnUrl,
                    'custom' => [
                        'order_id' => (string) $request->order->id,
                        'payment_id' => (string) $request->payment->id,
                        'attempt_key' => $request->idempotencyKey,
                    ],
                ],
                'items' => $this->checkoutItems($request->order),
            ], $request->idempotencyKey);
            $ident = (string) ($checkout['ident'] ?? $checkout['id'] ?? '');
            if ($ident === '') {
                return PaymentInitiationResult::failed(__('tebex::messages.errors.create_failed'));
            }
        } catch (TebexProviderException $exception) {
            Log::warning('payment.initiate.failed', [
                'gateway_id' => self::ID,
                'order_id' => $request->order->id,
            ]);

            if ($exception->unknownOutcome) {
                return PaymentInitiationResult::unknown(metadata: [
                    'provider_outcome' => 'unknown',
                ]);
            }

            return PaymentInitiationResult::failed(__('tebex::messages.errors.create_failed'));
        }

        $checkoutUrl = $checkout['links']['checkout'] ?? null;
        if (! is_string($checkoutUrl) || $checkoutUrl === '') {
            return PaymentInitiationResult::failed(__('tebex::messages.errors.create_failed'));
        }

        return PaymentInitiationResult::redirect(
            url: $checkoutUrl,
            externalId: $ident,
            metadata: [
                'provider_status' => 'created',
                'basket_ident' => $ident,
                'provider_checkout_url' => $checkoutUrl,
            ],
        );
    }

    public function mapStatus(string $providerStatus): PaymentStatus
    {
        return TebexStatusMapper::fromWebhook($providerStatus);
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = $this->webhookSecret();
        $body = $request->getContent();
        if ($secret === null || ! TebexWebhookVerifier::verify($body, (string) $request->header('X-Signature', ''), $secret)) {
            return false;
        }

        $event = json_decode($body, true);
        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return false;
        }
        $this->verifiedEvent = $event;

        return true;
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $event = $this->verifiedEvent ?? json_decode($request->getContent(), true);
        if (! is_array($event)) {
            throw TebexProviderException::failed('tebex::messages.errors.webhook_invalid');
        }

        $subject = is_array($event['subject'] ?? null) ? $event['subject'] : [];
        $type = (string) ($event['type'] ?? '');
        $paymentSubject = $this->recurringPaymentSubject($subject, $type);
        $externalId = (string) ($subject['transaction_id'] ?? $paymentSubject['transaction_id'] ?? '');
        $statusId = is_scalar($subject['status']['id'] ?? null) ? $subject['status']['id'] : null;
        $status = $type === 'payment.refunded' && $statusId !== null
            ? TebexStatusMapper::fromPaymentStatusId($statusId)
            : ($type !== '' ? TebexStatusMapper::fromWebhook($type) : TebexStatusMapper::fromPaymentStatusId($statusId));

        return new WebhookPayload(
            externalEventId: (string) $event['id'],
            externalPaymentId: $externalId !== '' ? $externalId : null,
            status: $status,
            raw: [
                'type' => $type,
                'transaction_id' => $externalId,
                'price_paid' => $subject['price_paid'] ?? null,
                'products' => $subject['products'] ?? [],
                'custom' => $subject['custom'] ?? ($subject['custom_data'] ?? null),
                'recurring_payment_reference' => $subject['recurring_payment_reference'] ?? $subject['reference'] ?? null,
                'status_id' => $statusId,
                'status_description' => $subject['status']['description'] ?? null,
                'next_payment_at' => $subject['next_payment_at'] ?? null,
                'created_at' => $subject['created_at'] ?? null,
                'payment_sequence' => $subject['payment_sequence'] ?? null,
            ],
        );
    }

    public function resolveWebhookAttempt(WebhookPayload $payload): ?PaymentAttempt
    {
        $custom = is_array($payload->raw['custom'] ?? null) ? $payload->raw['custom'] : [];
        $paymentId = $custom['payment_id'] ?? null;
        if (! is_scalar($paymentId) || trim((string) $paymentId) === '') {
            return null;
        }

        return PaymentAttempt::query()
            ->where('gateway_id', self::ID)
            ->where('payment_id', (string) $paymentId)
            ->latest('id')
            ->first();
    }

    public function validateWebhookPayload(PaymentAttempt $attempt, WebhookPayload $payload): bool
    {
        $recurringReference = $payload->raw['recurring_payment_reference'] ?? null;
        if (is_string($recurringReference) && str_starts_with($recurringReference, 'tbx-r-')) {
            $this->rememberProviderSubscription($attempt, $recurringReference);
        }

        if ($payload->status !== PaymentStatus::Paid) {
            return true;
        }

        $payment = $attempt->payment;
        $custom = is_array($payload->raw['custom'] ?? null) ? $payload->raw['custom'] : [];
        if ($custom === []
            || (string) ($custom['payment_id'] ?? '') !== (string) $payment->id
            || (string) ($custom['order_id'] ?? '') !== (string) $payment->order_id) {
            return false;
        }

        $pricePaid = is_array($payload->raw['price_paid'] ?? null) ? $payload->raw['price_paid'] : [];
        if (strtoupper((string) ($pricePaid['currency'] ?? '')) !== strtoupper((string) $payment->currency)
            || self::minorUnits($pricePaid['amount'] ?? null) !== (int) $payment->amount) {
            return false;
        }

        $expectedQuantity = $payment->order->items->sum(static fn ($item): int => max(1, (int) $item->quantity));
        $actualQuantity = 0;
        foreach ((array) ($payload->raw['products'] ?? []) as $product) {
            if (! is_array($product) || ! isset($product['quantity']) || ! is_numeric($product['quantity'])) {
                return false;
            }
            $actualQuantity += max(0, (int) $product['quantity']);
        }

        return $actualQuantity > 0 && $expectedQuantity === $actualQuantity;
    }

    public function managesProviderSubscriptions(): bool
    {
        return true;
    }

    public function cancelProviderSubscription(string $externalId, bool $atPeriodEnd): void
    {
        $api = $this->client();
        if ($api === null || ! str_starts_with($externalId, 'tbx-r-')) {
            throw TebexProviderException::failed('tebex::messages.errors.subscription_action_failed');
        }

        try {
            // Tebex exposes one cancellation operation. The resulting webhook
            // is authoritative for whether the cancellation is immediate or
            // scheduled at the end of the current billing period.
            $api->cancelRecurringPayment($externalId);
        } catch (TebexProviderException $exception) {
            throw $exception;
        }
    }

    public function resumeProviderSubscription(string $externalId): void
    {
        $api = $this->client();
        if ($api === null || ! str_starts_with($externalId, 'tbx-r-')) {
            throw TebexProviderException::failed('tebex::messages.errors.subscription_action_failed');
        }

        $api->updateRecurringPaymentStatus($externalId, 'Active');
    }

    public function providerSubscriptionEvent(WebhookPayload $payload): ?ProviderSubscriptionEvent
    {
        $type = (string) ($payload->raw['type'] ?? '');
        $reference = (string) ($payload->raw['recurring_payment_reference'] ?? '');
        if (! str_starts_with($reference, 'tbx-r-')) {
            return null;
        }

        [$eventType, $status, $cancelAtPeriodEnd] = match ($type) {
            'recurring-payment.started' => ['subscription.started', 'active', false],
            'recurring-payment.renewed' => ['subscription.renewed', 'active', false],
            'recurring-payment.cancellation.requested' => ['subscription.cancellation_requested', 'active', true],
            'recurring-payment.cancellation.aborted' => ['subscription.cancellation_aborted', 'active', false],
            'recurring-payment.ended' => ['subscription.canceled', 'canceled', false],
            default => [null, null, null],
        };
        if ($eventType === null || $status === null) {
            return null;
        }

        $custom = is_array($payload->raw['custom'] ?? null) ? $payload->raw['custom'] : [];
        $nextPaymentAt = $payload->raw['next_payment_at'] ?? null;

        return new ProviderSubscriptionEvent(
            gatewayId: self::ID,
            externalSubscriptionId: $reference,
            eventType: $eventType,
            status: $status,
            transactionId: is_string($payload->raw['transaction_id'] ?? null) && $payload->raw['transaction_id'] !== ''
                ? $payload->raw['transaction_id']
                : null,
            originOrderId: is_scalar($custom['order_id'] ?? null) ? (string) $custom['order_id'] : null,
            periodStart: is_string($payload->raw['created_at'] ?? null) ? $payload->raw['created_at'] : null,
            nextBillingAt: is_string($nextPaymentAt) ? $nextPaymentAt : null,
            cancelAtPeriodEnd: $cancelAtPeriodEnd,
            customData: $custom,
        );
    }

    public function providerRefundEvent(WebhookPayload $payload): ?ProviderRefundEvent
    {
        if (($payload->raw['type'] ?? null) !== 'payment.refunded'
            || ! is_string($payload->raw['transaction_id'] ?? null)
            || $payload->raw['transaction_id'] === '') {
            return null;
        }

        $status = match ((int) ($payload->raw['status_id'] ?? 2)) {
            21 => 'pending',
            2 => 'approved',
            default => 'rejected',
        };

        return new ProviderRefundEvent(
            gatewayId: self::ID,
            externalRefundId: $payload->externalEventId,
            transactionId: $payload->raw['transaction_id'],
            status: $status,
        );
    }

    private static function minorUnits(mixed $amount): ?int
    {
        if (is_int($amount)) {
            return $amount * 100;
        }
        if (! is_string($amount) && ! is_float($amount)) {
            return null;
        }
        $value = (string) $amount;
        if (! preg_match('/^(\\d+)(?:\\.(\\d{1,2}))?$/', $value, $matches)) {
            return null;
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public function refund(RefundRequest $request): RefundResult
    {
        if ($request->amount !== (int) $request->payment->amount
            || strtoupper($request->currency) !== strtoupper((string) $request->payment->currency)) {
            return RefundResult::fail(__('tebex::messages.errors.partial_refund_unsupported'));
        }

        $api = $this->client();
        $attempt = $this->latestExternalAttempt($request->payment);
        if ($api === null || $attempt?->external_id === null) {
            return RefundResult::fail(__('tebex::messages.errors.refund_failed'));
        }

        try {
            $refund = $api->refundPayment($attempt->external_id, $request->reason, $request->idempotencyKey);
        } catch (TebexProviderException $exception) {
            if ($exception->unknownOutcome) {
                return RefundResult::unknown(['provider_outcome' => 'unknown']);
            }

            return RefundResult::fail(__('tebex::messages.errors.refund_failed'));
        }

        $externalRefundId = $refund['id'] ?? $refund['transaction_id'] ?? null;
        if (! is_string($externalRefundId) || trim($externalRefundId) === '') {
            return RefundResult::fail(__('tebex::messages.errors.refund_failed'));
        }

        $providerStatus = match ((int) ($refund['status']['id'] ?? 2)) {
            2 => 'approved',
            21 => 'pending',
            18 => 'rejected',
            default => 'unknown',
        };
        if ($providerStatus === 'rejected') {
            return RefundResult::fail(__('tebex::messages.errors.refund_failed'), terminalFailure: true);
        }
        if ($providerStatus === 'unknown') {
            return RefundResult::unknown(['provider_status' => (string) ($refund['status']['id'] ?? 'unknown')]);
        }

        return RefundResult::ok(trim($externalRefundId), ['provider_status' => $providerStatus]);
    }

    public function syncStatus(Payment $payment): Payment
    {
        $api = $this->client();
        $attempt = $this->latestTransactionAttempt($payment);
        if ($api === null || $attempt?->external_id === null) {
            return $payment;
        }

        try {
            $remote = $api->getPayment($attempt->external_id);
            $status = TebexStatusMapper::fromPaymentStatusId($remote['status']['id'] ?? null);
            if ($status === PaymentStatus::Refunded || $status === PaymentStatus::Pending) {
                $this->applyRefundEvent->handle(new ProviderRefundEvent(
                    gatewayId: self::ID,
                    externalRefundId: $attempt->external_id,
                    transactionId: $attempt->external_id,
                    status: $status === PaymentStatus::Refunded ? 'approved' : 'pending',
                ));
            }
            $this->applyStatus->handle($attempt, $status);
        } catch (TebexProviderException) {
            Log::warning('payment.sync.failed', ['gateway_id' => self::ID, 'payment_id' => $payment->id]);
        }

        return $payment->fresh() ?? $payment;
    }

    public function health(): HealthResult
    {
        if ($this->projectId() === null || $this->secretKey() === null) {
            return HealthResult::fail(__('tebex::messages.health.missing_credentials'));
        }
        if ($this->webhookSecret() === null) {
            return HealthResult::fail(__('tebex::messages.health.missing_webhook'));
        }

        $api = $this->client();
        if ($api === null) {
            return HealthResult::fail(__('tebex::messages.health.missing_credentials'));
        }

        if ($api instanceof TebexConnectionChecker) {
            try {
                $api->ping();
            } catch (TebexProviderException) {
                return HealthResult::fail(__('tebex::messages.errors.request_failed'));
            }
        }

        return HealthResult::ok(__('tebex::messages.health.ok', [
            'webhook' => route('webhooks.payments', ['gateway' => self::ID], true),
        ]));
    }

    private function client(): ?TebexApi
    {
        if ($this->api !== null) {
            return $this->api;
        }
        $projectId = $this->projectId();
        $secret = $this->secretKey();

        return $projectId !== null && $secret !== null ? new HttpTebexApi($projectId, $secret) : null;
    }

    /** @return array<string, mixed> */
    private function recurringPaymentSubject(array $subject, string $type): array
    {
        if (! str_starts_with($type, 'recurring-payment.')) {
            return [];
        }

        foreach (['last_payment', 'initial_payment'] as $key) {
            if (is_array($subject[$key] ?? null)) {
                return $subject[$key];
            }
        }

        return [];
    }

    /** @return list<array{package: array<string, mixed>, qty: int}> */
    private function checkoutItems(Order $order): array
    {
        $automatic = $this->renewalModeFor($order) === 'automatic';
        $items = [];

        foreach ($order->items as $item) {
            $item->loadMissing('product.capabilities');
            $package = [
                'name' => trim((string) $item->label) !== ''
                    ? trim((string) $item->label)
                    : 'Product '.(string) $item->product_id,
                'price' => (float) MoneyFormatter::majorInputFromMinor(
                    (int) $item->unit_amount,
                    (string) $item->currency,
                ),
                'type' => $automatic ? 'subscription' : 'single',
                'custom' => [
                    'agovena_product_id' => (string) $item->product_id,
                    'agovena_order_item_id' => (string) $item->id,
                ],
            ];

            if ($automatic) {
                $capability = $item->product?->capability('subscribable');
                $recurring = $this->recurringPackageAttributes($capability?->config ?? []);
                if ($recurring === null) {
                    throw TebexProviderException::failed('tebex::messages.errors.subscription_interval_unsupported');
                }
                $package = [...$package, ...$recurring];
            }

            $items[] = [
                'package' => $package,
                'qty' => max(1, (int) $item->quantity),
            ];
        }

        return $items;
    }

    private function supportsRecurringOrder(Order $order): bool
    {
        if ($order->items->count() !== 1) {
            return false;
        }

        $item = $order->items->first();
        if ($item === null || (int) $item->quantity !== 1) {
            return false;
        }

        $item->loadMissing('product.capabilities');

        $capability = $item->product?->capability('subscribable');

        return $capability !== null && $this->recurringPackageAttributes($capability->config) !== null;
    }

    /** @param array<string, mixed> $config */
    private function recurringPackageAttributes(array $config): ?array
    {
        $period = strtolower(trim((string) ($config['interval'] ?? '')));
        $length = (int) ($config['interval_count'] ?? 0);
        if (! in_array($period, ['day', 'month', 'year'], true) || $length < 1) {
            return null;
        }

        return [
            'expiry_period' => $period,
            'expiry_length' => $length,
        ];
    }

    private function renewalModeFor(Order $order): ?string
    {
        $properties = $order->custom_properties_snapshot;
        if (! is_array($properties)) {
            return null;
        }

        $mode = $properties['_agovena_renewal_mode'] ?? null;
        if ($mode === null) {
            foreach ($properties as $property) {
                if (is_array($property) && ($property['key'] ?? null) === '_agovena_renewal_mode') {
                    $mode = $property['value'] ?? null;
                    break;
                }
            }
        }

        return in_array($mode, ['manual', 'automatic'], true) ? (string) $mode : null;
    }

    private function rememberProviderSubscription(PaymentAttempt $attempt, string $subscriptionId): void
    {
        $meta = is_array($attempt->response_meta) ? $attempt->response_meta : [];
        if (($meta['provider_subscription_id'] ?? null) === $subscriptionId) {
            return;
        }

        $meta['provider_subscription_id'] = $subscriptionId;
        $attempt->response_meta = $meta;
        $attempt->save();
    }


    private function projectId(): ?string
    {
        return $this->settingString('project_id');
    }

    private function secretKey(): ?string
    {
        return $this->settingString('secret_key');
    }

    private function webhookSecret(): ?string
    {
        return $this->settingString('webhook_secret');
    }

    private function settingString(string $key): ?string
    {
        $value = $this->settings->get(self::ID, $key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function latestExternalAttempt(Payment $payment): ?PaymentAttempt
    {
        return PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->where('gateway_id', self::ID)
            ->whereNotNull('external_id')
            ->latest('id')
            ->first();
    }

    private function latestTransactionAttempt(Payment $payment): ?PaymentAttempt
    {
        return PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->where('gateway_id', self::ID)
            ->where('external_id', 'like', 'tbx-%')
            ->latest('id')
            ->first();
    }
}
