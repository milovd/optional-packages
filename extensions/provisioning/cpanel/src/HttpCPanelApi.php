<?php

declare(strict_types=1);

namespace Agovena\Extensions\CPanel;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;
use Agovena\Modules\Provisioning\Support\ServerProviderException;
use Illuminate\Validation\ValidationException;

final class HttpCPanelApi extends AbstractHttpServerApi implements CPanelApi
{
    /** @return array<string, mixed> */
    public function connectionTest(): array
    {
        return $this->call('version');
    }

    /** @return list<string> */
    public function creatablePackages(): array
    {
        $packages = $this->call('listpkgs', ['want' => 'creatable'])['data']['pkg'] ?? null;
        if (! is_array($packages) || ! array_is_list($packages)) {
            throw new ServerProviderException('errors.malformed');
        }

        $names = [];
        foreach ($packages as $package) {
            $name = is_array($package) ? ($package['name'] ?? null) : null;
            if (! is_string($name) || $name === '') {
                throw new ServerProviderException('errors.malformed');
            }
            $names[] = $name;
        }

        return $names;
    }

    /** @return array<string, mixed>|null */
    public function findServerByExternalId(string $externalId): ?array
    {
        $payload = $this->call('listaccts', ['searchtype' => 'user', 'searchmethod' => 'exact', 'search' => $externalId]);
        $accounts = $this->accounts($payload, $externalId);
        if (count($accounts) > 1) {
            throw new ServerProviderException('errors.malformed');
        }

        return $accounts[0] ?? null;
    }

    /** @return array<string, mixed> */
    public function getServer(string $externalId): array
    {
        $accounts = $this->accounts($this->call('accountsummary', ['user' => $externalId]), $externalId);
        if (count($accounts) !== 1) {
            throw new ServerProviderException('errors.malformed');
        }

        return $accounts[0];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createServer(array $payload): array
    {
        return $this->call('createacct', [
            'username' => (string) ($payload['username'] ?? ''),
            'plan' => (string) ($payload['plan'] ?? ''),
            'showpass' => 'n',
        ]);
    }

    public function suspend(string $externalId): void
    {
        $this->call('suspendacct', ['user' => $externalId, 'reason' => 'Suspended by Agovena']);
    }

    public function unsuspend(string $externalId): void
    {
        $this->call('unsuspendacct', ['user' => $externalId]);
    }

    public function terminate(string $externalId): void
    {
        $this->call('removeacct', ['username' => $externalId]);
    }

    /** @param array<string, mixed> $payload */
    public function changePlan(string $externalId, array $payload): void
    {
        $this->call('changepackage', ['user' => $externalId, 'pkg' => (string) ($payload['package'] ?? '')]);
    }

    /** @return array<string, string> */
    protected function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'whm '.$this->credential('api_username').':'.$this->credential('api_token'),
        ];
    }

    /**
     * Calls one WHM API 1 function. Success requires the documented
     * metadata.command echo and metadata.result 1; result 0 is a definitive rejection.
     *
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function call(string $function, array $params = []): array
    {
        if (app()->environment('demo')) {
            throw new ServerProviderException('errors.demo_disabled');
        }
        if ($this->credential('api_username') === '' || $this->credential('api_token') === '') {
            throw new ServerProviderException('errors.not_configured');
        }

        $endpoint = CPanelEndpoint::normalize((string) ($this->connection['api_url'] ?? ''));
        try {
            $payload = $this->withConnection(['api_url' => $endpoint] + $this->connection)
                ->request('GET', '/json-api/'.$function, ['api.version' => 1] + $params);
        } catch (ValidationException) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        $metadata = $payload['metadata'] ?? null;
        if (! is_array($metadata)
            || ($metadata['command'] ?? null) !== $function
            || ! in_array($metadata['result'] ?? null, [0, 1], true)) {
            throw new ServerProviderException('errors.malformed');
        }
        if ($metadata['result'] === 0) {
            throw new ServerProviderException('errors.rejected');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function accounts(array $payload, string $username): array
    {
        $accounts = $payload['data']['acct'] ?? null;
        if (! is_array($accounts) || ! array_is_list($accounts)) {
            throw new ServerProviderException('errors.malformed');
        }
        foreach ($accounts as $account) {
            if (! is_array($account) || ($account['user'] ?? null) !== $username) {
                throw new ServerProviderException('errors.malformed');
            }
        }

        return $accounts;
    }

    private function credential(string $key): string
    {
        return trim((string) ($this->connection[$key] ?? ''));
    }
}
