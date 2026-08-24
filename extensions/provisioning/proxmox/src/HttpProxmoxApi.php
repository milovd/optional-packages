<?php

declare(strict_types=1);

namespace Agovena\Extensions\Proxmox;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpProxmoxApi implements ProxmoxApi
{
    /** @param array<string, mixed> $connection */
    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
        private readonly array $connection = [],
    ) {}

    public function withConnection(array $settings): ProxmoxApi
    {
        return new self($this->settings, $settings);
    }

    public function connectionTest(): array
    {
        return $this->request('GET', '/version');
    }

    public function nextVmId(): int
    {
        $response = $this->request('GET', '/cluster/nextid');

        return (int) ($response['data'] ?? 100);
    }

    public function cloneVm(string $node, int $templateVmid, array $payload): string
    {
        $response = $this->request(
            'POST',
            '/nodes/'.rawurlencode($node).'/qemu/'.$templateVmid.'/clone',
            body: $payload,
        );

        $upid = (string) ($response['data'] ?? '');
        if ($upid === '') {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.create_failed');
        }

        $this->waitForTask($node, $upid);

        return $upid;
    }

    public function updateConfig(string $node, int $vmid, array $payload): void
    {
        $this->request('PUT', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/config', body: $payload);
    }

    public function start(string $node, int $vmid): void
    {
        $response = $this->request('POST', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/status/start');
        $upid = (string) ($response['data'] ?? '');
        if ($upid !== '') {
            $this->waitForTask($node, $upid, maxAttempts: 30);
        }
    }

    public function stop(string $node, int $vmid): void
    {
        $response = $this->request('POST', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/status/stop');
        $upid = (string) ($response['data'] ?? '');
        if ($upid !== '') {
            $this->waitForTask($node, $upid, maxAttempts: 30);
        }
    }

    public function deleteVm(string $node, int $vmid): void
    {
        try {
            $this->stop($node, $vmid);
        } catch (ProxmoxProviderException) {
            // Best effort stop before delete.
        }

        $response = $this->request('DELETE', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid);
        $upid = (string) ($response['data'] ?? '');
        if ($upid !== '') {
            $this->waitForTask($node, $upid, maxAttempts: 60);
        }
    }

    public function currentStatus(string $node, int $vmid): array
    {
        $response = $this->request('GET', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/status/current');
        $data = $response['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    public function findVmConfig(string $node, int $vmid): ?array
    {
        try {
            $response = $this->request('GET', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/config');
            $data = $response['data'] ?? null;

            return is_array($data) ? $data : null;
        } catch (ProxmoxProviderException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }
    }

    private function waitForTask(string $node, string $upid, int $maxAttempts = 120): void
    {
        sleep(1);
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $response = $this->request('GET', '/nodes/'.rawurlencode($node).'/tasks/'.rawurlencode($upid).'/status');
            $status = (string) (($response['data']['status'] ?? '') ?: '');
            if ($status === 'stopped') {
                $exit = (string) ($response['data']['exitstatus'] ?? 'OK');
                if ($exit !== '' && $exit !== 'OK') {
                    throw ProxmoxProviderException::failed('proxmox::messages.errors.create_failed');
                }

                return;
            }
            if ($status !== 'running') {
                return;
            }
            sleep(1);
        }

        throw ProxmoxProviderException::failed('proxmox::messages.errors.timeout');
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = ProxmoxApiUrl::normalize($this->apiUrl()).'/api2/json'.$path;
        $timeout = max(1, (int) $this->setting('timeout', 30));
        $verify = filter_var($this->setting('verify_tls', true), FILTER_VALIDATE_BOOLEAN);

        try {
            $pending = Http::withHeaders([
                'Authorization' => 'PVEAPIToken='.$this->tokenCredential(),
                'Accept' => 'application/json',
            ])->timeout($timeout)
                ->withOptions([
                    'verify' => $verify,
                    'allow_redirects' => false,
                ]);

            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url, $query),
                'POST' => $pending->post($url, $body ?? []),
                'PUT' => $pending->put($url, $body ?? []),
                'DELETE' => $pending->delete($url, $body ?? []),
                default => throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed'),
            };
        } catch (ProxmoxProviderException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.timeout');
        } catch (Throwable) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.unreachable');
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.unauthorized', $response->status());
        }

        if ($response->status() === 404) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.not_found', 404);
        }

        if ($response->failed()) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed', $response->status());
        }

        if ($response->body() === '' || $response->body() === '[]') {
            return [];
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
        }

        return $json;
    }

    private function apiUrl(): string
    {
        return trim((string) $this->setting('api_url', ''));
    }

    private function tokenCredential(): string
    {
        $user = trim((string) $this->setting('token_user', ''));
        $tokenId = trim((string) $this->setting('token_id', ''));
        $secret = trim((string) $this->setting('token_secret', ''));
        if ($user === '' || $tokenId === '' || $secret === '') {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.not_configured');
        }

        return $user.'!'.$tokenId.'='.$secret;
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->connection)
            ? $this->connection[$key]
            : $this->settings->get('proxmox', $key, $default);
    }
}
