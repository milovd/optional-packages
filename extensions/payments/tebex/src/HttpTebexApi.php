<?php

declare(strict_types=1);

namespace Agovena\Extensions\Tebex;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpTebexApi implements TebexApi, TebexConnectionChecker
{
    private const BASE_URL = 'https://checkout.tebex.io/api';

    public function __construct(
        private readonly string $projectId,
        private readonly string $secretKey,
    ) {}

    public function createCheckout(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->request('post', '/checkout', $payload, $idempotencyKey);
    }


    public function getPayment(string $transactionId): array
    {
        return $this->request('get', '/payments/'.rawurlencode($transactionId).'?type=txn_id');
    }

    public function getRecurringPayment(string $reference): array
    {
        return $this->request('get', '/recurring-payments/'.rawurlencode($reference));
    }

    public function cancelRecurringPayment(string $reference): array
    {
        return $this->request('delete', '/recurring-payments/'.rawurlencode($reference));
    }

    public function updateRecurringPaymentStatus(string $reference, string $status, ?string $pausedUntil = null): array
    {
        $payload = ['status' => $status];
        if ($pausedUntil !== null) {
            $payload['paused_until'] = $pausedUntil;
        }

        return $this->request('put', '/recurring-payments/'.rawurlencode($reference).'/status', $payload);
    }

    public function ping(): void
    {
        try {
            $response = Http::withBasicAuth($this->projectId, $this->secretKey)
                ->acceptJson()
                ->timeout(20)
                ->get(self::BASE_URL.'/payments/tbx-0000000000000000000000000000000000000000?type=txn_id');
        } catch (ConnectionException) {
            throw TebexProviderException::unknown('tebex::messages.errors.request_failed');
        } catch (Throwable) {
            throw TebexProviderException::failed('tebex::messages.errors.request_failed');
        }

        // Tebex has no non-mutating project-info endpoint. A 404 for a
        // well-formed, non-existent payment proves the credentials reached the API.
        if ($response->status() === 404) {
            return;
        }

        try {
            $response->throw();
        } catch (RequestException $exception) {
            if ($exception->response?->serverError() ?? false) {
                throw TebexProviderException::unknown('tebex::messages.errors.request_failed');
            }

            throw TebexProviderException::failed('tebex::messages.errors.request_failed');
        } catch (Throwable) {
            throw TebexProviderException::failed('tebex::messages.errors.request_failed');
        }
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
            $response = match ($method) {
                'get' => $request->get(self::BASE_URL.$path),
                'delete' => $request->delete(self::BASE_URL.$path),
                'put' => $payload === null
                    ? $request->put(self::BASE_URL.$path)
                    : $request->put(self::BASE_URL.$path, $payload),
                default => $payload === null
                    ? $request->post(self::BASE_URL.$path)
                    : $request->post(self::BASE_URL.$path, $payload),
            };
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
