<?php

declare(strict_types=1);

namespace Agovena\Extensions\Convoy;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;
use Agovena\Modules\Provisioning\Support\ServerProviderException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type Limits from ConvoyApi
 * @phpstan-import-type Server from ConvoyApi
 */
final class HttpConvoyApi extends AbstractHttpServerApi implements ConvoyApi
{
    /** Server status values of Convoy v4.6.1 (App\Enums\Server\Status); null means ready. */
    private const STATUSES = ['installing', 'install_failed', 'suspended', 'restoring_backup', 'restoring_snapshot', 'deleting', 'deletion_failed'];

    /** @return array<string, mixed> */
    public function connectionTest(): array
    {
        $servers = $this->call('GET', '/servers', ['per_page' => 1])['data'] ?? null;
        if (! is_array($servers) || ! array_is_list($servers)) {
            throw new ServerProviderException('errors.malformed');
        }

        return [];
    }

    public function createUser(string $name, string $email, string $password): int
    {
        $user = $this->call('POST', '/users', body: [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'root_admin' => false,
        ])['data'] ?? null;

        $id = is_array($user) ? ($user['id'] ?? null) : null;
        if (! is_int($id) || $id < 1 || ($user['root_admin'] ?? null) !== false) {
            throw new ServerProviderException('errors.malformed');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Server
     */
    public function createServer(array $payload): array
    {
        return $this->server($this->call('POST', '/servers', body: $payload));
    }

    /** @return Server */
    public function getServer(string $externalId): array
    {
        return $this->server($this->call('GET', $this->serverPath($externalId)), $externalId);
    }

    public function suspend(string $externalId): void
    {
        $this->expectEmpty($this->call('POST', $this->serverPath($externalId).'/settings/suspend'));
    }

    public function unsuspend(string $externalId): void
    {
        $this->expectEmpty($this->call('POST', $this->serverPath($externalId).'/settings/unsuspend'));
    }

    public function terminate(string $externalId): void
    {
        $this->expectEmpty($this->call('DELETE', $this->serverPath($externalId)));
    }

    /**
     * @param  Limits  $limits
     * @param  list<int>  $addressIds
     */
    public function updateBuild(string $uuid, array $limits, array $addressIds): void
    {
        $this->server($this->call('PATCH', $this->serverPath($uuid).'/settings/build', body: [
            'cpu' => $limits['cpu'],
            'memory' => $limits['memory'],
            'disk' => $limits['disk'],
            'snapshot_limit' => $limits['snapshots'],
            'backup_limit' => $limits['backups'],
            'bandwidth_limit' => $limits['bandwidth'],
            'address_ids' => $addressIds,
        ]), $uuid);
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

        $endpoint = ConvoyEndpoint::normalize((string) ($this->connection['api_url'] ?? ''));
        try {
            return $this->withConnection(['api_url' => $endpoint] + $this->connection)
                ->request($method, '/api/application'.$path, $query, $body);
        } catch (ValidationException) {
            throw new ServerProviderException('errors.invalid_mapping');
        }
    }

    private function serverPath(string $uuid): string
    {
        if (! Str::isUuid($uuid)) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        return '/servers/'.$uuid;
    }

    /**
     * Documented action endpoints answer 204 with an empty body; anything else fails closed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function expectEmpty(array $payload): void
    {
        if ($payload !== []) {
            throw new ServerProviderException('errors.malformed');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Server
     */
    private function server(array $payload, ?string $expectedUuid = null): array
    {
        $server = $payload['data'] ?? null;
        $limits = is_array($server) ? ($server['limits'] ?? null) : null;
        if (! is_array($server) || ! is_array($limits)) {
            throw new ServerProviderException('errors.malformed');
        }

        $uuid = $server['uuid'] ?? null;
        $userId = $server['user_id'] ?? null;
        $hostname = $server['hostname'] ?? null;
        $status = $server['status'] ?? null;
        if (! is_string($uuid) || ! Str::isUuid($uuid)
            || ($expectedUuid !== null && $uuid !== $expectedUuid)
            || ! is_int($userId)
            || ($hostname !== null && ! is_string($hostname))
            || ($status !== null && ! in_array($status, self::STATUSES, true))
        ) {
            throw new ServerProviderException('errors.malformed');
        }

        return [
            'uuid' => $uuid,
            'userId' => $userId,
            'hostname' => $hostname === '' ? null : $hostname,
            'status' => $status,
            'limits' => $this->limits($limits),
            'addressIds' => $this->addressIds($limits['addresses'] ?? null),
        ];
    }

    /**
     * @param  array<mixed>  $limits
     * @return Limits
     */
    private function limits(array $limits): array
    {
        $values = [];
        foreach (['cpu', 'memory', 'disk', 'snapshots', 'backups', 'bandwidth'] as $key) {
            $value = $limits[$key] ?? null;
            if (! is_int($value) && ! ($value === null && $key === 'bandwidth')) {
                throw new ServerProviderException('errors.malformed');
            }
            $values[$key] = $value;
        }

        /** @var Limits $values */
        return $values;
    }

    /** @return list<int> */
    private function addressIds(mixed $addresses): array
    {
        if (! is_array($addresses)) {
            throw new ServerProviderException('errors.malformed');
        }

        $ids = [];
        foreach (['ipv4', 'ipv6'] as $type) {
            $list = $addresses[$type] ?? null;
            if (! is_array($list) || ! array_is_list($list)) {
                throw new ServerProviderException('errors.malformed');
            }
            foreach ($list as $address) {
                $id = is_array($address) ? ($address['id'] ?? null) : null;
                if (! is_int($id) || $id < 1) {
                    throw new ServerProviderException('errors.malformed');
                }
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
