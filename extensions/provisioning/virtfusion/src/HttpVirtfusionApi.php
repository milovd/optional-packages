<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtfusion;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;
use Agovena\Modules\Provisioning\Support\ServerProviderException;
use Illuminate\Validation\ValidationException;

final class HttpVirtfusionApi extends AbstractHttpServerApi implements VirtfusionApi
{
    /** Resource flags documented for PUT /servers/{id}/package/{packageId}. */
    private const PACKAGE_RESOURCES = [
        'backupPlan', 'cpu', 'memory', 'primaryDiskReadIOPS', 'primaryDiskReadThroughput', 'primaryDiskSize',
        'primaryDiskWriteIOPS', 'primaryDiskWriteThroughput', 'primaryNetworkInboundSpeed',
        'primaryNetworkOutboundSpeed', 'primaryNetworkTraffic',
    ];

    /** @return array<string, mixed> */
    public function connectionTest(): array
    {
        $this->expectEmpty($this->call('GET', '/connect'));

        return [];
    }

    /** @return list<int> */
    public function enabledPackageIds(): array
    {
        $packages = $this->call('GET', '/packages')['data'] ?? null;
        if (! is_array($packages) || ! array_is_list($packages)) {
            throw new ServerProviderException('errors.malformed');
        }

        $ids = [];
        foreach ($packages as $package) {
            $id = is_array($package) ? ($package['id'] ?? null) : null;
            $enabled = is_array($package) ? ($package['enabled'] ?? null) : null;
            if (! is_int($id) || ! is_bool($enabled)) {
                throw new ServerProviderException('errors.malformed');
            }
            if ($enabled) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function createUser(string $name, string $email, string $relation): int
    {
        return $this->userId($this->call('POST', '/users', body: [
            'name' => $name,
            'email' => $email,
            'relStr' => $relation,
            'sendMail' => true,
        ]));
    }

    public function userIdByRelation(string $relation): int
    {
        return $this->userId($this->call('GET', '/users/'.rawurlencode($relation).'/byExtRelation', ['relStr' => 'true']));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: int, ownerId: int, name: string|null, suspended: bool, buildFailed: bool, built: string|null}
     */
    public function createServer(array $payload): array
    {
        return $this->server($this->call('POST', '/servers', body: [
            'packageId' => (int) ($payload['packageId'] ?? 0),
            'userId' => (int) ($payload['userId'] ?? 0),
            'hypervisorId' => (int) ($payload['hypervisorId'] ?? 0),
        ]));
    }

    /** @return array{id: int, ownerId: int, name: string|null, suspended: bool, buildFailed: bool, built: string|null} */
    public function getServer(string $externalId): array
    {
        return $this->server($this->call('GET', $this->serverPath($externalId)), $externalId);
    }

    public function buildServer(string $serverId, int $templateId): void
    {
        $this->server($this->call('POST', $this->serverPath($serverId).'/build', body: [
            'operatingSystemId' => $templateId,
            'email' => true,
        ]), $serverId);
    }

    public function suspend(string $externalId): void
    {
        $this->expectEmpty($this->call('POST', $this->serverPath($externalId).'/suspend'));
    }

    public function unsuspend(string $externalId): void
    {
        $this->expectEmpty($this->call('POST', $this->serverPath($externalId).'/unsuspend'));
    }

    public function terminate(string $externalId): void
    {
        $this->expectEmpty($this->call('DELETE', $this->serverPath($externalId)));
    }

    /** The documented answer is 200 with an "info" list of resources that were not changed. */
    public function changePackage(string $serverId, int $packageId): void
    {
        if ($packageId < 1) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        // Every documented resource follows the new package, so the body is always a JSON object.
        $info = $this->call('PUT', $this->serverPath($serverId).'/package/'.$packageId, body: array_fill_keys(self::PACKAGE_RESOURCES, true))['info'] ?? null;
        if (! is_array($info) || ! array_is_list($info) || count(array_filter($info, 'is_string')) !== count($info)) {
            throw new ServerProviderException('errors.malformed');
        }
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if (app()->environment('demo')) {
            throw new ServerProviderException('errors.demo_disabled');
        }
        if (trim((string) ($this->connection['api_token'] ?? '')) === '') {
            throw new ServerProviderException('errors.not_configured');
        }

        $endpoint = VirtfusionEndpoint::normalize((string) ($this->connection['api_url'] ?? ''));
        try {
            return $this->withConnection(['api_url' => $endpoint] + $this->connection)
                ->request($method, '/api/v1'.$path, $query, $body);
        } catch (ValidationException) {
            throw new ServerProviderException('errors.invalid_mapping');
        }
    }

    private function serverPath(string $serverId): string
    {
        if (! ctype_digit($serverId) || (int) $serverId < 1) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        return '/servers/'.$serverId;
    }

    /**
     * Documented action endpoints answer 204 without a body; anything else fails closed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function expectEmpty(array $payload): void
    {
        if ($payload !== []) {
            throw new ServerProviderException('errors.malformed');
        }
    }

    /** @param array<string, mixed> $payload */
    private function userId(array $payload): int
    {
        $id = is_array($payload['data'] ?? null) ? ($payload['data']['id'] ?? null) : null;
        if (! is_int($id) || $id < 1) {
            throw new ServerProviderException('errors.malformed');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: int, ownerId: int, name: string|null, suspended: bool, buildFailed: bool, built: string|null}
     */
    private function server(array $payload, ?string $expectedId = null): array
    {
        $server = $payload['data'] ?? null;
        if (! is_array($server)) {
            throw new ServerProviderException('errors.malformed');
        }

        $id = $server['id'] ?? null;
        $ownerId = $server['ownerId'] ?? null;
        $name = $server['name'] ?? null;
        $built = $server['built'] ?? null;
        $suspended = $server['suspended'] ?? null;
        $buildFailed = $server['buildFailed'] ?? null;
        if (! is_int($id) || $id < 1
            || ($expectedId !== null && (string) $id !== $expectedId)
            || ! is_int($ownerId)
            || ! is_bool($suspended)
            || ! is_bool($buildFailed)
            || ($built !== null && ! is_string($built))
            || ($name !== null && ! is_string($name))
        ) {
            throw new ServerProviderException('errors.malformed');
        }

        return [
            'id' => $id,
            'ownerId' => $ownerId,
            'name' => $name === '' ? null : $name,
            'suspended' => $suspended,
            'buildFailed' => $buildFailed,
            'built' => $built,
        ];
    }
}
