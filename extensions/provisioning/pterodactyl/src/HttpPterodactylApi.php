<?php

declare(strict_types=1);

namespace Agovena\Extensions\Pterodactyl;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpPterodactylApi implements PterodactylApi
{
    /** @param array<string, mixed> $connection */
    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
        private readonly array $connection = [],
    ) {}

    public function withConnection(array $settings): PterodactylApi
    {
        return new self($this->settings, $settings);
    }

    public function connectionTest(): array
    {
        return $this->application('GET', '/api/application/servers', ['per_page' => 1]);
    }

    public function findServerByExternalId(string $externalId): ?array
    {
        try {
            return $this->unwrapServer(
                $this->application('GET', '/api/application/servers/external/'.rawurlencode($externalId)),
            );
        } catch (PterodactylProviderException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }
    }

    public function getServer(int $serverId): array
    {
        return $this->unwrapServer(
            $this->application('GET', '/api/application/servers/'.$serverId),
        );
    }

    public function getEgg(int $nestId, int $eggId): array
    {
        return $this->unwrap(
            $this->application('GET', '/api/application/nests/'.$nestId.'/eggs/'.$eggId, [
                'include' => 'variables',
            ]),
        );
    }

    public function createServer(array $payload): array
    {
        return $this->unwrapServer(
            $this->application('POST', '/api/application/servers', body: $payload),
        );
    }

    public function suspend(int $serverId): void
    {
        $this->application('POST', '/api/application/servers/'.$serverId.'/suspend');
    }

    public function unsuspend(int $serverId): void
    {
        $this->application('POST', '/api/application/servers/'.$serverId.'/unsuspend');
    }

    public function delete(int $serverId): void
    {
        $this->application('DELETE', '/api/application/servers/'.$serverId);
    }

    public function updateBuild(int $serverId, array $payload): array
    {
        return $this->unwrapServer(
            $this->application('PATCH', '/api/application/servers/'.$serverId.'/build', body: $payload),
        );
    }

    public function clientServer(string $identifier): array
    {
        return $this->unwrap(
            $this->client('GET', '/api/client/servers/'.rawurlencode($identifier)),
        );
    }

    public function power(string $identifier, string $signal): void
    {
        $this->client('POST', '/api/client/servers/'.rawurlencode($identifier).'/power', body: [
            'signal' => $signal,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function application(string $method, string $path, array $query = [], ?array $body = null): array
    {
        return $this->request($method, $path, $this->applicationKey(), $query, $body);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function client(string $method, string $path, ?array $body = null): array
    {
        $key = $this->clientKey();
        if ($key === null) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.power_unavailable');
        }

        return $this->request($method, $path, $key, body: $body, accept: 'application/json');
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        string $token,
        array $query = [],
        ?array $body = null,
        string $accept = 'application/vnd.pterodactyl.v1+json',
    ): array {
        $url = PterodactylPanelUrl::normalize($this->panelUrl()).$path;
        $timeout = max(1, (int) $this->setting('timeout', 15));
        $verify = filter_var($this->setting('verify_tls', true), FILTER_VALIDATE_BOOLEAN);

        try {
            $pending = Http::withToken($token)
                ->accept($accept)
                ->timeout($timeout)
                ->withOptions([
                    'verify' => $verify,
                    'allow_redirects' => false,
                ]);

            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url, $query),
                'POST' => $pending->post($url, $body ?? []),
                'PATCH' => $pending->patch($url, $body ?? []),
                'DELETE' => $pending->delete($url, $body ?? []),
                default => throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed'),
            };
        } catch (PterodactylProviderException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.timeout');
        } catch (Throwable) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.unreachable');
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.unauthorized', $response->status());
        }

        if ($response->status() === 404) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.not_found', 404);
        }

        if ($response->failed()) {
            throw PterodactylProviderException::failed($this->errorKeyFromResponse($response), $response->status());
        }

        if ($response->body() === '' || $response->body() === '[]') {
            return [];
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function unwrap(array $payload): array
    {
        $attributes = $payload['attributes'] ?? null;
        if (is_array($attributes)) {
            return $attributes;
        }

        $data = $payload['data'] ?? null;
        if (is_array($data) && isset($data['attributes']) && is_array($data['attributes'])) {
            return $data['attributes'];
        }

        if ($payload === []) {
            return [];
        }

        throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function unwrapServer(array $payload): array
    {
        $attributes = $this->unwrap($payload);
        if (! isset($attributes['id'], $attributes['identifier'])) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
        }

        return $attributes;
    }

    private function errorKeyFromResponse(Response $response): string
    {
        $json = $response->json();
        $detail = '';
        if (is_array($json) && isset($json['errors'][0]['detail']) && is_string($json['errors'][0]['detail'])) {
            $detail = strtolower($json['errors'][0]['detail']);
        }

        if (str_contains($detail, 'egg') || str_contains($detail, 'nest') || str_contains($detail, 'location')) {
            return 'pterodactyl::messages.errors.invalid_egg';
        }

        if (str_contains($detail, 'memory') || str_contains($detail, 'disk') || str_contains($detail, 'cpu') || str_contains($detail, 'enough')) {
            return 'pterodactyl::messages.errors.quota';
        }

        return 'pterodactyl::messages.errors.provider_failed';
    }

    private function panelUrl(): string
    {
        return trim((string) $this->setting('panel_url', ''));
    }

    private function applicationKey(): string
    {
        $key = trim((string) $this->setting('application_api_key', ''));
        if ($key === '') {
            throw PterodactylProviderException::failed('pterodactyl::messages.health.missing_key');
        }

        return $key;
    }

    private function clientKey(): ?string
    {
        $key = trim((string) $this->setting('client_api_key', ''));

        return $key !== '' ? $key : null;
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->connection)
            ? $this->connection[$key]
            : $this->settings->get('pterodactyl', $key, $default);
    }
}
