<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Support;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Security\OutboundHttpUrlValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

abstract class AbstractHttpServerApi implements ServerApi
{
    /** @param array<string, mixed> $connection */
    public function __construct(
        protected readonly ExtensionSettingsRepository $settings,
        OutboundHttpUrlValidator|array $urlValidatorOrConnection = [],
        protected array $connection = [],
    ) {
        if ($urlValidatorOrConnection instanceof OutboundHttpUrlValidator) {
            $this->urlValidator = $urlValidatorOrConnection;
        } else {
            $this->urlValidator = app(OutboundHttpUrlValidator::class);
            $this->connection = $urlValidatorOrConnection;
        }
    }

    protected readonly OutboundHttpUrlValidator $urlValidator;

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
        throw new ServerProviderException('errors.action_unavailable');
    }

    /**
     * Provider adapters must implement their own capacity semantics. A shared
     * endpoint would risk treating an unrelated vendor response as capacity.
     *
     * @param  array<string, mixed>  $requirements
     */
    public function availableCapacity(array $requirements): int
    {
        unset($requirements);

        throw new ServerProviderException('errors.capacity_unsupported');
    }

    /** @return array<string, mixed>|null */
    public function findServerByExternalId(string $externalId): ?array
    {
        throw new ServerProviderException('errors.action_unavailable');
    }

    /** @return array<string, mixed> */
    public function getServer(string $externalId): array
    {
        throw new ServerProviderException('errors.action_unavailable');
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createServer(array $payload): array
    {
        throw new ServerProviderException('errors.action_unavailable');
    }

    public function suspend(string $externalId): void
    {
        throw new ServerProviderException('errors.action_unavailable');
    }

    public function unsuspend(string $externalId): void
    {
        throw new ServerProviderException('errors.action_unavailable');
    }

    public function terminate(string $externalId): void
    {
        throw new ServerProviderException('errors.action_unavailable');
    }

    /** @param array<string, mixed> $payload */
    public function changePlan(string $externalId, array $payload): void
    {
        throw new ServerProviderException('errors.action_unavailable');
    }

    public function action(string $externalId, string $action): void
    {
        throw new ServerProviderException('errors.action_unavailable');
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
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $baseUrl = $this->urlValidator->validate((string) ($this->connection['api_url'] ?? ''));
        $url = rtrim($baseUrl, '/').$path;
        if ($url === $path) {
            throw new ServerProviderException('errors.not_configured');
        }

        $timeout = max(1, (int) ($this->connection['timeout'] ?? 20));
        $verifyTls = filter_var($this->connection['verify_tls'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verifyTls === null) {
            throw new ServerProviderException('errors.invalid_mapping');
        }
        try {
            $this->urlValidator->assertTlsVerification($verifyTls);
        } catch (ValidationException) {
            throw new ServerProviderException('errors.invalid_mapping');
        }
        $options = ['verify' => $verifyTls];
        if (! in_array(config('app.env'), ['local', 'testing'], true)) {
            $options['curl'] = [CURLOPT_RESOLVE => [$this->resolveHost($baseUrl)]];
        }

        $pending = Http::timeout($timeout)
            ->withHeaders($this->headers())
            ->acceptJson()
            ->withOptions($options)
            ->withoutRedirecting();

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

    private function resolveHost(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'http' ? 80 : 443));
        $address = $this->urlValidator->resolvePublicIp($baseUrl);
        $resolved = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '['.$address.']'
            : $address;

        return $host.':'.$port.':'.$resolved;
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
