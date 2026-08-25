<?php

declare(strict_types=1);

namespace Agovena\Extensions\Tebex;

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

final class TebexPaymentGateway implements OffersCheckoutMethods, PaymentGateway, SynchronizesPayments, ValidatesWebhookPayload
{
    public const ID = 'tebex';

    /** @var array<string, mixed>|null */
    private ?array $verifiedEvent = null;

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
        private readonly ApplyNormalizedPaymentStatus $applyStatus,
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
            recurring: false,
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

        $packageMap = $this->packageMap();
        $packages = [];
        foreach ($request->order->items as $item) {
            $packageId = $packageMap[(string) $item->product_id] ?? null;
            if (! is_string($packageId) || $packageId === '') {
                return PaymentInitiationResult::failed(__('tebex::messages.errors.package_mapping_missing'));
            }
            $packages[] = [$packageId, max(1, (int) $item->quantity)];
        }

        try {
            $basket = $api->createBasket([
                'email' => (string) $request->order->customer_email,
                'return_url' => $request->cancelUrl,
                'complete_url' => $request->returnUrl,
                'custom' => [
                    'order_id' => (string) $request->order->id,
                    'payment_id' => (string) $request->payment->id,
                ],
            ]);
            $ident = (string) ($basket['ident'] ?? $basket['id'] ?? '');
            if ($ident === '') {
                return PaymentInitiationResult::failed(__('tebex::messages.errors.create_failed'));
            }

            foreach ($packages as [$packageId, $quantity]) {
                $api->addPackage($ident, $packageId, $quantity);
            }
            $basket = $api->getBasket($ident);
        } catch (TebexProviderException) {
            Log::warning('payment.initiate.failed', [
                'gateway_id' => self::ID,
                'order_id' => $request->order->id,
            ]);

            return PaymentInitiationResult::failed(__('tebex::messages.errors.create_failed'));
        }

        $url = $basket['links']['checkout'] ?? null;
        if (! is_string($url) || $url === '') {
            return PaymentInitiationResult::failed(__('tebex::messages.errors.create_failed'));
        }

        return PaymentInitiationResult::redirect(
            url: $url,
            externalId: $ident,
            metadata: ['provider_status' => 'created', 'basket_ident' => $ident],
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
        $externalId = (string) ($subject['transaction_id'] ?? '');
        $status = $type !== ''
            ? TebexStatusMapper::fromWebhook($type)
            : TebexStatusMapper::fromPaymentStatusId($subject['status']['id'] ?? null);

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
            ],
        );
    }

    public function validateWebhookPayload(PaymentAttempt $attempt, WebhookPayload $payload): bool
    {
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

        $expected = [];
        $packageMap = $this->packageMap();
        foreach ($payment->order->items as $item) {
            $packageId = $packageMap[(string) $item->product_id] ?? null;
            if (! is_string($packageId) || $packageId === '') {
                return false;
            }
            $expected[] = $packageId.':'.max(1, (int) $item->quantity);
        }

        $actual = [];
        foreach ((array) ($payload->raw['products'] ?? []) as $product) {
            if (! is_array($product) || ! isset($product['id'], $product['quantity'])) {
                return false;
            }
            $actual[] = (string) $product['id'].':'.max(1, (int) $product['quantity']);
        }
        sort($expected);
        sort($actual);

        return $expected === $actual;
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
            $refund = $api->refundPayment($attempt->external_id, $request->reason);
        } catch (TebexProviderException) {
            return RefundResult::fail(__('tebex::messages.errors.refund_failed'));
        }

        $externalRefundId = $refund['id'] ?? $refund['transaction_id'] ?? null;
        if (! is_string($externalRefundId) || trim($externalRefundId) === '') {
            return RefundResult::fail(__('tebex::messages.errors.refund_failed'));
        }

        return RefundResult::ok(trim($externalRefundId));
    }

    public function syncStatus(Payment $payment): Payment
    {
        $api = $this->client();
        $attempt = $this->latestExternalAttempt($payment);
        if ($api === null || $attempt?->external_id === null) {
            return $payment;
        }

        try {
            $remote = $api->getPayment($attempt->external_id);
            $status = TebexStatusMapper::fromPaymentStatusId($remote['status']['id'] ?? null);
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

    /** @return array<string, string> */
    private function packageMap(): array
    {
        $value = $this->settings->get(self::ID, 'package_map', []);
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $productId => $packageId) {
            if (is_scalar($packageId) && trim((string) $packageId) !== '') {
                $map[(string) $productId] = trim((string) $packageId);
            }
        }

        return $map;
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
}
