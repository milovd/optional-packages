<?php

declare(strict_types=1);

namespace Agovena\Extensions\PayPal;

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
use App\Support\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class PayPalPaymentGateway implements HandlesProviderRefundEvents, ManagesProviderSubscriptions, OffersCheckoutMethods, PaymentGateway, SynchronizesPayments, ValidatesWebhookPayload
{
    public const ID = 'paypal';

    /** @var array<string, mixed>|null */
    private ?array $verifiedEvent = null;

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
        private readonly ApplyNormalizedPaymentStatus $applyStatus,
        private readonly ?PayPalApi $api = null,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'paypal::messages.gateway.label';
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
            cancelPending: false,
        );
    }

    public function checkoutMethods(): array
    {
        return [
            new CheckoutPaymentMethod(
                gatewayId: self::ID,
                id: self::ID.':paypal',
                label: $this->label(),
                icon: 'ag:payment-method/paypal',
                metadata: [
                    'provider_method' => 'paypal',
                    'supports_recurring' => true,
                ],
            ),
        ];
    }

    public function managesProviderSubscriptions(): bool
    {
        return true;
    }

    public function initiate(PaymentInitiation $request): PaymentInitiationResult
    {
        $api = $this->client();
        if ($api === null) {
            return PaymentInitiationResult::failed(__('paypal::messages.errors.not_configured'));
        }

        if ($this->isAutomaticSubscription($request->order)) {
            return $this->initiateSubscription($request, $api);
        }

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $request->payment->id,
                'custom_id' => (string) $request->order->id,
                'description' => $request->order->number,
                'amount' => [
                    'currency_code' => strtoupper($request->payment->currency),
                    'value' => MoneyFormatter::majorInputFromMinor((int) $request->payment->amount, $request->payment->currency),
                ],
            ]],
            'application_context' => [
                'return_url' => $request->returnUrl,
                'cancel_url' => $request->cancelUrl,
                'brand_name' => config('app.name', 'Agovena'),
                'user_action' => 'PAY_NOW',
            ],
        ];

        try {
            $order = $api->createOrder($payload, $request->idempotencyKey);
        } catch (PayPalProviderException $exception) {
            Log::warning('payment.initiate.failed', [
                'gateway_id' => self::ID,
                'order_id' => $request->order->id,
            ]);

            if ($exception->unknownOutcome) {
                return PaymentInitiationResult::unknown(
                    metadata: ['reason' => 'provider_transport_unknown'],
                    message: __('paypal::messages.errors.create_failed'),
                );
            }

            return PaymentInitiationResult::failed(__('paypal::messages.errors.create_failed'));
        }

        $url = $this->approvalUrl($order);
        if ($url === null || ! $this->isPayPalApprovalUrl($url)) {
            return PaymentInitiationResult::failed(__('paypal::messages.errors.create_failed'));
        }

        $externalId = trim((string) ($order['id'] ?? ''));
        if ($externalId === '') {
            return PaymentInitiationResult::failed(__('paypal::messages.errors.create_failed'));
        }

        return PaymentInitiationResult::redirect(
            url: $url,
            externalId: $externalId,
            metadata: [
                'provider_status' => (string) ($order['status'] ?? 'CREATED'),
            ],
        );
    }

    public function mapStatus(string $providerStatus): PaymentStatus
    {
        return PayPalStatusMapper::map($providerStatus);
    }

    public function verifyWebhook(Request $request): bool
    {
        $this->verifiedEvent = null;
        $webhookId = $this->webhookId();
        if ($webhookId === null) {
            return false;
        }

        $payload = $request->getContent();
        if ($payload === '') {
            return false;
        }

        $event = json_decode($payload, true);
        if (! is_array($event)) {
            return false;
        }

        $headers = [
            'auth_algo' => (string) $request->header('PAYPAL-AUTH-ALGO', ''),
            'cert_url' => (string) $request->header('PAYPAL-CERT-URL', ''),
            'transmission_id' => (string) $request->header('PAYPAL-TRANSMISSION-ID', ''),
            'transmission_sig' => (string) $request->header('PAYPAL-TRANSMISSION-SIG', ''),
            'transmission_time' => (string) $request->header('PAYPAL-TRANSMISSION-TIME', ''),
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ];

        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $required) {
            if ($headers[$required] === '') {
                return false;
            }
        }
        if (! $this->isTrustedCertificateUrl($headers['cert_url'])) {
            return false;
        }

        $api = $this->client();
        if ($api === null) {
            return false;
        }

        try {
            if (! $api->verifyWebhookSignature($headers)) {
                return false;
            }
        } catch (PayPalProviderException) {
            return false;
        }

        $this->verifiedEvent = $event;

        return isset($this->verifiedEvent['id'], $this->verifiedEvent['event_type'])
            && is_string($this->verifiedEvent['id'])
            && trim($this->verifiedEvent['id']) !== ''
            && is_string($this->verifiedEvent['event_type'])
            && trim($this->verifiedEvent['event_type']) !== '';
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $event = $this->verifiedEvent;
        if ($event === null) {
            $decoded = json_decode($request->getContent(), true);
            if (! is_array($decoded)) {
                throw PayPalProviderException::failed('paypal::messages.errors.webhook_invalid');
            }
            $event = $decoded;
        }

        $eventType = (string) ($event['event_type'] ?? '');
        $status = PayPalStatusMapper::fromWebhookEvent($event) ?? PaymentStatus::Pending;
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        if ($eventType === 'CHECKOUT.ORDER.APPROVED') {
            $status = $this->captureApprovedOrder($resource, $event);
        }

        $resourceId = trim((string) ($resource['id'] ?? ''));
        $relatedIds = is_array($resource['supplementary_data']['related_ids'] ?? null)
            ? $resource['supplementary_data']['related_ids']
            : [];
        $orderId = $this->firstString($relatedIds, ['order_id'])
            ?? $this->firstString($resource, ['custom_id']);
        $subscriptionId = $this->firstString($resource, ['billing_agreement_id'])
            ?? ($eventType === 'BILLING.SUBSCRIPTION.ACTIVATED'
                || $eventType === 'BILLING.SUBSCRIPTION.UPDATED'
                || $eventType === 'BILLING.SUBSCRIPTION.SUSPENDED'
                || $eventType === 'BILLING.SUBSCRIPTION.CANCELLED'
                || $eventType === 'BILLING.SUBSCRIPTION.EXPIRED'
                ? $resourceId
                : null);
        $amount = is_array($resource['amount'] ?? null) ? $resource['amount'] : [];
        $amountMinor = $this->minorAmount($amount['value'] ?? $amount['total'] ?? null, $amount['currency_code'] ?? $amount['currency'] ?? null);
        $currency = $this->firstString($amount, ['currency_code', 'currency']);
        $isSaleEvent = str_starts_with($eventType, 'PAYMENT.SALE.');
        $isCaptureEvent = str_starts_with($eventType, 'PAYMENT.CAPTURE.');
        $externalPaymentId = $isSaleEvent && $subscriptionId !== null
            ? $subscriptionId
            : ($isCaptureEvent && $orderId !== null ? $orderId : $resourceId);

        return new WebhookPayload(
            externalEventId: (string) ($event['id'] ?? ''),
            externalPaymentId: $externalPaymentId !== '' ? $externalPaymentId : null,
            status: $status,
            raw: array_filter([
                'event_type' => $eventType,
                'resource_id' => $resourceId,
                'order_id' => $orderId,
                'subscription_id' => $subscriptionId,
                'custom_id' => $this->firstString($resource, ['custom_id']),
                'plan_id' => $this->firstString($resource, ['plan_id']),
                'currency_code' => $currency,
                'amount_minor' => $amountMinor,
                'sale_id' => $isSaleEvent ? $resourceId : null,
                'capture_id' => $isCaptureEvent && ! str_contains($eventType, 'REFUND.') ? $resourceId : null,
                'refund_id' => str_contains($eventType, 'REFUND') || str_ends_with($eventType, '.REFUNDED') ? $resourceId : null,
                'transaction_id' => $externalPaymentId,
                'refund_status' => $this->refundStatus($eventType, $resource),
                'subscription_status' => $this->firstString($resource, ['status']),
                'period_start' => $this->firstString($resource, ['start_time']),
                'next_billing_at' => $this->nextBillingAt($resource),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    public function providerSubscriptionEvent(WebhookPayload $payload): ?ProviderSubscriptionEvent
    {
        $eventType = (string) ($payload->raw['event_type'] ?? '');
        if (! in_array($eventType, [
            'BILLING.SUBSCRIPTION.ACTIVATED',
            'BILLING.SUBSCRIPTION.UPDATED',
            'BILLING.SUBSCRIPTION.SUSPENDED',
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.EXPIRED',
        ], true)) {
            return null;
        }
        $subscriptionId = trim((string) ($payload->raw['subscription_id'] ?? ''));
        if (! $this->isProviderSubscriptionId($subscriptionId)) {
            return null;
        }

        $providerStatus = strtoupper((string) ($payload->raw['subscription_status'] ?? ''));
        [$normalizedEventType, $status, $cancelAtPeriodEnd] = match ($providerStatus) {
            'CANCELLED', 'EXPIRED' => ['subscription.canceled', 'canceled', false],
            'SUSPENDED' => ['subscription.updated', 'active', true],
            default => ['subscription.updated', 'active', false],
        };

        return new ProviderSubscriptionEvent(
            gatewayId: self::ID,
            externalSubscriptionId: $subscriptionId,
            eventType: $normalizedEventType,
            status: $status,
            transactionId: is_string($payload->raw['sale_id'] ?? null) ? $payload->raw['sale_id'] : null,
            originOrderId: is_scalar($payload->raw['custom_id'] ?? null) ? (string) $payload->raw['custom_id'] : null,
            periodStart: is_string($payload->raw['period_start'] ?? null) ? $payload->raw['period_start'] : null,
            periodEnd: is_string($payload->raw['next_billing_at'] ?? null) ? $payload->raw['next_billing_at'] : null,
            nextBillingAt: is_string($payload->raw['next_billing_at'] ?? null) ? $payload->raw['next_billing_at'] : null,
            cancelAtPeriodEnd: $cancelAtPeriodEnd,
            customData: [
                'paypal_event_type' => $eventType,
                'plan_id' => $payload->raw['plan_id'] ?? null,
            ],
        );
    }

    public function providerRefundEvent(WebhookPayload $payload): ?ProviderRefundEvent
    {
        $eventType = (string) ($payload->raw['event_type'] ?? '');
        if (! in_array($eventType, [
            'PAYMENT.CAPTURE.REFUNDED',
            'PAYMENT.CAPTURE.REFUND.PENDING',
            'PAYMENT.CAPTURE.REFUND.FAILED',
            'PAYMENT.SALE.REFUNDED',
        ], true)) {
            return null;
        }

        $refundId = trim((string) ($payload->raw['refund_id'] ?? ''));
        $transactionId = trim((string) ($payload->raw['transaction_id'] ?? ''));
        if ($refundId === '' || $transactionId === '') {
            return null;
        }

        return new ProviderRefundEvent(
            gatewayId: self::ID,
            externalRefundId: $refundId,
            transactionId: $transactionId,
            status: (string) ($payload->raw['refund_status'] ?? 'pending'),
            amountMinor: is_int($payload->raw['amount_minor'] ?? null) ? $payload->raw['amount_minor'] : null,
            currency: is_string($payload->raw['currency_code'] ?? null) ? $payload->raw['currency_code'] : null,
        );
    }

    public function validateWebhookPayload(PaymentAttempt $attempt, WebhookPayload $payload): bool
    {
        $raw = $payload->raw;
        if ($payload->status !== PaymentStatus::Paid) {
            return true;
        }

        $payment = $attempt->payment;
        $customId = $raw['custom_id'] ?? null;
        if ($customId !== null && (string) $customId !== (string) $payment->order_id) {
            return false;
        }

        $currency = $raw['currency_code'] ?? null;
        if ($currency !== null && strtoupper((string) $currency) !== strtoupper((string) $payment->currency)) {
            return false;
        }

        $amountMinor = $raw['amount_minor'] ?? null;
        if ($amountMinor !== null && (int) $amountMinor !== (int) $payment->amount) {
            return false;
        }

        $eventType = (string) ($raw['event_type'] ?? '');
        $subscriptionId = is_string($raw['subscription_id'] ?? null) ? trim($raw['subscription_id']) : '';
        if (str_starts_with($eventType, 'PAYMENT.SALE.') && ! $this->isProviderSubscriptionId($subscriptionId)) {
            return false;
        }

        $meta = is_array($attempt->response_meta) ? $attempt->response_meta : [];
        if ($this->isProviderSubscriptionId($subscriptionId)) {
            $meta['provider_subscription_id'] = $subscriptionId;
        }
        foreach (['sale_id' => 'paypal_sale_id', 'capture_id' => 'paypal_capture_id'] as $source => $target) {
            $value = $raw[$source] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $meta[$target] = trim($value);
            }
        }
        $attempt->response_meta = $meta;
        $attempt->save();

        return true;
    }

    public function cancelProviderSubscription(string $externalId, bool $atPeriodEnd): void
    {
        $api = $this->client();
        if ($api === null) {
            throw PayPalProviderException::failed('paypal::messages.errors.not_configured');
        }

        try {
            if ($atPeriodEnd) {
                $api->suspendSubscription($externalId, 'Agovena cancellation at period end');
            } else {
                $api->cancelSubscription($externalId, 'Agovena immediate cancellation');
            }
        } catch (PayPalProviderException $exception) {
            throw new PayPalProviderException(
                __('paypal::messages.errors.subscription_cancel_failed'),
                'paypal::messages.errors.subscription_cancel_failed',
                $exception->status,
                $exception->unknownOutcome,
            );
        }
    }

    public function resumeProviderSubscription(string $externalId): void
    {
        $api = $this->client();
        if ($api === null) {
            throw PayPalProviderException::failed('paypal::messages.errors.not_configured');
        }

        try {
            $api->activateSubscription($externalId, 'Agovena subscription resumed');
        } catch (PayPalProviderException $exception) {
            throw new PayPalProviderException(
                __('paypal::messages.errors.subscription_resume_failed'),
                'paypal::messages.errors.subscription_resume_failed',
                $exception->status,
                $exception->unknownOutcome,
            );
        }
    }

    public function refund(RefundRequest $request): RefundResult
    {
        if ($request->amount < 1
            || $request->amount > (int) $request->payment->amount
            || strtoupper($request->currency) !== strtoupper((string) $request->payment->currency)) {
            return RefundResult::fail(__('paypal::messages.errors.refund_failed'));
        }

        $api = $this->client();
        if ($api === null) {
            return RefundResult::fail(__('paypal::messages.errors.not_configured'));
        }

        $attempt = $this->latestExternalAttempt($request->payment);
        $meta = is_array($attempt?->response_meta) ? $attempt->response_meta : [];
        $saleId = is_string($meta['paypal_sale_id'] ?? null) ? trim($meta['paypal_sale_id']) : '';
        $captureId = is_string($meta['paypal_capture_id'] ?? null) ? trim($meta['paypal_capture_id']) : '';
        if ($saleId === '' && $captureId === '') {
            $captureId = $this->captureId($request->payment) ?? '';
        }
        if ($saleId === '' && $captureId === '') {
            return RefundResult::fail(__('paypal::messages.errors.refund_failed'));
        }

        $payload = [
            'amount' => [
                'currency_code' => strtoupper($request->currency),
                'value' => MoneyFormatter::majorInputFromMinor($request->amount, $request->currency),
            ],
            'note_to_payer' => $request->reason ?: $request->payment->order?->number,
        ];

        try {
            $refund = $saleId !== ''
                ? $api->refundSale($saleId, $payload, $request->idempotencyKey)
                : $api->refundCapture($captureId, $payload, $request->idempotencyKey);
        } catch (PayPalProviderException $exception) {
            if ($exception->unknownOutcome) {
                return RefundResult::unknown(
                    ['reason' => 'provider_transport_unknown'],
                    __('paypal::messages.errors.unknown_outcome'),
                );
            }

            return RefundResult::fail(__('paypal::messages.errors.refund_failed'));
        }

        $externalRefundId = $refund['id'] ?? null;
        if (! is_string($externalRefundId) || trim($externalRefundId) === '') {
            return RefundResult::unknown(
                ['reason' => 'provider_response_invalid'],
                __('paypal::messages.errors.unknown_outcome'),
            );
        }

        $providerStatus = strtolower((string) ($refund['status'] ?? $refund['state'] ?? ''));
        if (in_array($providerStatus, ['completed', 'approved'], true)) {
            return RefundResult::ok(trim($externalRefundId), ['provider_status' => 'approved']);
        }
        if (in_array($providerStatus, ['pending', 'processing'], true)) {
            return RefundResult::ok(trim($externalRefundId), ['provider_status' => 'processing']);
        }
        if (in_array($providerStatus, ['failed', 'denied', 'rejected', 'cancelled', 'canceled'], true)) {
            return RefundResult::fail(__('paypal::messages.errors.refund_failed'), terminalFailure: true);
        }

        return RefundResult::unknown(
            ['provider_status' => $providerStatus],
            __('paypal::messages.errors.unknown_outcome'),
        );
    }

    public function syncStatus(Payment $payment): Payment
    {
        $api = $this->client();
        if ($api === null) {
            return $payment;
        }

        $attempt = $this->latestExternalAttempt($payment);
        if ($attempt?->external_id === null) {
            return $payment;
        }

        try {
            if ($this->isProviderSubscriptionId((string) $attempt->external_id)) {
                $subscription = $api->getSubscription((string) $attempt->external_id);
                if ($payment->status === PaymentStatus::Pending
                    && in_array(strtoupper((string) ($subscription['status'] ?? '')), ['CANCELLED', 'EXPIRED'], true)) {
                    $this->applyStatus->handle($attempt, PaymentStatus::Failed);
                }

                return $payment->fresh() ?? $payment;
            }

            $remote = $api->getOrder((string) $attempt->external_id);
        } catch (PayPalProviderException) {
            Log::warning('payment.sync.failed', [
                'gateway_id' => self::ID,
                'payment_id' => $payment->id,
            ]);

            return $payment;
        }

        $this->applyStatus->handle($attempt, PayPalStatusMapper::fromOrder($remote));

        return $payment->fresh() ?? $payment;
    }

    private function isAutomaticSubscription(Order $order): bool
    {
        if ($order->items->count() !== 1) {
            return false;
        }

        $item = $order->items->first();
        if ($item === null) {
            return false;
        }

        $item->loadMissing('product.capabilities');

        return $this->renewalModeFor($order) === 'automatic'
            && $item->quantity === 1
            && $item->product?->hasCapability('subscribable') === true;
    }

    private function initiateSubscription(PaymentInitiation $request, PayPalApi $api): PaymentInitiationResult
    {
        $planId = $this->subscriptionPlanIdFor($request->order);
        if ($planId === null) {
            return PaymentInitiationResult::failed(__('paypal::messages.errors.subscription_plan_missing'));
        }

        try {
            $plan = $api->getPlan($planId);
        } catch (PayPalProviderException) {
            return PaymentInitiationResult::failed(__('paypal::messages.errors.subscription_plan_invalid'));
        }

        if (! $this->planMatchesOrder($plan, $request->order, $request->payment->currency, (int) $request->payment->amount)) {
            return PaymentInitiationResult::failed(__('paypal::messages.errors.subscription_plan_invalid'));
        }

        $payload = [
            'plan_id' => $planId,
            'quantity' => '1',
            'custom_id' => (string) $request->order->id,
            'subscriber' => [
                'email_address' => (string) $request->order->customer_email,
            ],
            'application_context' => [
                'brand_name' => config('app.name', 'Agovena'),
                'locale' => 'en-US',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => $request->returnUrl,
                'cancel_url' => $request->cancelUrl,
            ],
        ];

        try {
            $subscription = $api->createSubscription($payload, $request->idempotencyKey);
        } catch (PayPalProviderException $exception) {
            Log::warning('payment.subscription.initiate.failed', [
                'gateway_id' => self::ID,
                'order_id' => $request->order->id,
            ]);

            if ($exception->unknownOutcome) {
                return PaymentInitiationResult::unknown(
                    metadata: ['reason' => 'provider_transport_unknown'],
                    message: __('paypal::messages.errors.unknown_outcome'),
                );
            }

            return PaymentInitiationResult::failed(__('paypal::messages.errors.subscription_failed'));
        }

        $subscriptionId = trim((string) ($subscription['id'] ?? ''));
        $url = $this->approvalUrl($subscription);
        if (! $this->isProviderSubscriptionId($subscriptionId)
            || $url === null
            || ! $this->isPayPalApprovalUrl($url)
        ) {
            return PaymentInitiationResult::failed(__('paypal::messages.errors.subscription_failed'));
        }

        return PaymentInitiationResult::redirect(
            url: $url,
            externalId: $subscriptionId,
            metadata: [
                'provider_status' => (string) ($subscription['status'] ?? 'APPROVAL_PENDING'),
                'provider_subscription_id' => $subscriptionId,
                'subscription_plan_id' => $planId,
            ],
        );
    }

    private function subscriptionPlanIdFor(Order $order): ?string
    {
        $item = $order->items->first();
        if ($item !== null) {
            $item->loadMissing('product.capabilities');
            $capability = $item->product?->capability('subscribable');
            $config = $capability?->config;
            if (is_array($config) && is_string($config['paypal_plan_id'] ?? null) && trim($config['paypal_plan_id']) !== '') {
                return trim($config['paypal_plan_id']);
            }
        }

        $value = $this->settings->get('paypal', 'subscription_plan_id');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function planMatchesOrder(array $plan, Order $order, string $currency, int $amount): bool
    {
        if (strtoupper((string) ($plan['status'] ?? '')) !== 'ACTIVE') {
            return false;
        }

        $item = $order->items->first();
        if ($item === null || $item->quantity !== 1) {
            return false;
        }
        $item->loadMissing('product.capabilities');
        $capability = $item->product?->capability('subscribable');
        $config = is_array($capability?->config) ? $capability->config : [];
        $cycles = array_values(array_filter(
            (array) ($plan['billing_cycles'] ?? []),
            static fn (mixed $cycle): bool => is_array($cycle),
        ));

        $regular = null;
        $trial = null;
        foreach ($cycles as $cycle) {
            $tenure = strtoupper((string) ($cycle['tenure_type'] ?? ''));
            if ($tenure === 'REGULAR' && $regular === null) {
                $regular = $cycle;
            }
            if ($tenure === 'TRIAL' && $trial === null) {
                $trial = $cycle;
            }
        }
        if (! is_array($regular)) {
            return false;
        }

        $price = is_array($regular['pricing_scheme']['fixed_price'] ?? null)
            ? $regular['pricing_scheme']['fixed_price']
            : [];
        if (strtoupper((string) ($price['currency_code'] ?? '')) !== strtoupper($currency)) {
            return false;
        }
        try {
            if (MoneyFormatter::minorFromMajorInput((string) ($price['value'] ?? ''), $currency) !== $amount) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $expectedInterval = match ((string) ($config['interval'] ?? 'month')) {
            'day' => 'DAY',
            'week' => 'WEEK',
            'year' => 'YEAR',
            default => 'MONTH',
        };
        $expectedCount = max(1, (int) ($config['interval_count'] ?? 1));
        if (strtoupper((string) ($regular['frequency'] ?? $regular['interval_unit'] ?? '')) !== $expectedInterval
            || (int) ($regular['frequency_interval'] ?? $regular['interval_count'] ?? 1) !== $expectedCount
        ) {
            return false;
        }

        $trialDays = max(0, (int) ($config['trial_days'] ?? 0));
        if ($trialDays === 0) {
            return $trial === null;
        }

        return is_array($trial)
            && strtoupper((string) ($trial['frequency'] ?? $trial['interval_unit'] ?? '')) === 'DAY'
            && (int) ($trial['frequency_interval'] ?? $trial['interval_count'] ?? 0) === $trialDays;
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

        return is_string($mode) ? $mode : null;
    }

    /**
     * Capture an approved order before acknowledging the webhook. PayPal's
     * CAPTURE intent does not settle the order merely because the buyer
     * approved it.
     *
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $event
     */
    private function captureApprovedOrder(array $resource, array $event): PaymentStatus
    {
        $orderId = $this->externalIdFromResource($resource, $event);
        $api = $this->client();
        if ($orderId === '' || $api === null) {
            throw PayPalProviderException::failed('paypal::messages.errors.provider_failed');
        }

        try {
            $order = $api->getOrder($orderId);
            if (strtoupper((string) ($order['status'] ?? '')) !== 'COMPLETED') {
                $order = $api->captureOrder($orderId, (string) ($event['id'] ?? null));
            }
        } catch (PayPalProviderException $exception) {
            throw $exception;
        }

        return PayPalStatusMapper::fromOrder($order);
    }

    public function health(): HealthResult
    {
        if ($this->clientId() === null) {
            return HealthResult::fail(__('paypal::messages.health.missing_client_id'));
        }
        if ($this->clientSecret() === null) {
            return HealthResult::fail(__('paypal::messages.health.missing_secret'));
        }
        if ($this->webhookId() === null) {
            return HealthResult::fail(__('paypal::messages.health.missing_webhook'));
        }

        $api = $this->client();
        if ($api === null) {
            return HealthResult::fail(__('paypal::messages.health.missing_secret'));
        }

        try {
            $api->ping();
        } catch (PayPalProviderException $exception) {
            $key = $exception->errorKey;
            if ($key === 'paypal::messages.errors.unauthorized') {
                return HealthResult::fail(__('paypal::messages.health.unauthorized'));
            }

            return HealthResult::fail(__('paypal::messages.health.unreachable'));
        }

        $sandbox = filter_var($this->settings->get('paypal', 'sandbox', true), FILTER_VALIDATE_BOOLEAN);

        return HealthResult::ok(__('paypal::messages.health.ok', [
            'mode' => $sandbox ? 'sandbox' : 'live',
            'webhook' => $this->webhookUrl(),
        ]));
    }

    private function client(): ?PayPalApi
    {
        if ($this->api !== null) {
            return $this->api;
        }

        if ($this->clientId() === null || $this->clientSecret() === null) {
            return null;
        }

        return new HttpPayPalApi($this->settings);
    }

    private function clientId(): ?string
    {
        $value = $this->settings->get('paypal', 'client_id');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function clientSecret(): ?string
    {
        $value = $this->settings->get('paypal', 'client_secret');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function webhookId(): ?string
    {
        $value = $this->settings->get('paypal', 'webhook_id');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function webhookUrl(): string
    {
        return route('webhooks.payments', ['gateway' => self::ID], true);
    }

    private function isPayPalApprovalUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return ($parts['scheme'] ?? '') === 'https'
            && in_array($host, ['www.paypal.com', 'www.sandbox.paypal.com'], true);
    }

    private function isTrustedCertificateUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return ($parts['scheme'] ?? '') === 'https'
            && in_array($host, ['api-m.paypal.com', 'api-m.sandbox.paypal.com', 'api.paypal.com', 'api.sandbox.paypal.com'], true);
    }

    private function isProviderSubscriptionId(string $value): bool
    {
        return preg_match('/^I-[A-Za-z0-9_-]+$/', trim($value)) === 1;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private function firstString(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function minorAmount(mixed $value, mixed $currency): ?int
    {
        if ((! is_string($value) && ! is_int($value)) || ! is_string($currency) || trim($currency) === '') {
            return null;
        }

        try {
            return MoneyFormatter::minorFromMajorInput((string) $value, $currency);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function nextBillingAt(array $resource): ?string
    {
        $billingInfo = is_array($resource['billing_info'] ?? null) ? $resource['billing_info'] : [];
        return $this->firstString($billingInfo, ['next_billing_time'])
            ?? $this->firstString($resource, ['next_billing_time']);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function refundStatus(string $eventType, array $resource): string
    {
        return match ($eventType) {
            'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.SALE.REFUNDED' => 'approved',
            'PAYMENT.CAPTURE.REFUND.FAILED' => 'rejected',
            default => strtolower((string) ($resource['status'] ?? $resource['state'] ?? 'pending')),
        };
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function approvalUrl(array $order): ?string
    {
        $links = $order['links'] ?? null;
        if (! is_array($links)) {
            return null;
        }

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }
            if (($link['rel'] ?? '') === 'approve' && is_string($link['href'] ?? null) && $link['href'] !== '') {
                return $link['href'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $event
     */
    private function externalIdFromResource(array $resource, array $event): string
    {
        $id = (string) ($resource['id'] ?? '');
        if (str_starts_with($id, 'WH-') || str_starts_with($id, 'CAPTURE') || str_starts_with($id, '8')) {
            $supplementary = is_array($resource['supplementary_data'] ?? null)
                ? ($resource['supplementary_data']['related_ids']['order_id'] ?? null)
                : null;
            if (is_string($supplementary) && $supplementary !== '') {
                return $supplementary;
            }
        }

        if (str_starts_with($id, '5O') || str_starts_with($id, '7')) {
            return $id;
        }

        $related = $event['resource_type'] ?? '';
        if ($related === 'checkout-order' && $id !== '') {
            return $id;
        }

        return $id;
    }

    private function captureId(Payment $payment): ?string
    {
        $attempt = $this->latestExternalAttempt($payment);
        if ($attempt === null) {
            return null;
        }

        $meta = is_array($attempt->response_meta) ? $attempt->response_meta : [];
        $captureId = $meta['capture_id'] ?? null;
        if (is_string($captureId) && $captureId !== '') {
            return $captureId;
        }

        $api = $this->client();
        if ($api === null || $attempt->external_id === null) {
            return null;
        }

        try {
            $order = $api->getOrder((string) $attempt->external_id);
        } catch (PayPalProviderException) {
            return null;
        }

        return $this->captureIdFromOrder($order);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function captureIdFromOrder(array $order): ?string
    {
        $units = $order['purchase_units'] ?? [];
        if (! is_array($units)) {
            return null;
        }

        foreach ($units as $unit) {
            if (! is_array($unit)) {
                continue;
            }
            $payments = is_array($unit['payments'] ?? null) ? $unit['payments'] : [];
            $captures = is_array($payments['captures'] ?? null) ? $payments['captures'] : [];
            foreach ($captures as $capture) {
                if (is_array($capture) && isset($capture['id']) && is_string($capture['id']) && $capture['id'] !== '') {
                    return $capture['id'];
                }
            }
        }

        return null;
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
