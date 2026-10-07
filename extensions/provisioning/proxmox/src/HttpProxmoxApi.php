<?php

declare(strict_types=1);

namespace Agovena\Extensions\Proxmox;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Security\OutboundHttpUrlValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

final class HttpProxmoxApi implements ProxmoxApi
{
    /** @param array<string, mixed> $connection */
    private readonly ExtensionSettingsRepository $settings;

    private readonly OutboundHttpUrlValidator $urlValidator;

    private readonly ?array $connection;

    /** @param array<string, mixed>|OutboundHttpUrlValidator $urlValidatorOrConnection */
    public function __construct(
        ExtensionSettingsRepository $settings,
        OutboundHttpUrlValidator|array $urlValidatorOrConnection = [],
        ?array $connection = null,
    ) {
        $this->settings = $settings;
        $this->urlValidator = is_array($urlValidatorOrConnection)
            ? app(OutboundHttpUrlValidator::class)
            : $urlValidatorOrConnection;
        $this->connection = is_array($urlValidatorOrConnection) ? $urlValidatorOrConnection : $connection;
    }

    public function withConnection(array $settings): ProxmoxApi
    {
        return new self($this->settings, $this->urlValidator, $settings !== [] ? $settings : null);
    }

    public function connectionTest(): array
    {
        return $this->request('GET', '/version');
    }

    public function nodeCapacity(string $node, string $storage): array
    {
        $nodeStatus = $this->request('GET', '/nodes/'.rawurlencode($node).'/status')['data'] ?? null;
        $storageStatus = $this->request('GET', '/nodes/'.rawurlencode($node).'/storage/'.rawurlencode($storage).'/status')['data'] ?? null;
        if (! is_array($nodeStatus) || ! is_array($storageStatus)) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
        }

        $cpuInfo = is_array($nodeStatus['cpuinfo'] ?? null) ? $nodeStatus['cpuinfo'] : [];

