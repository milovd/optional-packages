<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\ApplyNormalizedPaymentStatus;
use App\Agovena\Payments\CheckoutPaymentMethod;
use App\Agovena\Payments\Contracts\HandlesProviderRefundEvents;
use App\Agovena\Payments\Contracts\ManagesProviderSubscriptions;
use App\Agovena\Payments\Contracts\OffersCheckoutMethods;
use App\Agovena\Payments\Contracts\PaymentGateway;
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

final class PaddlePaymentGateway implements HandlesProviderRefundEvents, ManagesProviderSubscriptions, OffersCheckoutMethods, PaymentGateway, SynchronizesPayments, ValidatesWebhookPayload
{
    public const ID = 'paddle';

    /** @var array<string, list<string>> */
    private const CHECKOUT_METHOD_COUNTRIES = [
        'alipay' => ['CN'],
        'bancontact' => ['BE'],
        'blik' => ['PL'],
        'ideal' => ['NL'],
        'kakao_pay' => ['KR'],
        'mb_way' => ['PT'],
        'naver_pay' => ['KR'],
        'payco' => ['KR'],
        'pix' => ['BR'],
        'samsung_pay' => ['KR'],
        'south_korea_local_card' => ['KR'],
        'upi' => ['IN'],
    ];

    /** @var list<string> */
    private const CHECKOUT_METHOD_IDS = [
        'card',
        'apple_pay',
        'google_pay',
        'paypal',
        'alipay',
        'bancontact',
        'blik',
        'ideal',
        'kakao_pay',
        'mb_way',
        'naver_pay',
        'payco',
        'pix',
        'samsung_pay',
        'south_korea_local_card',
        'upi',
    ];

