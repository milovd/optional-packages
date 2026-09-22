<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpPaddleApi implements PaddleApi, PaddleConnectionChecker
{
    private string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        bool $sandbox = true,
    ) {
        $this->baseUrl = $sandbox ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';
    }

    public function createTransaction(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->request('post', '/transactions', $payload, $idempotencyKey);
    }

    public function getTransaction(string $transactionId): array
    {
        return $this->request('get', '/transactions/'.rawurlencode($transactionId));
    }

    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('get', '/subscriptions/'.rawurlencode($subscriptionId));
    }

    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = true): array
    {
        return $this->request('post', '/subscriptions/'.rawurlencode($subscriptionId).'/cancel', [
            'effective_from' => $atPeriodEnd ? 'next_billing_period' : 'immediately',
        ]);
    }

    public function clearScheduledSubscriptionChange(string $subscriptionId): array
    {
        return $this->request('patch', '/subscriptions/'.rawurlencode($subscriptionId), [
            'scheduled_change' => null,
        ]);
    }

    public function ping(): void
    {
        $this->request('get', '/products?per_page=1');
    }

    /**
     * @param  list<array{item_id: string, type: string, amount?: string}>|null  $items
     */
    public function createAdjustment(
        string $transactionId,
        string $reason,
        string $type = 'full',
        ?array $items = null,
        ?string $idempotencyKey = null,
    ): array
    {
        $payload = [
            'action' => 'refund',
            'transaction_id' => $transactionId,
            'reason' => $reason !== '' ? $reason : 'Agovena refund',
            'type' => $type,
        ];
        if ($type === 'partial' && $items !== null) {
            $payload['items'] = $items;
            $payload['tax_mode'] = 'internal';
        }

        return $this->request('post', '/adjustments', $payload, $idempotencyKey);
    }

    /** @param array<string, mixed> $payload */
    private function request(string $method, string $path, array $payload = [], ?string $idempotencyKey = null): array
    {
        try {
            $request = Http::withToken($this->apiKey)
                ->acceptJson()
                ->withHeaders(['Paddle-Version' => '1'])
                ->timeout(20);
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            }

            $response = match ($method) {
                'get' => $request->get($this->baseUrl.$path),
                'patch' => $request->patch($this->baseUrl.$path, $payload),
                default => $request->post($this->baseUrl.$path, $payload),
            };
            $response->throw();
            $data = $response->json('data');
        } catch (ConnectionException) {
            throw PaddleProviderException::unknown('paddle::messages.errors.unknown_outcome');
        } catch (Throwable) {
            throw PaddleProviderException::failed('paddle::messages.errors.request_failed');
        }

        if (! is_array($data)) {
            throw PaddleProviderException::failed('paddle::messages.errors.invalid_response');
        }

        return $data;
    }
}