        return [
            'memory_free' => $nodeStatus['memory']['free'] ?? null,
            'cpu_cores' => $cpuInfo['cpus'] ?? $cpuInfo['cores'] ?? $nodeStatus['cpus'] ?? null,
            'storage_free' => $storageStatus['avail'] ?? null,
        ];
    }

    public function nextVmId(): int
    {
        $response = $this->request('GET', '/cluster/nextid');
        $value = $response['data'] ?? null;
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($parsed === false) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
        }

        return $parsed;
    }

    public function cloneVm(string $node, int $templateVmid, array $payload): string
    {
        $response = $this->request(
            'POST',
            '/nodes/'.rawurlencode($node).'/qemu/'.$templateVmid.'/clone',
            body: $payload,
        );

        $upid = $this->taskId($response, 'create');
        $this->waitForTask($node, $upid);

        return $upid;
    }

    public function updateConfig(string $node, int $vmid, array $payload): void
    {
        $this->request('PUT', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/config', body: $payload);
    }

    public function resizeDisk(string $node, int $vmid, string $disk, string $size): void
    {
        if (preg_match('/\A(?:scsi|virtio|sata|ide)\d+\z/', $disk) !== 1
            || preg_match('/\A\+?\d+(?:\.\d+)?[KMGT]?\z/', $size) !== 1
        ) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.invalid_mapping');
        }

        $response = $this->request(
            'PUT',
            '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/resize',
            body: ['disk' => $disk, 'size' => $size],
        );
        // Older Proxmox VE releases resize synchronously and return null; current ones return a UPID.
        if (($response['data'] ?? null) === null) {
            return;
        }

        $this->waitForTask($node, $this->taskId($response, 'resize'), maxAttempts: 120);
    }

    public function start(string $node, int $vmid): void
    {
        $response = $this->request('POST', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/status/start');
        $this->waitForTask($node, $this->taskId($response, 'start'), maxAttempts: 30);
    }

    public function stop(string $node, int $vmid): void
    {
        $response = $this->request('POST', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/status/stop');
        $this->waitForTask($node, $this->taskId($response, 'stop'), maxAttempts: 30);
    }

    public function deleteVm(string $node, int $vmid): void
    {
        if ($this->locateVm($node, $vmid) === null) {
            return;
        }

        $status = $this->currentStatus($node, $vmid)['status'];
        if ($status !== 'stopped') {
            $this->stop($node, $vmid);
        }

        $this->deleteAndWait($node, $vmid);
    }

    private function deleteAndWait(string $node, int $vmid): void
    {
        try {
            // purge drops the VMID from HA, backup and replication configs; Proxmox refuses to
            // destroy an HA-managed VM without it. DELETE parameters travel in the query string.
            $response = $this->request('DELETE', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid, query: ['purge' => 1]);
        } catch (ProxmoxProviderException $exception) {
            if ($exception->status === 404) {
                return;
            }

            throw $exception;
        }

        $this->waitForTask($node, $this->taskId($response, 'delete'), maxAttempts: 60);
    }

    public function currentStatus(string $node, int $vmid): array
    {
        $response = $this->request('GET', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/status/current');
        $data = $response['data'] ?? [];

        if (! is_array($data)
            || ! array_key_exists('status', $data)
            || ! is_string($data['status'])
            || trim($data['status']) === ''
        ) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
        }

        return $data;
    }

    public function findVmByName(string $node, string $name): ?array
    {
        $response = $this->request('GET', '/nodes/'.rawurlencode($node).'/qemu');
        $items = $response['data'] ?? null;
        if (! is_array($items)) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
        }
        foreach ($items as $item) {
            if (! is_array($item) || ! array_key_exists('vmid', $item)) {
                throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
            }
            $vmid = $this->positiveVmid($item['vmid']);
            if ($vmid === null) {
                throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
            }
            // "name" is optional in the documented VM index schema.
            if (! is_string($item['name'] ?? null) || $item['name'] !== $name) {
                continue;
            }

            return ['node' => $node, 'vmid' => $vmid, 'name' => $name];
        }

        return null;
    }

    public function findVmConfig(string $node, int $vmid): ?array
    {
        // Proxmox VE answers HTTP 500 "Configuration file ... does not exist" for an absent VMID,
        // so absence is decided from the cluster-wide resource index, never from an error status.
        if ($this->locateVm($node, $vmid) === null) {
            return null;
        }

        try {
            $response = $this->request('GET', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/config');
            $data = $response['data'] ?? null;
            if (! is_array($data)) {
                throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
            }

            return $data;
        } catch (ProxmoxProviderException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Returns the cluster resource entry for a QEMU VMID on the expected node, or null when the
     * VMID is not present anywhere in the cluster. A VMID that exists elsewhere (another node,
     * or as a container) fails closed instead of being reported as absent.
     *
     * @return array<string, mixed>|null
     */
    private function locateVm(string $node, int $vmid): ?array
    {
        $items = $this->request('GET', '/cluster/resources', query: ['type' => 'vm'])['data'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
            }
            if (! array_key_exists('vmid', $item)) {
                continue;
            }
            $itemVmid = $this->positiveVmid($item['vmid']);
            if ($itemVmid === null) {
                throw ProxmoxProviderException::failed('proxmox::messages.errors.malformed');
            }
            if ($itemVmid !== $vmid) {
                continue;
            }
            if (($item['type'] ?? null) !== 'qemu' || ($item['node'] ?? null) !== $node) {
                throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed', 409);
            }

            return $item;
        }

        return null;
    }

    /** @param array<string, mixed> $response */
    private function taskId(array $response, string $operation): string
    {
        $taskId = $response['data'] ?? null;
        $errorKey = $operation === 'create'
            ? 'proxmox::messages.errors.create_failed'
            : 'proxmox::messages.errors.provider_failed';
        if (! is_string($taskId)) {
            throw ProxmoxProviderException::failed($errorKey);
        }

        $taskId = trim($taskId);
        if (preg_match('/\AUPID:[^:\s]+(?::[^:\s]+){5,8}:\z/D', $taskId) !== 1) {
            throw ProxmoxProviderException::failed($errorKey);
        }

        return $taskId;
    }

    private function waitForTask(string $node, string $upid, int $maxAttempts = 120): void
    {
        sleep(1);
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $response = $this->request('GET', '/nodes/'.rawurlencode($node).'/tasks/'.rawurlencode($upid).'/status');
            $status = (string) (($response['data']['status'] ?? '') ?: '');
            if ($status === 'stopped') {
                $taskData = $response['data'] ?? null;
                // pve-common PVE::UPID::status_is_error: only "OK" and "WARNINGS: <n>" are successful.
                if (! is_array($taskData)
                    || ! array_key_exists('exitstatus', $taskData)
                    || ! is_string($taskData['exitstatus'])
                    || ($taskData['exitstatus'] !== 'OK' && preg_match('/\AWARNINGS: \d+\z/', $taskData['exitstatus']) !== 1)
                ) {
                    throw ProxmoxProviderException::failed('proxmox::messages.errors.create_failed');
                }

                return;
            }
            if ($status !== 'running') {
                throw ProxmoxProviderException::failed('proxmox::messages.errors.provider_failed');
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
        try {
            $baseUrl = $this->urlValidator->validate(ProxmoxApiUrl::normalize($this->apiUrl()));
        } catch (ValidationException) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.invalid_mapping');
        }
        $url = rtrim($baseUrl, '/').'/api2/json'.$path;
        $timeout = max(1, (int) $this->setting('timeout', 30));
        $verify = filter_var($this->setting('verify_tls', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verify === null) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.invalid_mapping');
        }
        try {
            $this->urlValidator->assertTlsVerification($verify);
        } catch (ValidationException) {
            throw ProxmoxProviderException::failed('proxmox::messages.errors.invalid_mapping');
        }

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
                'DELETE' => $pending->delete($query === [] ? $url : $url.'?'.http_build_query($query)),
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
        if ($this->connection !== null) {
            return array_key_exists($key, $this->connection)
                ? $this->connection[$key]
                : $default;
        }

        return $this->settings->get('proxmox', $key, $default);
    }

    private function positiveVmid(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $vmid = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $vmid === false ? null : $vmid;
    }
}
