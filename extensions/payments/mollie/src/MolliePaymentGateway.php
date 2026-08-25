<?php

declare(strict_types=1);

namespace Agovena\Extensions\Mollie;

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
use App\Support\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class MolliePaymentGateway implements CancelsPayments, ChargesRecurringPayments, OffersCheckoutMethods, OffersReusablePaymentAuthorization, PaymentGateway, SynchronizesPayments
{
    public const ID = 'mollie';

    /** @var array<string, mixed>|null */
    private ?array $verifiedPayment = null;

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
        private readonly ApplyNormalizedPaymentStatus $applyStatus,
        private readonly ?MollieApi $api = null,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'mollie::messages.gateway.label';
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
        // Paymenter-style: one storefront option; Mollie hosted checkout lists methods.
        return [new CheckoutPaymentMethod(self::ID, self::ID, $this->label())];
    }

    public function initiate(PaymentInitiation $request): PaymentInitiationResult
    {
        $api = $this->client();
        if ($api === null) {
            return PaymentInitiationResult::failed(__('mollie::messages.errors.not_configured'));
        }

        $payload = [
            'amount' => [
                'currency' => $request->payment->currency,
                'value' => MoneyFormatter::majorInputFromMinor((int) $request->payment->amount, $request->payment->currency),
            ],
            'description' => $request->order->number,
            'redirectUrl' => $request->returnUrl,
            'cancelUrl' => $request->cancelUrl,
            'webhookUrl' => $this->webhookUrl(),
            'metadata' => [
                'order_id' => $request->order->id,
                'payment_id' => $request->payment->id,
            ],
            'locale' => $this->locale(),
        ];

        // Prefer admin-restricted methods; otherwise Mollie hosted checkout shows profile methods.
        $restricted = $this->enabledMethodIds();
        if (count($restricted) === 1) {
            $payload['method'] = $restricted[0];
        } elseif (count($restricted) > 1) {
            $payload['method'] = $restricted;
        }

        $this->attachCustomerSequence($api, $request, $payload);

        try {
            $created = $api->createPayment($payload, $request->idempotencyKey);
        } catch (MollieProviderException) {
            Log::warning('payment.initiate.failed', [
                'gateway_id' => self::ID,
                'order_id' => $request->order->id,
            ]);

            return PaymentInitiationResult::failed(__('mollie::messages.errors.create_failed'));
        }

        $checkout = $created['checkout_url'] ?? null;
        if (! is_string($checkout) || $checkout === '') {
            return PaymentInitiationResult::failed(__('mollie::messages.errors.create_failed'));
        }

        $this->rememberMandate($request, $created);

        return PaymentInitiationResult::redirect(
            url: $checkout,
            externalId: (string) ($created['id'] ?? ''),
            metadata: [
                'provider_status' => (string) ($created['status'] ?? ''),
                'mode' => (string) ($created['mode'] ?? ''),
            ],
        );
    }

    public function reusableAuthorization(?int $customerId, string $customerEmail): ReusablePaymentAuthorization
    {
        $row = $this->mandateRow($customerId, $customerEmail);
        if ($row === null || $row->mandate_id === null || $row->mandate_id === '') {
            return ReusablePaymentAuthorization::missing(self::ID);
        }

        return ReusablePaymentAuthorization::active(self::ID, $row->updated_at);
    }

    public function charge(PaymentInitiation $request): PaymentInitiationResult
    {
        $api = $this->client();
        if ($api === null) {
            return PaymentInitiationResult::failed(__('mollie::messages.errors.not_configured'));
        }

        $mandate = $this->mandateFor($request);
        if ($mandate === null || $mandate->mandate_id === null || $mandate->mandate_id === '') {
            return PaymentInitiationResult::failed(__('mollie::messages.errors.recurring_unavailable'), [
                'reason' => 'authorization_missing',
            ]);
        }

        try {
            $created = $api->createPayment([
                'amount' => [
                    'currency' => $request->payment->currency,
                    'value' => MoneyFormatter::majorInputFromMinor((int) $request->payment->amount, $request->payment->currency),
                ],
                'description' => $request->order->number,
                'sequenceType' => 'recurring',
                'customerId' => $mandate->mollie_customer_id,
                'mandateId' => $mandate->mandate_id,
                'webhookUrl' => $this->webhookUrl(),
                'metadata' => [
                    'order_id' => $request->order->id,
                    'payment_id' => $request->payment->id,
                ],
            ], $request->idempotencyKey);
        } catch (MollieProviderException) {
            return PaymentInitiationResult::failed(__('mollie::messages.errors.create_failed'));
        }

        $status = MollieStatusMapper::map((string) ($created['status'] ?? 'open'));
        if ($status === PaymentStatus::Paid) {
            return PaymentInitiationResult::completed(
                externalId: (string) ($created['id'] ?? ''),
                metadata: ['provider_status' => (string) ($created['status'] ?? '')],
            );
        }

        return PaymentInitiationResult::pending(
            externalId: (string) ($created['id'] ?? ''),
            metadata: ['provider_status' => (string) ($created['status'] ?? '')],
        );
    }

    public function mapStatus(string $providerStatus): PaymentStatus
    {
        return MollieStatusMapper::map($providerStatus);
    }

    public function verifyWebhook(Request $request): bool
    {
        $id = $request->input('id');
        if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9_]{4,64}$/', $id)) {
            return false;
        }

        $api = $this->client();
        if ($api === null) {
            return false;
        }

        try {
            $this->verifiedPayment = $api->getPayment($id);
        } catch (MollieProviderException) {
            return false;
        }

        return isset($this->verifiedPayment['id']);
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $payment = $this->verifiedPayment;
        if ($payment === null) {
            $id = (string) $request->input('id');
            $api = $this->client() ?? throw MollieProviderException::failed('mollie::messages.errors.not_configured');
            $payment = $api->getPayment($id);
        }

        $status = $this->statusFromProviderPayment($payment);
        $externalId = (string) ($payment['id'] ?? '');
        $this->captureMandateFromRemote($payment);

        return new WebhookPayload(
            externalEventId: $externalId.':'.$status->value,
            externalPaymentId: $externalId,
            status: $status,
            raw: [
                'id' => $externalId,
                'status' => (string) ($payment['status'] ?? ''),
            ],
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $api = $this->client();
        if ($api === null) {
            return RefundResult::fail(__('mollie::messages.errors.not_configured'));
        }

        $attempt = $this->latestExternalAttempt($request->payment);
        if ($attempt?->external_id === null) {
            return RefundResult::fail(__('mollie::messages.errors.refund_failed'));
        }

        try {
            $refund = $api->refundPayment($attempt->external_id, [
                'amount' => [
                    'currency' => $request->currency,
                    'value' => MoneyFormatter::majorInputFromMinor($request->amount, $request->currency),
                ],
                'description' => $request->reason ?: $request->payment->order?->number,
            ], $request->idempotencyKey);
        } catch (MollieProviderException) {
            return RefundResult::fail(__('mollie::messages.errors.refund_failed'));
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
            $remote = $api->getPayment($attempt->external_id);
        } catch (MollieProviderException) {
            Log::warning('payment.sync.failed', [
                'gateway_id' => self::ID,
                'payment_id' => $payment->id,
            ]);

            return $payment;
        }

        $this->rememberMandateFromPayment($payment, $remote);
        $this->applyStatus->handle($attempt, $this->statusFromProviderPayment($remote));

        return $payment->fresh() ?? $payment;
    }

    public function cancel(Payment $payment): Payment
    {
        $api = $this->client();
        if ($api === null) {
            throw ValidationException::withMessages([
                'payment' => __('mollie::messages.errors.not_configured'),
            ]);
        }

        $attempt = $this->latestExternalAttempt($payment);
        if ($attempt?->external_id === null) {
            throw ValidationException::withMessages([
                'payment' => __('mollie::messages.errors.cancel_unsupported'),
            ]);
        }

        try {
            $remote = $api->getPayment($attempt->external_id);
            if (! ($remote['is_cancelable'] ?? false)) {
                throw ValidationException::withMessages([
                    'payment' => __('mollie::messages.errors.cancel_unsupported'),
                ]);
            }
            $remote = $api->cancelPayment($attempt->external_id);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (MollieProviderException) {
            throw ValidationException::withMessages([
                'payment' => __('mollie::messages.errors.cancel_unsupported'),
            ]);
        }

        $this->applyStatus->handle($attempt, $this->statusFromProviderPayment($remote));

        return $payment->fresh() ?? $payment;
    }

    public function health(): HealthResult
    {
        $key = $this->apiKey();
        if ($key === null) {
            return HealthResult::fail(__('mollie::messages.health.missing_key'));
        }
        if (! preg_match('/^(test|live)_[A-Za-z0-9]+$/', $key)) {
            return HealthResult::fail(__('mollie::messages.health.invalid_key'));
        }

        $api = $this->client();
        if ($api === null) {
            return HealthResult::fail(__('mollie::messages.health.missing_key'));
        }

        try {
            $methods = $api->listEnabledMethods();
        } catch (MollieProviderException) {
            return HealthResult::fail(__('mollie::messages.health.unreachable'));
        }

        $this->settings->set('mollie', 'discovered_methods', $methods, secret: false);

        $ids = array_map(static fn (array $row): string => $row['id'], $methods);
        $mode = str_starts_with($key, 'live_') ? 'live' : 'test';

        return HealthResult::ok(__('mollie::messages.health.ok', [
            'mode' => $mode,
            'methods' => $ids !== [] ? implode(', ', $ids) : '-',
            'webhook' => $this->webhookUrl(),
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function attachCustomerSequence(MollieApi $api, PaymentInitiation $request, array &$payload): void
    {
        $mandate = $this->mandateFor($request);
        if ($mandate !== null) {
            $payload['customerId'] = $mandate->mollie_customer_id;
            $payload['sequenceType'] = $mandate->mandate_id ? 'oneoff' : 'first';

            return;
        }

        $email = (string) $request->order->customer_email;
        if ($email === '') {
            return;
        }

        try {
            $customer = $api->createCustomer([
                'name' => (string) $request->order->customer_name,
                'email' => $email,
            ]);
        } catch (MollieProviderException) {
            return;
        }

        $payload['customerId'] = $customer['id'] ?? null;
        $payload['sequenceType'] = 'first';
        $this->storeMandate(
            $request->order->customer_id !== null ? (int) $request->order->customer_id : null,
            $email,
            (string) ($customer['id'] ?? ''),
            null,
        );
    }

    /**
     * @param array<string, mixed> $created
     */
    private function rememberMandate(PaymentInitiation $request, array $created): void
    {
        $this->rememberMandateFromPayment($request->payment, $created);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function captureMandateFromRemote(array $remote): void
    {
        $paymentId = $remote['metadata']['payment_id'] ?? null;
        if (! is_numeric($paymentId)) {
            return;
        }

        $payment = Payment::query()->find((int) $paymentId);
        if ($payment === null) {
            return;
        }

        $this->rememberMandateFromPayment($payment, $remote);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function rememberMandateFromPayment(Payment $payment, array $remote): void
    {
        $customerId = $remote['customer_id'] ?? $remote['customerId'] ?? null;
        $mandateId = $remote['mandate_id'] ?? $remote['mandateId'] ?? null;
        if (! is_string($customerId) || $customerId === '') {
            return;
        }

        $order = $payment->order;
        if ($order === null) {
            return;
        }

        $this->storeMandate(
            $order->customer_id !== null ? (int) $order->customer_id : null,
            (string) $order->customer_email,
            $customerId,
            is_string($mandateId) && $mandateId !== '' ? $mandateId : null,
        );
    }

    private function storeMandate(?int $customerId, string $email, string $mollieCustomerId, ?string $mandateId): void
    {
        if (! Schema::hasTable('mollie_mandates') || $mollieCustomerId === '') {
            return;
        }

        $row = null;
        if ($customerId !== null) {
            $row = MollieMandate::query()->where('customer_id', $customerId)->first();
        }
        if ($row === null && $email !== '') {
            $row = MollieMandate::query()->where('customer_email', $email)->first();
        }

        $values = [
            'customer_id' => $customerId,
            'customer_email' => $email,
            'mollie_customer_id' => $mollieCustomerId,
        ];
        if ($mandateId !== null) {
            $values['mandate_id'] = $mandateId;
        }

        if ($row === null) {
            MollieMandate::query()->create($values);

            return;
        }

        $row->fill($values);
        $row->save();
    }

    private function mandateFor(PaymentInitiation $request): ?MollieMandate
    {
        return $this->mandateRow(
            $request->order->customer_id !== null ? (int) $request->order->customer_id : null,
            (string) $request->order->customer_email,
        );
    }

    private function mandateRow(?int $customerId, string $email): ?MollieMandate
    {
        if (! Schema::hasTable('mollie_mandates')) {
            return null;
        }

        if ($customerId !== null) {
            $row = MollieMandate::query()->where('customer_id', $customerId)->first();
            if ($row !== null) {
                return $row;
            }
        }

        if ($email === '') {
            return null;
        }

        return MollieMandate::query()->where('customer_email', $email)->first();
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function statusFromProviderPayment(array $payment): PaymentStatus
    {
        $amount = $payment['amount']['value'] ?? '0';
        $currency = (string) ($payment['amount']['currency'] ?? 'EUR');
        $paidMinor = 0;
        if (is_string($amount) && $amount !== '') {
            try {
                $paidMinor = MoneyFormatter::minorFromMajorInput($amount, $currency);
            } catch (\Throwable) {
                $paidMinor = 0;
            }
        }

        $refunded = $payment['amount_refunded'] ?? null;

        return MollieStatusMapper::map(
            (string) ($payment['status'] ?? 'open'),
            is_array($refunded) ? $refunded : null,
            $paidMinor,
            $currency,
        );
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

    private function client(): ?MollieApi
    {
        if ($this->api !== null) {
            return $this->api;
        }

        $key = $this->apiKey();
        if ($key === null) {
            return null;
        }

        return new SdkMollieApi($key);
    }

    private function apiKey(): ?string
    {
        $key = $this->settings->get('mollie', 'api_key');
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return trim($key);
    }

    private function webhookUrl(): string
    {
        return route('webhooks.payments', ['gateway' => self::ID], true);
    }

    private function locale(): ?string
    {
        $locale = app()->getLocale();

        return match ($locale) {
            'nl' => 'nl_NL',
            'en' => 'en_US',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function enabledMethodIds(): array
    {
        $raw = $this->settings->get('mollie', 'enabled_methods', '');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = strtolower(trim($part));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