    /** @var array<string, mixed>|null */
    private ?array $verifiedEvent = null;

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
        private readonly ApplyNormalizedPaymentStatus $applyStatus,
        private readonly ?PaddleApi $api = null,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'paddle::messages.gateway.label';
    }

    public function capabilities(): PaymentGatewayCapabilities
    {
        return new PaymentGatewayCapabilities(
            refunds: true,
            partialRefunds: true,
            recurring: true,
            webhooks: true,
            redirect: true,
            statusSync: true,
        );
    }

    public function checkoutMethods(): array
    {
        $methods = [];

        foreach (self::CHECKOUT_METHOD_IDS as $method) {
            $methods[] = new CheckoutPaymentMethod(
                gatewayId: self::ID,
                id: self::ID.':'.$method,
                label: 'paddle::messages.methods.'.$method,
                icon: $method === 'south_korea_local_card' ? 'ag:payment-method/card' : null,
                metadata: [
                    'provider_method' => $method,
                    'customer_countries' => self::CHECKOUT_METHOD_COUNTRIES[$method] ?? [],
                ],
            );
        }

        $methods[] = new CheckoutPaymentMethod(
            gatewayId: self::ID,
            id: self::ID.':paddle',
            label: $this->label(),
            metadata: ['provider_method' => null, 'customer_countries' => []],
        );

        return $methods;
    }

    public function initiate(PaymentInitiation $request): PaymentInitiationResult
    {
        $api = $this->client();
        if ($api === null) {
            return PaymentInitiationResult::failed(__('paddle::messages.errors.not_configured'));
        }

        if ($request->order->items->isEmpty()) {
            return PaymentInitiationResult::failed(__('paddle::messages.errors.items_missing'));
        }

        $orderName = (string) $request->order->number;
        $orderDescription = 'Agovena order '.$orderName;
        $currency = strtoupper((string) $request->payment->currency);
        $items = [[
            'quantity' => 1,
            'price' => [
                'name' => $orderName,
                'description' => $orderDescription,
                'unit_price' => [
                    'amount' => (string) $request->payment->amount,
                    'currency_code' => $currency,
                ],
                'product' => [
                    'name' => $orderName,
                    'description' => $orderDescription,
                    'tax_category' => 'standard',
                ],
            ],
        ]];
        $billingCycle = $this->billingCycleFor($request->order);
        if ($this->renewalModeFor($request->order) === 'automatic' && $billingCycle === null) {
            return PaymentInitiationResult::failed(__('paddle::messages.errors.recurring_items_unsupported'));
        }
        if ($billingCycle !== null) {
            $items[0]['price']['billing_cycle'] = $billingCycle;
            $trialPeriod = $this->trialPeriodFor($request->order);
            if ($trialPeriod !== null) {
                $items[0]['price']['trial_period'] = $trialPeriod;
            }
        }

        $payload = [
            'items' => $items,
            'currency_code' => $currency,
            'collection_mode' => 'automatic',
            'custom_data' => [
                'order_id' => (string) $request->order->id,
                'payment_id' => (string) $request->payment->id,
            ],
        ];
        $providerMethod = $this->requestedProviderMethod($request->metadata['checkout_method'] ?? null);
        $requestedMethod = $request->metadata['checkout_method'] ?? null;
        $legacyAutomaticMethods = [self::ID, self::ID.':paddle'];
        if ($requestedMethod !== null && ! in_array($requestedMethod, $legacyAutomaticMethods, true) && $providerMethod === null) {
            return PaymentInitiationResult::failed(__('paddle::messages.errors.payment_method_unavailable'));
        }

        if ($providerMethod !== null) {
            $previewPayload = [
                'items' => $items,
                'currency_code' => $currency,
            ];
            $country = strtoupper(trim((string) ($request->order->billing_country ?? '')));
            $postalCode = trim((string) ($request->order->billing_postal_code ?? ''));
            if ($country !== '') {
                $previewPayload['address'] = array_filter([
                    'country_code' => $country,
                    'postal_code' => $postalCode,
                ], static fn (string $value): bool => $value !== '');
            }

            try {
                $preview = $api->previewTransaction($previewPayload);
            } catch (PaddleProviderException) {
                return PaymentInitiationResult::failed(__('paddle::messages.errors.payment_methods_unavailable'));
            }

            $availableMethods = $this->normalizePaymentMethods($preview['available_payment_methods'] ?? null);
            if ($availableMethods === [] || ! in_array($providerMethod, $availableMethods, true)) {
                return PaymentInitiationResult::failed(__('paddle::messages.errors.payment_method_unavailable'));
            }
        }

        try {
            $transaction = $api->createTransaction($payload, $request->idempotencyKey);
        } catch (PaddleProviderException $exception) {
            Log::warning('payment.initiate.failed', [
                'gateway_id' => self::ID,
                'order_id' => $request->order->id,
            ]);

            if ($exception->errorKey === 'paddle::messages.errors.unknown_outcome') {
                return PaymentInitiationResult::unknown(message: __($exception->errorKey));
            }

            return PaymentInitiationResult::failed(__('paddle::messages.errors.create_failed'));
        }

        $url = $transaction['checkout']['url'] ?? $transaction['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return PaymentInitiationResult::failed(__('paddle::messages.errors.create_failed'));
        }

        $externalId = $transaction['id'] ?? null;
        if (! is_string($externalId) || trim($externalId) === '') {
            return PaymentInitiationResult::failed(__('paddle::messages.errors.create_failed'));
        }
        if ($providerMethod !== null) {
            $url = $this->restrictHostedCheckout($url, $providerMethod);
        }

        return PaymentInitiationResult::redirect(
            url: $url,
            externalId: trim($externalId),
            metadata: array_filter([
                'provider_status' => (string) ($transaction['status'] ?? ''),
                'provider_subscription_id' => is_string($transaction['subscription_id'] ?? null)
                    ? $transaction['subscription_id']
                    : null,
                'available_payment_methods' => $this->normalizePaymentMethods($transaction['available_payment_methods'] ?? null),
            ]),
        );
    }

    public function mapStatus(string $providerStatus): PaymentStatus
    {
        return PaddleStatusMapper::map($providerStatus);
    }

    /**
     * Return the methods Paddle calculated for this transaction.
     *
     * Paddle's method list depends on the transaction, currency, country,
     * product and account configuration. It is not a global gateway list.
     *
     * @return list<string>
     */
    public function availablePaymentMethods(Payment $payment): array
    {
        $attempt = $this->latestExternalAttempt($payment);
        if ($attempt === null || $attempt->external_id === null) {
            return [];
        }

        $metadata = is_array($attempt->response_meta) ? $attempt->response_meta : [];
        $methods = $this->normalizePaymentMethods($metadata['available_payment_methods'] ?? null);
        if ($methods !== []) {
            return $methods;
        }

        $api = $this->client();
        if ($api === null) {
            return [];
        }

        try {
            return $this->normalizePaymentMethods($api->getTransaction($attempt->external_id)['available_payment_methods'] ?? null);
        } catch (PaddleProviderException) {
            return [];
        }
    }

    public function managesProviderSubscriptions(): bool
    {
        return true;
    }

    public function cancelProviderSubscription(string $externalId, bool $atPeriodEnd): void
    {
        $api = $this->client();
        if ($api === null) {
            throw PaddleProviderException::failed('paddle::messages.errors.not_configured');
        }

        $api->cancelSubscription($externalId, $atPeriodEnd);
    }

    public function resumeProviderSubscription(string $externalId): void
    {
        $api = $this->client();
        if ($api === null) {
            throw PaddleProviderException::failed('paddle::messages.errors.not_configured');
        }

        $api->clearScheduledSubscriptionChange($externalId);
    }

    public function providerSubscriptionEvent(WebhookPayload $payload): ?ProviderSubscriptionEvent
    {
        $raw = $payload->raw;
        $eventType = (string) ($raw['event_type'] ?? '');
        $customData = is_array($raw['custom_data'] ?? null) ? $raw['custom_data'] : [];
        $subscriptionId = is_string($raw['subscription_id'] ?? null) ? trim($raw['subscription_id']) : '';
        if ($subscriptionId === '' && str_starts_with($eventType, 'subscription.')) {
            $subscriptionId = (string) ($raw['object_id'] ?? '');
        }
        if ($subscriptionId === '') {
            return null;
        }

        $period = is_array($raw['billing_period'] ?? null) ? $raw['billing_period'] : [];
        $scheduledChange = is_array($raw['scheduled_change'] ?? null) ? $raw['scheduled_change'] : [];
        $subscriptionEvent = str_starts_with($eventType, 'subscription.');
        $status = $subscriptionEvent ? (string) ($raw['status'] ?? '') : '';

        return new ProviderSubscriptionEvent(
            gatewayId: self::ID,
            externalSubscriptionId: $subscriptionId,
            eventType: $eventType,
            status: $status,
            transactionId: is_string($raw['object_id'] ?? null) && str_starts_with((string) $raw['object_id'], 'txn_')
                ? (string) $raw['object_id']
                : null,
            originOrderId: is_scalar($customData['order_id'] ?? null) ? (string) $customData['order_id'] : null,
            periodStart: is_string($period['starts_at'] ?? null) ? $period['starts_at'] : null,
            periodEnd: is_string($period['ends_at'] ?? null) ? $period['ends_at'] : null,
            nextBillingAt: is_string($raw['next_billed_at'] ?? null) ? $raw['next_billed_at'] : null,
            cancelAtPeriodEnd: $subscriptionEvent
                ? (string) ($scheduledChange['action'] ?? '') === 'cancel'
                : null,
            customData: $customData,
        );
    }

    public function providerRefundEvent(WebhookPayload $payload): ?ProviderRefundEvent
    {
        if (($payload->raw['event_type'] ?? null) !== 'adjustment.updated') {
            return null;
        }

        $adjustmentId = $payload->raw['adjustment_id'] ?? null;
        $transactionId = $payload->raw['object_id'] ?? null;
        if (! is_string($adjustmentId) || ! str_starts_with($adjustmentId, 'adj_')
            || ! is_string($transactionId) || ! str_starts_with($transactionId, 'txn_')) {
            return null;
        }

        return new ProviderRefundEvent(
            gatewayId: self::ID,
            externalRefundId: $adjustmentId,
            transactionId: $transactionId,
            status: (string) ($payload->raw['status'] ?? ''),
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = $this->webhookSecret();
        $body = $request->getContent();
        $header = (string) $request->header('Paddle-Signature', '');
        if ($secret === null || ! PaddleWebhookVerifier::verify($body, $header, $secret)) {
            return false;
        }

        $event = json_decode($body, true);
        if (! is_array($event) || ! isset($event['event_id'], $event['event_type'])) {
            return false;
        }
        $this->verifiedEvent = $event;

        return true;
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $event = $this->verifiedEvent ?? json_decode($request->getContent(), true);
        if (! is_array($event)) {
            throw PaddleProviderException::failed('paddle::messages.errors.webhook_invalid');
        }

        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $type = (string) ($event['event_type'] ?? '');
        $status = $type === 'adjustment.updated'
            ? PaddleStatusMapper::map((string) ($data['status'] ?? ''), (string) ($data['action'] ?? ''))
            : PaddleStatusMapper::map((string) ($data['status'] ?? str_replace('transaction.', '', $type)));
        $externalId = $type === 'adjustment.updated'
            ? (string) ($data['transaction_id'] ?? $data['id'] ?? '')
            : (string) ($data['id'] ?? $data['transaction_id'] ?? '');

        return new WebhookPayload(
            externalEventId: (string) $event['event_id'],
            externalPaymentId: $externalId !== '' ? $externalId : null,
            status: $status,
            raw: [
                'event_type' => $type,
                'object_id' => $externalId,
                'adjustment_id' => $type === 'adjustment.updated' ? ($data['id'] ?? null) : null,
                'status' => $data['status'] ?? null,
                'currency_code' => $data['currency_code'] ?? null,
                'amount_minor' => $data['details']['totals']['grand_total'] ?? null,
                'line_items' => $data['details']['line_items'] ?? [],
                'custom_data' => $data['custom_data'] ?? [],
                'subscription_id' => $data['subscription_id'] ?? null,
                'billing_period' => $data['billing_period'] ?? $data['current_billing_period'] ?? null,
                'scheduled_change' => $data['scheduled_change'] ?? null,
                'next_billed_at' => $data['next_billed_at'] ?? null,
            ],
        );
    }

    public function validateWebhookPayload(PaymentAttempt $attempt, WebhookPayload $payload): bool
    {
        $subscriptionId = $payload->raw['subscription_id'] ?? null;
        if (is_string($subscriptionId) && trim($subscriptionId) !== '') {
            $this->rememberProviderSubscription($attempt, trim($subscriptionId));
        }

        if ($payload->status !== PaymentStatus::Paid) {
            return true;
        }

        $payment = $attempt->payment;
        $customData = is_array($payload->raw['custom_data'] ?? null) ? $payload->raw['custom_data'] : [];
        if ((string) ($customData['payment_id'] ?? '') !== (string) $payment->id
            || (string) ($customData['order_id'] ?? '') !== (string) $payment->order_id) {
            return false;
        }

        if (strtoupper((string) ($payload->raw['currency_code'] ?? '')) !== strtoupper((string) $payment->currency)) {
            return false;
        }

        if ((string) ($payload->raw['amount_minor'] ?? '') !== (string) $payment->amount) {
            return false;
        }

        $lineItems = array_values(array_filter(
            (array) ($payload->raw['line_items'] ?? []),
            static fn (mixed $item): bool => is_array($item),
        ));
        if (count($lineItems) !== 1 || (int) ($lineItems[0]['quantity'] ?? 0) !== 1) {
            return false;
        }

        $lineTotal = $lineItems[0]['totals']['total'] ?? $lineItems[0]['unit_totals']['total'] ?? null;

        return (string) $lineTotal === (string) $payment->amount;
    }

    public function refund(RefundRequest $request): RefundResult
    {
        if ($request->amount < 1
            || $request->amount > (int) $request->payment->amount
            || strtoupper($request->currency) !== strtoupper((string) $request->payment->currency)) {
            return RefundResult::fail(__('paddle::messages.errors.refund_failed'));
        }

        $api = $this->client();
        $attempt = $this->latestExternalAttempt($request->payment);
        if ($api === null || $attempt?->external_id === null) {
            return RefundResult::fail(__('paddle::messages.errors.refund_failed'));
        }

        $type = $request->amount === (int) $request->payment->amount ? 'full' : 'partial';
        $items = null;
        if ($type === 'partial') {
            try {
                $transaction = $api->getTransaction($attempt->external_id);
            } catch (PaddleProviderException) {
                return RefundResult::fail(__('paddle::messages.errors.refund_failed'));
            }

            $lineItems = array_values(array_filter(
                (array) ($transaction['details']['line_items'] ?? $transaction['items'] ?? []),
                static fn (mixed $item): bool => is_array($item),
            ));
            $lineItemId = $lineItems[0]['id'] ?? null;
            if (count($lineItems) !== 1
                || ! is_string($lineItemId)
                || ! str_starts_with($lineItemId, 'txnitm_')) {
                return RefundResult::fail(__('paddle::messages.errors.refund_failed'));
            }

            $items = [[
                'item_id' => $lineItemId,
                'type' => 'partial',
                'amount' => (string) $request->amount,
            ]];
        }

        try {
            $adjustment = $api->createAdjustment(
                $attempt->external_id,
                $request->reason ?? '',
                $type,
                $items,
                $request->idempotencyKey,
            );
        } catch (PaddleProviderException) {
            return RefundResult::fail(__('paddle::messages.errors.refund_failed'));
        }

        $externalRefundId = $adjustment['id'] ?? null;
        if (! is_string($externalRefundId) || ! str_starts_with($externalRefundId, 'adj_')) {
            return RefundResult::fail(__('paddle::messages.errors.refund_failed'));
        }

        return RefundResult::ok($externalRefundId, [
            'provider_status' => (string) ($adjustment['status'] ?? 'pending_approval'),
        ]);
    }

    public function syncStatus(Payment $payment): Payment
    {
        $api = $this->client();
        $attempt = $this->latestExternalAttempt($payment);
        if ($api === null || $attempt?->external_id === null) {
            return $payment;
        }

        try {
            $transaction = $api->getTransaction($attempt->external_id);
            $subscriptionId = $transaction['subscription_id'] ?? null;
            if (is_string($subscriptionId) && trim($subscriptionId) !== '') {
                $this->rememberProviderSubscription($attempt, trim($subscriptionId));
            }
            $this->applyStatus->handle($attempt, PaddleStatusMapper::map((string) ($transaction['status'] ?? '')));
        } catch (PaddleProviderException) {
            Log::warning('payment.sync.failed', ['gateway_id' => self::ID, 'payment_id' => $payment->id]);
        }

        return $payment->fresh() ?? $payment;
    }

    public function health(): HealthResult
    {
        if ($this->apiKey() === null) {
            return HealthResult::fail(__('paddle::messages.health.missing_key'));
        }
        if ($this->webhookSecret() === null) {
            return HealthResult::fail(__('paddle::messages.health.missing_webhook'));
        }

        $api = $this->client();
        if ($api === null) {
            return HealthResult::fail(__('paddle::messages.health.missing_key'));
        }

        if ($api instanceof PaddleConnectionChecker) {
            try {
                $api->ping();
            } catch (PaddleProviderException) {
                return HealthResult::fail(__('paddle::messages.errors.request_failed'));
            }
        }

        return HealthResult::ok(__('paddle::messages.health.ok', [
            'mode' => $this->sandbox() ? 'sandbox' : 'live',
            'webhook' => route('webhooks.payments', ['gateway' => self::ID], true),
        ]));
    }

    /** @return array{interval: string, frequency: int}|null */
    private function trialPeriodFor(Order $order): ?array
    {
        if ($order->items->count() !== 1) {
            return null;
        }
        $item = $order->items->first();
        if ($item === null) {
            return null;
        }
        $item->loadMissing('product.capabilities');
        $capability = $item->product?->capability('subscribable');
        $trialDays = $capability === null ? 0 : (int) ($capability->config['trial_days'] ?? 0);
        if ($trialDays <= 0) {
            return null;
        }

        return ['interval' => 'day', 'frequency' => min(365, $trialDays)];
    }

    /** @return array{interval: string, frequency: int}|null */
    private function billingCycleFor(Order $order): ?array
    {
        if ($this->renewalModeFor($order) !== 'automatic' || $order->items->count() !== 1) {
            return null;
        }

        $item = $order->items->first();
        if ($item === null) {
            return null;
        }
        $item->loadMissing('product.capabilities');
        $capability = $item->product?->capability('subscribable');
        $config = $capability === null ? [] : $capability->config;

        $interval = match ((string) ($config['interval'] ?? 'month')) {
            'day' => 'day',
            'week' => 'week',
            'year' => 'year',
            default => 'month',
        };
        $frequency = max(1, (int) ($config['interval_count'] ?? 1));
        return [
            'interval' => $interval,
            'frequency' => $frequency,
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

    private function requestedProviderMethod(mixed $checkoutMethod): ?string
    {
        if (! is_string($checkoutMethod) || $checkoutMethod === '' || $checkoutMethod === self::ID) {
            return null;
        }

        $prefix = self::ID.':';
        $method = str_starts_with($checkoutMethod, $prefix)
            ? substr($checkoutMethod, strlen($prefix))
            : $checkoutMethod;

        return in_array($method, self::CHECKOUT_METHOD_IDS, true) ? $method : null;
    }

    private function restrictHostedCheckout(string $url, string $method): string
    {
        $fragment = '';
        $fragmentPosition = strpos($url, '#');
        if ($fragmentPosition !== false) {
            $fragment = substr($url, $fragmentPosition);
            $url = substr($url, 0, $fragmentPosition);
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'allowed_payment_methods='.rawurlencode($method).$fragment;
    }

    /** @return list<string> */
    private function normalizePaymentMethods(mixed $methods): array
    {
        if (! is_array($methods)) {
            return [];
        }

        return array_values(array_filter($methods, static fn (mixed $method): bool => is_string($method) && trim($method) !== ''));
    }

    private function client(): ?PaddleApi
    {
        if ($this->api !== null) {
            return $this->api;
        }
        $key = $this->apiKey();

        return $key !== null ? new HttpPaddleApi($key, $this->sandbox()) : null;
    }


    private function apiKey(): ?string
    {
        return $this->settingString('api_key');
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

    private function sandbox(): bool
    {
        return filter_var($this->settings->get(self::ID, 'sandbox', true), FILTER_VALIDATE_BOOLEAN);
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
}
