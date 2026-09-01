<?php

declare(strict_types=1);

namespace Agovena\Extensions\Tebex;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

final class HttpTebexApi implements TebexApi
{
    private const BASE_URL = 'https://checkout.tebex.io/api';

    public function __construct(
        private readonly string $projectId,
        private readonly string $secretKey,
    ) {}

    public function createBasket(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->request('post', '/baskets', $payload, $idempotencyKey);
    }

    public function getBasket(string $ident): array
    {
        return $this->request('get', '/baskets/'.rawurlencode($ident));
    }

    public function addPackage(string $ident, string $packageId, int $quantity, ?string $idempotencyKey = null): array
    {
        return $this->request('post', '/baskets/'.rawurlencode($ident).'/packages', [
            'package' => ['id' => $packageId],
            'qty' => $quantity,
        ], $idempotencyKey);
    }

    public function getPayment(string $transactionId): array
    {
        return $this->request('get', '/payments/'.rawurlencode($transactionId).'?type=txn_id');
    }

    public function refundPayment(string $transactionId, ?string $reason = null, ?string $idempotencyKey = null): array
    {
        return $this->request('post', '/payments/'.rawurlencode($transactionId).'/refund?type=txn_id', null, $idempotencyKey);
    }

    /** @param array<string, mixed>|null $payload */
    private function request(string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null): array
    {
        try {
            $request = Http::withBasicAuth($this->projectId, $this->secretKey)
                ->acceptJson()
                ->timeout(20);
            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            }
            $response = $method === 'get'
                ? $request->get(self::BASE_URL.$path)
                : ($payload === null ? $request->post(self::BASE_URL.$path) : $request->post(self::BASE_URL.$path, $payload));
            $response->throw();
            $data = $response->json();
        } catch (ConnectionException) {
            throw TebexProviderException::unknown('tebex::messages.errors.request_failed');
        } catch (RequestException $exception) {
            if ($exception->response?->serverError() ?? false) {
                throw TebexProviderException::unknown('tebex::messages.errors.request_failed');
            }

            throw TebexProviderException::failed('tebex::messages.errors.request_failed');
        } catch (Throwable) {
            throw TebexProviderException::unknown('tebex::messages.errors.request_failed');
        }

        if (! is_array($data)) {
            throw TebexProviderException::unknown('tebex::messages.errors.invalid_response');
        }

        return $data;
    }
}
