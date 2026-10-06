<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtualizor;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;
use Agovena\Modules\Provisioning\Support\ServerProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Admin API authenticates with query parameters, so request URLs carry the
 * credentials: they are never placed in exceptions, messages or logs.
 */
final class HttpVirtualizorApi extends AbstractHttpServerApi implements VirtualizorApi
{
    /** @return array<string, mixed> */
    public function connectionTest(): array
    {
        $this->plans();

        return [];
    }

    public function plans(): array
    {
        $plans = $this->listed($this->call('plans', form: ['reslen' => '1000']), 'plans');

        $result = [];
        foreach ($plans as $key => $plan) {
            if (! is_array($plan)) {
                throw new ServerProviderException('errors.malformed');
            }
            $id = $this->number($plan['plid'] ?? null);
            $virt = $plan['virt'] ?? null;
            $enabled = $plan['is_enabled'] ?? null;
            if ($id < 1 || (string) $key !== (string) $id || ! is_string($virt) || $virt === '' || ! in_array($enabled, ['0', '1', 0, 1], true)) {
                throw new ServerProviderException('errors.malformed');
            }

            $result[$id] = [
                'virt' => $virt,
                'enabled' => (int) $enabled === 1,
                'ips' => $this->number($plan['ips'] ?? null),
                'space' => $this->number($plan['space'] ?? null),
                'ram' => $this->number($plan['ram'] ?? null),
                'bandwidth' => $this->number($plan['bandwidth'] ?? null),
                'cores' => $this->number($plan['cores'] ?? null),
            ];
        }

        return $result;
    }

    public function userIdsByEmail(string $email): array
    {
        $ids = [];
        foreach ($this->listed($this->call('users', form: ['email' => $email]), 'users') as $user) {
            $address = is_array($user) ? ($user['email'] ?? null) : null;
            if (! is_string($address)) {
                throw new ServerProviderException('errors.malformed');
            }
            if (strcasecmp($address, $email) === 0) {
                $ids[] = $this->number($user['uid'] ?? null);
            }
        }

        return $ids;
    }

    public function createVps(array $form): array
    {
        $payload = $this->call('addvs', form: $form);
        $info = $payload['vs_info'] ?? null;
        if (($payload['error'] ?? null) !== [] || ! is_array($info)) {
            throw new ServerProviderException('errors.malformed');
        }

        $vpsId = $this->number($info['vpsid'] ?? null);
        $userId = $this->number($info['uid'] ?? null);
        if ($vpsId < 1 || $userId < 1) {
            throw new ServerProviderException('errors.malformed');
        }

        return ['vpsid' => (string) $vpsId, 'uid' => $userId, 'hostname' => $this->hostname($info)];
    }

    public function vps(string $vpsId): array
    {
        $this->assertVpsId($vpsId);
        $vps = $this->listed($this->call('vs', ['vpsid' => $vpsId, 'search' => 'Search']), 'vs')[$vpsId] ?? null;
        if ($vps === null) {
            throw new ServerProviderException('errors.not_found');
        }

        $virt = is_array($vps) ? ($vps['virt'] ?? null) : null;
        $suspended = is_array($vps) ? ($vps['suspended'] ?? null) : null;
        if (! is_array($vps)
            || (string) $this->number($vps['vpsid'] ?? null) !== $vpsId
            || ! is_string($virt)
            || ! in_array($suspended, ['0', '1', 0, 1], true)
        ) {
            throw new ServerProviderException('errors.malformed');
        }

        return [
            'vpsid' => $vpsId,
            'uid' => $this->number($vps['uid'] ?? null),
            'plid' => $this->number($vps['plid'] ?? null),
            'virt' => $virt,
            'hostname' => $this->hostname($vps),
            'suspended' => (int) $suspended === 1,
        ];
    }

    public function suspendVps(string $vpsId): void
    {
        $this->assertVpsId($vpsId);
        $this->assertDone($this->call('vs', ['suspend' => $vpsId])['done'] ?? null);
    }

    public function unsuspendVps(string $vpsId): void
    {
        $this->assertVpsId($vpsId);
        $this->assertDone($this->call('vs', ['unsuspend' => $vpsId])['done'] ?? null);
    }

    public function deleteVps(string $vpsId): void
    {
        $this->assertVpsId($vpsId);
        $this->assertDone($this->call('vs', ['delete' => $vpsId])['done'] ?? null);
    }

