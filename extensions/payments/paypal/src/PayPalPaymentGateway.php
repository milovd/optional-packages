<?php

declare(strict_types=1);

namespace Agovena\Extensions\PayPal;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\ApplyNormalizedPaymentStatus;
use App\Agovena\Payments\CheckoutPaymentMethod;
use App\Agovena\Payments\Contracts\CancelsPayments;
use App\Agovena\Payments\Contracts\OffersCheckoutMethods;
use App\Agovena\Payments\Contracts\PaymentGateway;
use App\Agovena\Payments\Contracts\SynchronizesPayments;
use App\Agovena\Payments\HealthResult;
use App\Agovena\Payments\PaymentGatewayCapabilities;
use App\Agovena\Payments\PaymentInitiation;
use App\Agovena\Payments\PaymentInitiationResult;
use App\Agovena\Payments\RefundRequest;
use App\Agovena\Payments\RefundResult;
use App\Agovena\Payments\WebhookPayload;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Support\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class PayPalPaymentGateway implements CancelsPayments, OffersCheckoutMethods, PaymentGateway, SynchronizesPayments
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
            recurring: false,
            webhooks: true,
            redirect: true,
            statusSync: true,
            cancelPending: true,
        );
    }

    public function checkoutMethods(): array
    {
        return [
            new CheckoutPaymentMethod(
                gatewayId: self::ID,
                id: self::ID.':paypal',
                label: $this->label(),
            ),
        ];
    }

    public function initiate(PaymentInitiation $request): PaymentInitiationResult
    {
        $api = $this->client();
        if ($api === null) {
            return PaymentInitiationResult::failed(__('paypal::messages.errors.not_configured'));
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
        } catch (PayPalProviderException) {
            Log::warning('payment.initiate.failed', [
                'gateway_id' => self::ID,
                'order_id' => $request->order->id,
            ]);

            return PaymentInitiationResult::failed(__('paypal::messages.errors.create_failed'));
        }

        $url = $this->approvalUrl($order);
        if ($url === null) {
            return PaymentInitiationResult::failed(__('paypal::messages.errors.create_failed'));
        }

        return PaymentInitiationResult::redirect(
            url: $url,
            externalId: (string) ($order['id'] ?? ''),
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

        return isset($this->verifiedEvent['id'], $this->verifiedEvent['event_type']);
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

        $status = PayPalStatusMapper::fromWebhookEvent($event) ?? PaymentStatus::Pending;
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $externalPaymentId = $this->externalIdFromResource($resource, $event);

        return new WebhookPayload(
            externalEventId: (string) ($event['id'] ?? ''),
            externalPaymentId: $externalPaymentId !== '' ? $externalPaymentId : null,
            status: $status,
            raw: [
                'id' => (string) ($event['id'] ?? ''),
                'event_type' => (string) ($event['event_type'] ?? ''),
                'resource_id' => (string) ($resource['id'] ?? ''),
            ],
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $api = $this->client();
        if ($api === null) {
            return RefundResult::fail(__('paypal::messages.errors.not_configured'));
        }

        $captureId = $this->captureId($request->payment);
        if ($captureId === null) {
            return RefundResult::fail(__('paypal::messages.errors.refund_failed'));
        }

        try {
            $refund = $api->refundCapture($captureId, [
                'amount' => [
                    'currency_code' => strtoupper($request->currency),
                    'value' => MoneyFormatter::majorInputFromMinor($request->amount, $request->currency),
                ],
                'note_to_payer' => $request->reason ?: $request->payment->order?->number,
            ], $request->idempotencyKey);
        } catch (PayPalProviderException) {
            return RefundResult::fail(__('paypal::messages.errors.refund_failed'));
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

    public function cancel(Payment $payment): Payment
    {
        throw ValidationException::withMessages([
            'payment' => __('paypal::messages.errors.cancel_unsupported'),
        ]);
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
