<?php

declare(strict_types=1);

namespace Agovena\Extensions\DirectAdmin;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;
use Agovena\Modules\Provisioning\Support\ServerProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * DirectAdmin legacy API client. Responses use the documented url-encoded format:
 * commands answer error=0|1&text=...&details=..., listings answer list[]=...
 */
final class HttpDirectAdminApi extends AbstractHttpServerApi implements DirectAdminApi
{
    /** @return array<string, mixed> */
    public function connectionTest(): array
    {
        return ['packages' => $this->packages()];
    }

    /** @return list<string> */
    public function packages(): array
    {
        return $this->listValues($this->call('GET', 'CMD_API_PACKAGES_USER'));
    }

    /** @return array<string, mixed>|null */
    public function findServerByExternalId(string $externalId): ?array
    {
        $owned = $this->listValues($this->call('GET', 'CMD_API_SHOW_USERS'));

        return in_array($externalId, $owned, true) ? $this->getServer($externalId) : null;
    }

    /** @return array<string, mixed> */
    public function getServer(string $externalId): array
    {
        $config = $this->call('GET', 'CMD_API_SHOW_USER_CONFIG', ['user' => $externalId]);
        if (($config['username'] ?? null) !== $externalId) {
            throw new ServerProviderException('errors.malformed');
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $payload  username, email, password, domain, package, ip
     * @return array<string, mixed>
     */
    public function createServer(array $payload): array
    {
        $password = (string) ($payload['password'] ?? '');

        $this->command('CMD_API_ACCOUNT_USER', [
            'action' => 'create',
            'add' => 'Submit',
            'username' => (string) ($payload['username'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'passwd' => $password,
            'passwd2' => $password,
            'domain' => (string) ($payload['domain'] ?? ''),
            'package' => (string) ($payload['package'] ?? ''),
            'ip' => (string) ($payload['ip'] ?? ''),
            'notify' => 'yes',
        ]);

        return [];
    }

    public function suspend(string $externalId): void
    {
        $this->command('CMD_API_SELECT_USERS', ['dosuspend' => 'yes', 'select0' => $externalId]);
    }

    public function unsuspend(string $externalId): void
    {
        $this->command('CMD_API_SELECT_USERS', ['dounsuspend' => 'yes', 'select0' => $externalId]);
    }

    public function terminate(string $externalId): void
    {
        $this->command('CMD_API_SELECT_USERS', ['confirmed' => 'Confirm', 'delete' => 'yes', 'select0' => $externalId]);
    }

    /** @param array<string, mixed> $payload */
    public function changePlan(string $externalId, array $payload): void
    {
        $this->command('CMD_API_SELECT_USERS', [
            'dopackage' => 'yes',
            'package' => (string) ($payload['package'] ?? ''),
            'select0' => $externalId,
        ]);
    }

    /** @param array<string, string> $form */
    private function command(string $command, array $form): void
    {
        if (($this->call('POST', $command, $form)['error'] ?? null) !== '0') {
            throw new ServerProviderException('errors.malformed');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function listValues(array $data): array
    {
        if ($data === []) {
            return [];
        }

        $list = $data['list'] ?? null;
        if (! is_array($list) || ! array_is_list($list)) {
            throw new ServerProviderException('errors.malformed');
        }

        $values = [];
        foreach ($list as $value) {
            if (! is_string($value)) {
                throw new ServerProviderException('errors.malformed');
            }
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function call(string $method, string $command, array $params = []): array
    {
        if (app()->environment('demo')) {
            throw new ServerProviderException('errors.provider_failed');
        }

        $endpoint = DirectAdminEndpoint::normalize((string) ($this->connection['api_url'] ?? ''));
        $username = trim((string) ($this->connection['api_username'] ?? ''));
        $token = (string) ($this->connection['api_token'] ?? '');
        if ($username === '' || trim($token) === '') {
            throw new ServerProviderException('errors.not_configured');
        }

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

        $pending = Http::timeout(max(1, (int) ($this->connection['timeout'] ?? 20)))
            ->withHeaders(['Authorization' => 'Basic '.base64_encode($username.':'.$token)])
            ->withOptions($options)
            ->withoutRedirecting();
        $url = $endpoint.'/'.$command;

        try {
            $response = $method === 'GET'
                ? $pending->get($url, $params)
                : $pending->asForm()->post($url, $params);
        } catch (ConnectionException) {
            throw new ServerProviderException('errors.timeout');
        } catch (Throwable) {
            throw new ServerProviderException('errors.unreachable');
        }

        return $this->decodeBody($response);
    }

    private function pinnedHost(string $endpoint): string
    {
        $parts = (array) parse_url($endpoint);
        $address = $this->urlValidator->resolvePublicIp($endpoint);
        $resolved = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.$address.']' : $address;

        return ($parts['host'] ?? '').':'.($parts['port'] ?? '').':'.$resolved;
    }

    /** @return array<string, mixed> */
    private function decodeBody(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw new ServerProviderException('errors.unauthorized', $response->status());
        }
        if ($response->status() !== 200) {
            throw new ServerProviderException('errors.provider_failed', $response->status());
        }

        $body = trim($response->body());
        if (str_contains($body, '<')) {
            throw new ServerProviderException('errors.malformed');
        }

        parse_str(html_entity_decode($body, ENT_QUOTES | ENT_HTML5), $parsed);
        if (($parsed['error'] ?? null) === '1') {
            throw new ServerProviderException('errors.provider_rejected');
        }

        $data = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }
}