    public function applyPlan(string $vpsId, int $planId): void
    {
        $this->assertVpsId($vpsId);
        if ($planId < 1) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        $done = $this->call('managevps', ['vpsid' => $vpsId], [
            'vpsid' => $vpsId,
            'plid' => (string) $planId,
            'apply_plan' => '1',
            'editvps' => '1',
        ])['done'] ?? null;
        $this->assertDone(is_array($done) ? ($done['done'] ?? null) : null);
    }

    /**
     * Sends one Admin API call; documented POST parameters travel as form data.
     *
     * @param  array<string, string>  $query
     * @param  array<string, string>|null  $form
     * @return array<string, mixed>
     */
    private function call(string $act, array $query = [], ?array $form = null): array
    {
        if (app()->environment('demo')) {
            throw new ServerProviderException('errors.demo_disabled');
        }

        $key = trim((string) ($this->connection['api_token'] ?? ''));
        $pass = trim((string) ($this->connection['api_secret'] ?? ''));
        if ($key === '' || $pass === '') {
            throw new ServerProviderException('errors.not_configured');
        }

        $endpoint = VirtualizorEndpoint::normalize((string) ($this->connection['api_url'] ?? ''));
        $verifyTls = filter_var($this->connection['verify_tls'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verifyTls === null) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        $options = ['verify' => $verifyTls];
        try {
            $this->urlValidator->validate($endpoint);
            $this->urlValidator->assertTlsVerification($verifyTls);
            if (! in_array(config('app.env'), ['local', 'testing'], true)) {
                $options['curl'] = [CURLOPT_RESOLVE => [$this->pinnedHost($endpoint)]];
            }
        } catch (ValidationException) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        $url = $endpoint.'/index.php?'.http_build_query(['act' => $act] + $query + [
            'api' => 'json',
            'adminapikey' => $key,
            'adminapipass' => $pass,
        ]);
        $pending = Http::timeout(max(1, (int) ($this->connection['timeout'] ?? 20)))
            ->acceptJson()
            ->withOptions($options)
            ->withoutRedirecting();

        try {
            $response = $form === null ? $pending->get($url) : $pending->asForm()->post($url, $form);
        } catch (ConnectionException) {
            throw new ServerProviderException('errors.timeout');
        } catch (Throwable) {
            throw new ServerProviderException('errors.unreachable');
        }

        return $this->decode($response);
    }

    private function pinnedHost(string $endpoint): string
    {
        $parts = (array) parse_url($endpoint);
        $address = $this->urlValidator->resolvePublicIp($endpoint);
        $resolved = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.$address.']' : $address;

        return ($parts['host'] ?? '').':'.($parts['port'] ?? '').':'.$resolved;
    }

    /**
     * Virtualizor answers failures with HTTP 200 and a non-empty error list.
     *
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw new ServerProviderException('errors.unauthorized', $response->status());
        }
        if ($response->status() !== 200) {
            throw new ServerProviderException('errors.provider_failed', $response->status());
        }

        $json = $response->json();
        if (! is_array($json) || ($json !== [] && array_is_list($json))) {
            throw new ServerProviderException('errors.malformed');
        }
        if (($json['error'] ?? []) !== []) {
            throw new ServerProviderException('errors.provider_rejected');
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function listed(array $payload, string $key): array
    {
        if (! array_key_exists($key, $payload) || ! (is_array($payload[$key]) || $payload[$key] === null)) {
            throw new ServerProviderException('errors.malformed');
        }

        return $payload[$key] ?? [];
    }

    /** Virtualizor encodes ids and resource sizes as digit strings. */
    private function number(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (! is_string($value) || ! ctype_digit($value)) {
            throw new ServerProviderException('errors.malformed');
        }

        return (int) $value;
    }

    /** @param array<array-key, mixed> $vps */
    private function hostname(array $vps): ?string
    {
        $hostname = $vps['hostname'] ?? null;
        if ($hostname !== null && ! is_string($hostname)) {
            throw new ServerProviderException('errors.malformed');
        }

        return $hostname === '' ? null : $hostname;
    }

    private function assertDone(mixed $done): void
    {
        if (! in_array($done, [true, 1, '1'], true)) {
            throw new ServerProviderException('errors.malformed');
        }
    }

    private function assertVpsId(string $vpsId): void
    {
        if (! ctype_digit($vpsId) || (int) $vpsId < 1) {
            throw new ServerProviderException('errors.invalid_mapping');
        }
    }
}
