<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\ApplyNormalizedPaymentStatus;
use App\Agovena\Payments\CheckoutPaymentMethod;
use App\Agovena\Payments\Contracts\OffersCheckoutMethods;
use App\Agovena\Payments\Contracts\PaymentGateway;
use App\Agovena\Payments\Contracts\SynchronizesPayments;
use App\Agovena\Payments\Contracts\ValidatesWebhookPayload;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class PaddlePaymentGateway implements OffersCheckoutMethods, PaymentGateway, SynchronizesPayments, ValidatesWebhookPayload
{
    public const ID = 'paddle';

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
            partialRefunds: false,
            recurring: false,
            webhooks: true,
            redirect: true,
            statusSync: true,
        );
    }

    public function checkoutMethods(): array
    {
        return [new CheckoutPaymentMethod(self::ID, self::ID.':paddle', $this->label())];
    }

    public function initiate(PaymentInitiation $request): PaymentInitiationResult
    {
        $api = $this->client();
        if ($api === null) {
            return PaymentInitiationResult::failed(__('paddle::messages.errors.not_configured'));
        }

        $priceMap = $this->priceMap();
        $items = [];
        foreach ($request->order->items as $item) {
            $priceId = $priceMap[(string) $item->product_id] ?? null;
            if (! is_string($priceId) || $priceId === '') {
                return PaymentInitiationResult::failed(__('paddle::messages.errors.price_mapping_missing'));
            }
            $items[] = ['price_id' => $priceId, 'quantity' => max(1, (int) $item->quantity)];
        }
        if ($items === []) {
            return PaymentInitiationResult::failed(__('paddle::messages.errors.items_missing'));
        }

        try {
            $transaction = $api->createTransaction([
                'items' => $items,
                'collection_mode' => 'automatic',
                'custom_data' => [
                    'order_id' => (string) $request->order->id,
                    'payment_id' => (string) $request->payment->id,
                ],
            ], $request->idempotencyKey);
        } catch (PaddleProviderException) {
            Log::warning('payment.initiate.failed', [
                'gateway_id' => self::ID,
                'order_id' => $request->order->id,
            ]);

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

        return PaymentInitiationResult::redirect(
            url: $url,
            externalId: trim($externalId),
            metadata: ['provider_status' => (string) ($transaction['status'] ?? '')],
        );
    }

    public function mapStatus(string $providerStatus): PaymentStatus
    {
        return PaddleStatusMapper::map($providerStatus);
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
                'currency_code' => $data['currency_code'] ?? null,
                'amount_minor' => $data['details']['totals']['grand_total'] ?? null,
                'line_items' => $data['details']['line_items'] ?? [],
                'custom_data' => $data['custom_data'] ?? [],
            ],
        );
    }

    public function validateWebhookPayload(PaymentAttempt $attempt, WebhookPayload $payload): bool
    {
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

        $expected = [];
        $priceMap = $this->priceMap();
        foreach ($payment->order->items as $item) {
            $priceId = $priceMap[(string) $item->product_id] ?? null;
            if (! is_string($priceId) || $priceId === '') {
                return false;
            }
            $expected[] = $priceId.':'.max(1, (int) $item->quantity);
        }

        $actual = [];
        foreach ((array) ($payload->raw['line_items'] ?? []) as $item) {
            if (! is_array($item) || ! isset($item['price_id'], $item['quantity'])) {
                return false;
            }
            $actual[] = (string) $item['price_id'].':'.max(1, (int) $item['quantity']);
        }
        sort($expected);
        sort($actual);

        return $expected === $actual;
    }

    public function refund(RefundRequest $request): RefundResult
    {
        if ($request->amount !== (int) $request->payment->amount
            || strtoupper($request->currency) !== strtoupper((string) $request->payment->currency)) {
            return RefundResult::fail(__('paddle::messages.errors.partial_refund_unsupported'));
        }

        $api = $this->client();
        $attempt = $this->latestExternalAttempt($request->payment);
        if ($api === null || $attempt?->external_id === null) {
            return RefundResult::fail(__('paddle::messages.errors.refund_failed'));
        }

        try {
            $adjustment = $api->createAdjustment(
                $attempt->external_id,
                $request->reason ?? '',
                'full',
                $request->idempotencyKey,
            );
        } catch (PaddleProviderException) {
            return RefundResult::fail(__('paddle::messages.errors.refund_failed'));
        }

        $externalRefundId = $adjustment['id'] ?? null;
        if (! is_string($externalRefundId) || ! str_starts_with($externalRefundId, 'adj_')) {
            return RefundResult::fail(__('paddle::messages.errors.refund_failed'));
        }

        return RefundResult::ok($externalRefundId);
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

        return HealthResult::ok(__('paddle::messages.health.ok', [
            'mode' => $this->sandbox() ? 'sandbox' : 'live',
            'webhook' => route('webhooks.payments', ['gateway' => self::ID], true),
        ]));
    }

    private function client(): ?PaddleApi
    {
        if ($this->api !== null) {
            return $this->api;
        }
        $key = $this->apiKey();

        return $key !== null ? new HttpPaddleApi($key, $this->sandbox()) : null;
    }

    /** @return array<string, string> */
    private function priceMap(): array
    {
        $value = $this->settings->get(self::ID, 'price_map', []);
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $productId => $priceId) {
            if (is_scalar($priceId) && trim((string) $priceId) !== '') {
                $map[(string) $productId] = trim((string) $priceId);
            }
        }

        return $map;
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
