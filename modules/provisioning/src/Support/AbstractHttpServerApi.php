<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Support;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class AbstractHttpServerApi implements ServerApi
{
    /** @param array<string, mixed> $connection */
    public function __construct(
        protected readonly ExtensionSettingsRepository $settings,
        protected array $connection = [],
    ) {}

    /** @param array<string, mixed> $settings */
    public function withConnection(array $settings): static
    {
        $clone = clone $this;
        $clone->connection = $settings;

        return $clone;
    }

    /** @return array<string, mixed> */
    public function connectionTest(): array
    {
        return $this->request('GET', $this->healthPath());
    }

    /**
     * Provider adapters must implement their own capacity semantics. A shared
     * endpoint would risk treating an unrelated vendor response as capacity.
     *
     * @param array<string, mixed> $requirements
     */
    public function availableCapacity(array $requirements): int
    {
        unset($requirements);

        throw new ServerProviderException('errors.capacity_unsupported');
    }

    /** @return array<string, mixed>|null */
    public function findServerByExternalId(string $externalId): ?array
    {
        $payload = $this->request('GET', $this->collectionPath(), ['external_id' => $externalId]);
        $items = $payload['data'] ?? $payload['servers'] ?? $payload;
        if (! is_array($items)) {
            throw new ServerProviderException('errors.malformed');
        }

        if (array_is_list($items)) {
            foreach ($items as $item) {
                if (is_array($item) && (string) ($item['external_id'] ?? '') === $externalId) {
                    return $item;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function getServer(string $externalId): array
    {
        return $this->request('GET', $this->itemPath($externalId));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createServer(array $payload): array
    {
        return $this->request('POST', $this->collectionPath(), body: $payload);
    }

    public function suspend(string $externalId): void
    {
        $this->request('POST', $this->actionPath($externalId, 'suspend'));
    }

    public function unsuspend(string $externalId): void
    {
        $this->request('POST', $this->actionPath($externalId, 'unsuspend'));
    }

    public function terminate(string $externalId): void
    {
        $this->request('DELETE', $this->itemPath($externalId));
    }

    /** @param array<string, mixed> $payload */
    public function changePlan(string $externalId, array $payload): void
    {
        $this->request('PATCH', $this->itemPath($externalId), body: $payload);
    }

    public function action(string $externalId, string $action): void
    {
        $this->request('POST', $this->actionPath($externalId, $action));
    }

    abstract protected function collectionPath(): string;

    protected function healthPath(): string
    {
        return '/api/health';
    }

    protected function itemPath(string $externalId): string
    {
        return $this->collectionPath().'/'.rawurlencode($externalId);
    }

    protected function actionPath(string $externalId, string $action): string
    {
        return $this->itemPath($externalId).'/'.rawurlencode($action);
    }

    /** @return array<string, string> */
    protected function headers(): array
    {
        $token = trim((string) ($this->connection['api_token'] ?? ''));

        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ];
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = rtrim(trim((string) ($this->connection['api_url'] ?? '')), '/').$path;
        if ($url === $path) {
            throw new ServerProviderException('errors.not_configured');
        }

        $timeout = max(1, (int) ($this->connection['timeout'] ?? 20));
        $verifyTls = filter_var($this->connection['verify_tls'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verifyTls === null) {
            throw new ServerProviderException('errors.invalid_mapping');
        }
        $pending = Http::timeout($timeout)
            ->withHeaders($this->headers())
            ->acceptJson()
            ->withOptions(['verify' => $verifyTls]);

        try {
            $response = match ($method) {
                'GET' => $pending->get($url, $query),
                'POST' => $pending->post($url, $body ?? []),
                'PATCH' => $pending->patch($url, $body ?? []),
                'DELETE' => $pending->delete($url, $body ?? []),
                default => throw new ServerProviderException('errors.provider_failed'),
            };
        } catch (ServerProviderException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            throw new ServerProviderException('errors.timeout');
        } catch (Throwable) {
            throw new ServerProviderException('errors.unreachable');
        }

        return $this->decode($response);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw new ServerProviderException('errors.unauthorized', $response->status());
        }

        if ($response->status() === 404) {
            throw new ServerProviderException('errors.not_found', 404);
        }

        if ($response->failed()) {
            throw new ServerProviderException('errors.provider_failed', $response->status());
        }

        if ($response->body() === '' || $response->body() === '[]') {
            return [];
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new ServerProviderException('errors.malformed');
        }

        return $json;
    }
}
