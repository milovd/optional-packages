<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpPaddleApi implements PaddleApi
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

    public function createAdjustment(string $transactionId, string $reason, string $type = 'full', ?string $idempotencyKey = null): array
    {
        return $this->request('post', '/adjustments', [
            'action' => 'refund',
            'transaction_id' => $transactionId,
            'reason' => $reason !== '' ? $reason : 'Agovena refund',
            'type' => $type,
        ], $idempotencyKey);
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

            $response = $method === 'get'
                ? $request->get($this->baseUrl.$path)
                : $request->post($this->baseUrl.$path, $payload);
            $response->throw();
            $data = $response->json('data');
        } catch (Throwable) {
            throw PaddleProviderException::failed('paddle::messages.errors.request_failed');
        }

        if (! is_array($data)) {
            throw PaddleProviderException::failed('paddle::messages.errors.invalid_response');
        }

        return $data;
    }
}
