<?php

declare(strict_types=1);

namespace Agovena\Extensions\Stripe;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\ApplyNormalizedPaymentStatus;
use App\Agovena\Payments\CheckoutPaymentMethod;
use App\Agovena\Payments\Contracts\CancelsPayments;
use App\Agovena\Payments\Contracts\ChargesRecurringPayments;
use App\Agovena\Payments\Contracts\OffersCheckoutMethods;
use App\Agovena\Payments\Contracts\OffersReusablePaymentAuthorization;
use App\Agovena\Payments\Contracts\PaymentGateway;
use App\Agovena\Payments\Contracts\SynchronizesPayments;
use App\Agovena\Payments\HealthResult;
use App\Agovena\Payments\PaymentGatewayCapabilities;
use App\Agovena\Payments\PaymentInitiation;
use App\Agovena\Payments\PaymentInitiationResult;
use App\Agovena\Payments\RefundRequest;
use App\Agovena\Payments\RefundResult;
use App\Agovena\Payments\ReusablePaymentAuthorization;
use App\Agovena\Payments\WebhookPayload;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class StripePaymentGateway implements CancelsPayments, ChargesRecurringPayments, OffersCheckoutMethods, OffersReusablePaymentAuthorization, PaymentGateway, SynchronizesPayments
{
    public const ID = 'stripe';

    /** @var array<string, mixed>|null */
    private ?array $verifiedEvent = null;

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
        private readonly ApplyNormalizedPaymentStatus $applyStatus,
        private readonly ?StripeApi $api = null,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'stripe::messages.gateway.label';
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
            cancelPending: true,
        );
    }

    public function checkoutMethods(): array
    {
        // Paymenter-style: one storefront option; Stripe Checkout lists methods.
        return [new CheckoutPaymentMethod(self::ID, self::ID, $this->label())];
    }

    public function initiate(PaymentInitiation $request): PaymentInitiationResult
    {
        $api = $this->client();
        if ($api === null) {
            return PaymentInitiationResult::failed(__('stripe::messages.errors.not_configured'));
        }

        $payload = [
            'mode' => 'payment',
            'success_url' => $request->returnUrl,
            'cancel_url' => $request->cancelUrl,
            'client_reference_id' => (string) $request->payment->id,
            'metadata' => [
                'order_id' => (string) $request->order->id,
                'payment_id' => (string) $request->payment->id,
            ],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($request->payment->currency),
                    'unit_amount' => (int) $request->payment->amount,
                    'product_data' => ['name' => $request->order->number],
                ],
            ]],
            'payment_intent_data' => [
                'setup_future_usage' => 'off_session',
                'metadata' => [
                    'order_id' => (string) $request->order->id,
                    'payment_id' => (string) $request->payment->id,
                ],
            ],
        ];

        $authorization = $this->authorizationFor($request);
        if ($authorization !== null) {
            $payload['customer'] = $authorization->stripe_customer_id;
        } else {
            $payload['customer_email'] = $request->order->customer_email;
            $payload['customer_creation'] = 'always';
        }

        $restricted = $this->enabledMethodIds();
        if ($restricted !== []) {
            $payload['payment_method_types'] = $restricted;
        }
        // Otherwise omit types - Stripe Checkout shows dashboard-configured methods (Paymenter-style).

        try {
            $session = $api->createCheckoutSession($payload, $request->idempotencyKey);
        } catch (StripeProviderException) {
            Log::warning('payment.initiate.failed', [
                'gateway_id' => self::ID,
                'order_id' => $request->order->id,
            ]);

            return PaymentInitiationResult::failed(__('stripe::messages.errors.create_failed'));
        }

        $url = $session['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return PaymentInitiationResult::failed(__('stripe::messages.errors.create_failed'));
        }

        $this->rememberCustomerFromSession($request, $session);

        return PaymentInitiationResult::redirect(
            url: $url,
            externalId: $this->externalIdFromSession($session),
            metadata: [
                'provider_status' => (string) ($session['status'] ?? 'open'),
                'session_id' => (string) ($session['id'] ?? ''),
            ],
        );
    }

    public function reusableAuthorization(?int $customerId, string $customerEmail): ReusablePaymentAuthorization
    {
        $row = $this->authorizationRow($customerId, $customerEmail);
        if ($row === null || $row->status === 'revoked' || $row->payment_method_id === null || $row->payment_method_id === '') {
            if ($row !== null && $row->status === 'revoked') {
                return ReusablePaymentAuthorization::revoked(self::ID, $row->last_verified_at);
            }

            return ReusablePaymentAuthorization::missing(self::ID);
        }

        return ReusablePaymentAuthorization::active(self::ID, $row->last_verified_at ?? $row->updated_at);
    }

    public function charge(PaymentInitiation $request): PaymentInitiationResult
    {
        $api = $this->client();
        if ($api === null) {
            return PaymentInitiationResult::failed(__('stripe::messages.errors.not_configured'));
        }

        $authorization = $this->authorizationFor($request);
        if ($authorization === null || $authorization->payment_method_id === null || $authorization->payment_method_id === '' || $authorization->status === 'revoked') {
            return PaymentInitiationResult::failed(__('stripe::messages.errors.recurring_unavailable'), [
                'reason' => 'authorization_missing',
            ]);
        }

        try {
            $intent = $api->createPaymentIntent([
                'amount' => (int) $request->payment->amount,
                'currency' => strtolower($request->payment->currency),
                'customer' => $authorization->stripe_customer_id,
                'payment_method' => $authorization->payment_method_id,
                'off_session' => true,
                'confirm' => true,
                'metadata' => [
                    'order_id' => (string) $request->order->id,
                    'payment_id' => (string) $request->payment->id,
                ],
            ], $request->idempotencyKey);
        } catch (StripeProviderException) {
            return PaymentInitiationResult::failed(__('stripe::messages.errors.create_failed'));
        }

        $status = StripeStatusMapper::fromPaymentIntent($intent);
        $externalId = (string) ($intent['id'] ?? '');
        if ($status === PaymentStatus::Paid) {
            $this->rememberAuthorizationFromIntent($request->order->customer_id !== null ? (int) $request->order->customer_id : null, (string) $request->order->customer_email, $intent);

            return PaymentInitiationResult::completed(
                externalId: $externalId,
                metadata: ['provider_status' => (string) ($intent['status'] ?? '')],
            );
        }

        if (in_array((string) ($intent['status'] ?? ''), ['requires_action', 'requires_confirmation'], true)) {
            $url = is_array($intent['next_action'] ?? null)
                ? (string) (($intent['next_action']['redirect_to_url']['url'] ?? '') ?: '')
                : '';

            return PaymentInitiationResult::redirect(
                url: $url !== '' ? $url : $request->returnUrl,
                externalId: $externalId,
                metadata: ['provider_status' => (string) ($intent['status'] ?? '')],
            );
        }

        if ($status === PaymentStatus::Failed || $status === PaymentStatus::Cancelled) {
            return PaymentInitiationResult::failed(__('stripe::messages.errors.create_failed'), [
                'provider_status' => (string) ($intent['status'] ?? ''),
            ]);
        }

        return PaymentInitiationResult::pending(
            externalId: $externalId,
            metadata: ['provider_status' => (string) ($intent['status'] ?? '')],
        );
    }

    public function mapStatus(string $providerStatus): PaymentStatus
    {
        return StripeStatusMapper::map($providerStatus);
    }

    public function verifyWebhook(Request $request): bool
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');
        $secret = $this->webhookSecret();
        if ($payload === '' || $signature === '' || $secret === null) {
            return false;
        }

        $api = $this->client();
        if ($api === null) {
            return false;
        }

        try {
            $this->verifiedEvent = $api->constructEvent($payload, $signature, $secret);
        } catch (StripeProviderException) {
            return false;
        }

        return isset($this->verifiedEvent['id'], $this->verifiedEvent['type']);
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $event = $this->verifiedEvent;
        if ($event === null) {
            throw StripeProviderException::failed('stripe::messages.errors.webhook_invalid');
        }

        $type = (string) ($event['type'] ?? '');
        $object = is_array($event['data'] ?? null) && is_array($event['data']['object'] ?? null)
            ? $event['data']['object']
            : [];
        $status = StripeStatusMapper::fromEventType($type, $object);
        $externalPaymentId = $this->paymentIdFromObject($object);
        $this->captureAuthorizationFromObject($object);

        return new WebhookPayload(
            externalEventId: (string) ($event['id'] ?? ''),
            externalPaymentId: $externalPaymentId !== '' ? $externalPaymentId : null,
            status: $status,
            raw: [
                'id' => (string) ($event['id'] ?? ''),
                'type' => $type,
                'object_id' => (string) ($object['id'] ?? ''),
            ],
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $api = $this->client();
        if ($api === null) {
            return RefundResult::fail(__('stripe::messages.errors.not_configured'));
        }

        $intentId = $this->paymentIntentId($request->payment);
        if ($intentId === null) {
            return RefundResult::fail(__('stripe::messages.errors.refund_failed'));
        }

        try {
            $refund = $api->refundPaymentIntent($intentId, [
                'amount' => $request->amount,
            ], $request->idempotencyKey);
        } catch (StripeProviderException) {
            return RefundResult::fail(__('stripe::messages.errors.refund_failed'));
        }

        return RefundResult::ok((string) ($refund['id'] ?? ''));
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
            $remote = $this->retrieveByExternalId($api, (string) $attempt->external_id, $attempt);
        } catch (StripeProviderException) {
            Log::warning('payment.sync.failed', [
                'gateway_id' => self::ID,
                'payment_id' => $payment->id,
            ]);

            return $payment;
        }

        $this->rememberAuthorizationFromIntent(
            $payment->order->customer_id !== null ? (int) $payment->order->customer_id : null,
            (string) $payment->order->customer_email,
            $remote,
        );
        $this->applyStatus->handle($attempt, StripeStatusMapper::fromPaymentIntent($remote));

        return $payment->fresh() ?? $payment;
    }

    public function cancel(Payment $payment): Payment
    {
        $api = $this->client();
        if ($api === null) {
            throw ValidationException::withMessages([
                'payment' => __('stripe::messages.errors.not_configured'),
            ]);
        }

        $intentId = $this->paymentIntentId($payment);
        if ($intentId === null) {
            throw ValidationException::withMessages([
                'payment' => __('stripe::messages.errors.cancel_unsupported'),
            ]);
        }

        try {
            $remote = $api->retrievePaymentIntent($intentId);
            $status = (string) ($remote['status'] ?? '');
            if (! in_array($status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture'], true)) {
                throw ValidationException::withMessages([
                    'payment' => __('stripe::messages.errors.cancel_unsupported'),
                ]);
            }
            $remote = $api->cancelPaymentIntent($intentId);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (StripeProviderException) {
            throw ValidationException::withMessages([
                'payment' => __('stripe::messages.errors.cancel_unsupported'),
            ]);
        }

        $attempt = $this->latestExternalAttempt($payment);
        if ($attempt !== null) {
            $this->applyStatus->handle($attempt, StripeStatusMapper::fromPaymentIntent($remote));
        }

        return $payment->fresh() ?? $payment;
    }

    public function health(): HealthResult
    {
        $key = $this->secretKey();
        if ($key === null) {
            return HealthResult::fail(__('stripe::messages.health.missing_key'));
        }
        if (! preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $key)) {
            return HealthResult::fail(__('stripe::messages.health.invalid_key'));
        }
        if ($this->webhookSecret() === null) {
            return HealthResult::fail(__('stripe::messages.health.missing_webhook'));
        }

        $api = $this->client();
        if ($api === null) {
            return HealthResult::fail(__('stripe::messages.health.missing_key'));
        }

        try {
            $api->retrieveBalance();
        } catch (StripeProviderException) {
            return HealthResult::fail(__('stripe::messages.health.unreachable'));
        }

        $mode = str_starts_with($key, 'sk_live_') ? 'live' : 'test';

        return HealthResult::ok(__('stripe::messages.health.ok', [
            'mode' => $mode,
            'webhook' => $this->webhookUrl(),
        ]));
    }

    private function client(): ?StripeApi
    {
        if ($this->api !== null) {
            return $this->api;
        }

        $key = $this->secretKey();
        if ($key === null) {
            return null;
        }

        return new SdkStripeApi($key);
    }

    private function secretKey(): ?string
    {
        $key = $this->settings->get('stripe', 'secret_key');
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return trim($key);
    }

    private function webhookSecret(): ?string
    {
        $secret = $this->settings->get('stripe', 'webhook_secret');
        if (! is_string($secret) || trim($secret) === '') {
            return null;
        }

        return trim($secret);
    }

    private function webhookUrl(): string
    {
        return route('webhooks.payments', ['gateway' => self::ID], true);
    }

    /**
     * @return list<string>
     */
    private function enabledMethodIds(): array
    {
        $raw = $this->settings->get('stripe', 'enabled_methods', '');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $id) {
            $id = trim($id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function externalIdFromSession(array $session): string
    {
        $intent = $session['payment_intent'] ?? null;
        if (is_string($intent) && $intent !== '') {
            return $intent;
        }
        if (is_array($intent) && isset($intent['id']) && is_string($intent['id']) && $intent['id'] !== '') {
            return $intent['id'];
        }

        return (string) ($session['id'] ?? '');
    }

    /**
     * @param array<string, mixed> $object
     */
    private function paymentIdFromObject(array $object): string
    {
        $intent = $object['payment_intent'] ?? $object['id'] ?? '';
        if (is_array($intent)) {
            $intent = $intent['id'] ?? '';
        }
        $id = is_string($intent) ? $intent : '';
        if (str_starts_with($id, 'pi_') || str_starts_with($id, 'cs_')) {
            return $id;
        }

        return is_string($object['id'] ?? null) ? (string) $object['id'] : '';
    }

    private function paymentIntentId(Payment $payment): ?string
    {
        $attempt = $this->latestExternalAttempt($payment);
        $externalId = $attempt?->external_id;
        if (! is_string($externalId) || $externalId === '') {
            return null;
        }
        if (str_starts_with($externalId, 'pi_')) {
            return $externalId;
        }

        $sessionId = is_array($attempt->response_meta) ? ($attempt->response_meta['session_id'] ?? $externalId) : $externalId;
        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        $api = $this->client();
        if ($api === null) {
            return null;
        }

        try {
            $session = $api->retrieveCheckoutSession($sessionId);
        } catch (StripeProviderException) {
            return null;
        }

        $intent = $this->externalIdFromSession($session);

        return str_starts_with($intent, 'pi_') ? $intent : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function retrieveByExternalId(StripeApi $api, string $externalId, PaymentAttempt $attempt): array
    {
        if (str_starts_with($externalId, 'cs_')) {
            $session = $api->retrieveCheckoutSession($externalId);
            $intentId = $this->externalIdFromSession($session);
            if (str_starts_with($intentId, 'pi_')) {
                return $api->retrievePaymentIntent($intentId);
            }

            return [
                'id' => $intentId,
                'status' => ($session['payment_status'] ?? '') === 'paid' ? 'succeeded' : (string) ($session['status'] ?? 'open'),
                'amount' => $attempt->amount,
                'amount_refunded' => 0,
            ];
        }

        return $api->retrievePaymentIntent($externalId);
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

    private function authorizationFor(PaymentInitiation $request): ?StripePaymentAuthorization
    {
        return $this->authorizationRow(
            $request->order->customer_id !== null ? (int) $request->order->customer_id : null,
            (string) $request->order->customer_email,
        );
    }

    private function authorizationRow(?int $customerId, string $customerEmail): ?StripePaymentAuthorization
    {
        if (! Schema::hasTable('stripe_payment_authorizations')) {
            return null;
        }

        $query = StripePaymentAuthorization::query();
        if ($customerId !== null) {
            $row = (clone $query)->where('customer_id', $customerId)->first();
            if ($row !== null) {
                return $row;
            }
        }

        if ($customerEmail === '') {
            return null;
        }

        return $query->where('customer_email', $customerEmail)->orderByDesc('id')->first();
    }

    /**
     * @param array<string, mixed> $session
     */
    private function rememberCustomerFromSession(PaymentInitiation $request, array $session): void
    {
        $customer = $session['customer'] ?? null;
        $customerId = is_array($customer) ? ($customer['id'] ?? null) : $customer;
        if (! is_string($customerId) || $customerId === '') {
            return;
        }

        $this->storeAuthorization(
            $request->order->customer_id !== null ? (int) $request->order->customer_id : null,
            (string) $request->order->customer_email,
            $customerId,
            $this->paymentMethodId($session),
            'active',
        );
    }

    /**
     * @param array<string, mixed> $object
     */
    private function captureAuthorizationFromObject(array $object): void
    {
        $email = '';
        if (isset($object['customer_email']) && is_string($object['customer_email'])) {
            $email = $object['customer_email'];
        } elseif (is_array($object['customer_details'] ?? null) && isset($object['customer_details']['email']) && is_string($object['customer_details']['email'])) {
            $email = $object['customer_details']['email'];
        }
        $customer = $object['customer'] ?? null;
        $stripeCustomerId = is_array($customer) ? ($customer['id'] ?? null) : $customer;
        if (! is_string($stripeCustomerId) || $stripeCustomerId === '') {
            return;
        }

        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $agovenaCustomerId = isset($metadata['customer_id']) && is_numeric($metadata['customer_id'])
            ? (int) $metadata['customer_id']
            : null;

        $this->storeAuthorization(
            $agovenaCustomerId,
            $email,
            $stripeCustomerId,
            $this->paymentMethodId($object),
            'active',
        );
    }

    /**
     * @param array<string, mixed> $intent
     */
    private function rememberAuthorizationFromIntent(?int $customerId, string $email, array $intent): void
    {
        $customer = $intent['customer'] ?? null;
        $stripeCustomerId = is_array($customer) ? ($customer['id'] ?? null) : $customer;
        if (! is_string($stripeCustomerId) || $stripeCustomerId === '') {
            return;
        }

        $this->storeAuthorization($customerId, $email, $stripeCustomerId, $this->paymentMethodId($intent), 'active');
    }

    /**
     * @param array<string, mixed> $object
     */
    private function paymentMethodId(array $object): ?string
    {
        $method = $object['payment_method'] ?? null;
        if (is_array($method)) {
            $method = $method['id'] ?? null;
        }
        if (is_string($method) && str_starts_with($method, 'pm_')) {
            return $method;
        }

        $setup = $object['setup_intent'] ?? null;
        if (is_array($setup)) {
            return $this->paymentMethodId($setup);
        }

        return null;
    }

    private function storeAuthorization(?int $customerId, string $email, string $stripeCustomerId, ?string $paymentMethodId, string $status): void
    {
        if (! Schema::hasTable('stripe_payment_authorizations') || $stripeCustomerId === '') {
            return;
        }
        if ($email === '' && $customerId === null && $this->authorizationRow($customerId, $email) === null) {
            return;
        }

        $row = $this->authorizationRow($customerId, $email);
        if ($row === null) {
            $row = new StripePaymentAuthorization;
        }

        $row->customer_id = $customerId ?? $row->customer_id;
        if ($email !== '') {
            $row->customer_email = $email;
        } elseif (! $row->exists) {
            $row->customer_email = '';
        }
        $row->stripe_customer_id = $stripeCustomerId;
        if ($paymentMethodId !== null && $paymentMethodId !== '') {
            $row->payment_method_id = $paymentMethodId;
        }
        $row->status = $status;
        $row->last_verified_at = now();
        $row->save();
    }
}
