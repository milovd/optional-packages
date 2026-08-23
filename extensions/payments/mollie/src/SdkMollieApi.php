<?php

declare(strict_types=1);

namespace Agovena\Extensions\Mollie;

use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\RequestException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Method;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Refund;
use Throwable;

/**
 * Official Mollie PHP SDK adapter. SDK types stay inside this class.
 */
final class SdkMollieApi implements MollieApi
{
    public function __construct(
        private readonly string $apiKey,
    ) {}

    public function createPayment(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->run(function (MollieApiClient $client) use ($payload, $idempotencyKey): array {
            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $client->setIdempotencyKey($idempotencyKey);
            }

            $payment = $client->payments->create($payload);

            return $this->paymentToArray($payment);
        });
    }

    public function getPayment(string $id): array
    {
        return $this->run(function (MollieApiClient $client) use ($id): array {
            return $this->paymentToArray($client->payments->get($id));
        });
    }

    public function cancelPayment(string $id): array
    {
        return $this->run(function (MollieApiClient $client) use ($id): array {
            return $this->paymentToArray($client->payments->cancel($id));
        });
    }

    public function refundPayment(string $paymentId, array $payload, ?string $idempotencyKey = null): array
    {
        return $this->run(function (MollieApiClient $client) use ($paymentId, $payload, $idempotencyKey): array {
            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $client->setIdempotencyKey($idempotencyKey);
            }

            /** @var Refund $refund */
            $refund = $client->paymentRefunds->createForId($paymentId, $payload);

            return [
                'id' => (string) $refund->id,
                'status' => (string) $refund->status,
                'payment_id' => $paymentId,
            ];
        });
    }

    public function listEnabledMethods(): array
    {
        return $this->run(function (MollieApiClient $client): array {
            $methods = [];
            foreach ($client->methods->allEnabled() as $method) {
                if (! $method instanceof Method) {
                    continue;
                }
                $methods[] = [
                    'id' => (string) $method->id,
                    'description' => (string) $method->description,
                ];
            }

            return $methods;
        });
    }

    public function createCustomer(array $payload): array
    {
        return $this->run(function (MollieApiClient $client) use ($payload): array {
            $customer = $client->customers->create($payload);

            return [
                'id' => (string) $customer->id,
                'name' => (string) $customer->name,
                'email' => (string) ($customer->email ?? ''),
            ];
        });
    }

    /**
     * @template T
     *
     * @param  callable(MollieApiClient): T  $callback
     * @return T
     */
    private function run(callable $callback): mixed
    {
        try {
            $client = new MollieApiClient;
            $client->setApiKey($this->apiKey);

            return $callback($client);
        } catch (ApiException|RequestException $exception) {
            throw MollieProviderException::failed('mollie::messages.errors.create_failed');
        } catch (Throwable $exception) {
            report($exception);
            throw MollieProviderException::failed('mollie::messages.health.unreachable');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentToArray(Payment $payment): array
    {
        $checkout = null;
        if (isset($payment->_links->checkout->href) && is_string($payment->_links->checkout->href)) {
            $checkout = $payment->_links->checkout->href;
        }

        $amountRefunded = null;
        if (isset($payment->amountRefunded->value, $payment->amountRefunded->currency)) {
            $amountRefunded = [
                'value' => (string) $payment->amountRefunded->value,
                'currency' => (string) $payment->amountRefunded->currency,
            ];
        }

        $amount = [
            'value' => (string) $payment->amount->value,
            'currency' => (string) $payment->amount->currency,
        ];

        return [
            'id' => (string) $payment->id,
            'status' => (string) $payment->status,
            'mode' => (string) $payment->mode,
            'checkout_url' => $checkout,
            'is_cancelable' => (bool) $payment->isCancelable,
            'sequence_type' => (string) $payment->sequenceType,
            'customer_id' => is_string($payment->customerId) && $payment->customerId !== '' ? $payment->customerId : null,
            'mandate_id' => is_string($payment->mandateId) && $payment->mandateId !== '' ? $payment->mandateId : null,
            'amount' => $amount,
            'amount_refunded' => $amountRefunded,
            'metadata' => is_array($payment->metadata)
                ? $payment->metadata
                : (array) ($payment->metadata ?? []),
        ];
    }
}
