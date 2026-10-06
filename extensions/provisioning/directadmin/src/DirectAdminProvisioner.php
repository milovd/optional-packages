<?php

declare(strict_types=1);

namespace Agovena\Extensions\DirectAdmin;

use Agovena\Modules\Provisioning\Models\ServiceInstance;
use Agovena\Modules\Provisioning\Support\AbstractServerProvisioner;
use Agovena\Modules\Provisioning\Support\ServerProviderException;
use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Provisioning\ProvisionerPanelData;
use App\Agovena\Provisioning\ServiceInstanceInfo;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ownership is proven only by a directadmin_accounts claim row that Agovena inserted
 * before creating the user itself. Existing users are never adopted by lookup.
 */
final class DirectAdminProvisioner extends AbstractServerProvisioner
{
    public function __construct(
        ExtensionSettingsRepository $settings,
        private readonly DirectAdminApi $directAdmin,
        private readonly DirectAdminUsernameGenerator $usernames,
    ) {
        parent::__construct($settings, $directAdmin);
    }

    public function id(): string
    {
        return 'directadmin';
    }

    public function label(): string
    {
        return __('directadmin::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'directadmin::messages.settings.api_url', required: true, help: 'directadmin::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_username', 'directadmin::messages.settings.api_username', required: true, help: 'directadmin::messages.settings.api_username_help'),
            new ExtensionSettingDefinition('api_token', 'directadmin::messages.settings.api_token', secret: true, required: true, help: 'directadmin::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('ip', 'directadmin::messages.settings.ip', required: true, help: 'directadmin::messages.settings.ip_help'),
            new ExtensionSettingDefinition('verify_tls', 'directadmin::messages.settings.verify_tls', type: 'boolean', default: true, help: 'directadmin::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'directadmin::messages.settings.timeout', default: '20', help: 'directadmin::messages.settings.timeout_help'),
        ];
    }

    /** @return list<ExtensionSettingDefinition> */
    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('package', 'directadmin::messages.product.package', required: true, help: 'directadmin::messages.product.package_help'),
            new ExtensionSettingDefinition('domain', 'directadmin::messages.product.domain', required: true, help: 'directadmin::messages.product.domain_help'),
        ];
    }

    public function provision(ServiceInstanceInfo $instance): void
    {
        $this->locked($instance, function () use ($instance): void {
            $connection = $this->connection($instance);
            $endpoint = $this->endpoint($connection);
            $account = $this->account($instance->id);
            if ($account === null) {
                $this->claimAndCreate($instance, $connection, $endpoint);

                return;
            }

            $this->assertEndpoint($account, $endpoint);
            if (in_array($account->state, [DirectAdminAccount::STATE_ACTIVE, DirectAdminAccount::STATE_SUSPENDED], true)) {
                return;
            }
            if ($account->state === DirectAdminAccount::STATE_TERMINATED) {
                throw new ServerProviderException('errors.invalid_state');
            }

            $this->reconcile($instance, $account, $connection);
        });
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        $this->changeState($instance, DirectAdminAccount::STATE_ACTIVE, DirectAdminAccount::STATE_SUSPENDED,
            static fn (DirectAdminApi $api, string $username) => $api->suspend($username));
    }

    public function unsuspend(ServiceInstanceInfo $instance): void
    {
        $this->changeState($instance, DirectAdminAccount::STATE_SUSPENDED, DirectAdminAccount::STATE_ACTIVE,
            static fn (DirectAdminApi $api, string $username) => $api->unsuspend($username));
    }

    public function terminate(ServiceInstanceInfo $instance): void
    {
        $this->locked($instance, function () use ($instance): void {
            [$account, $api] = $this->ownedAccount($instance, [
                DirectAdminAccount::STATE_ACTIVE,
                DirectAdminAccount::STATE_SUSPENDED,
                DirectAdminAccount::STATE_FAILED,
                DirectAdminAccount::STATE_TERMINATED,
            ]);
            if ($account->state === DirectAdminAccount::STATE_TERMINATED) {
                return;
            }
            // A failed claim never produced a user of ours, so there is nothing to delete remotely.
            if ($account->state !== DirectAdminAccount::STATE_FAILED) {
                $api->terminate($account->remote_id);
            }

            $this->transition($account, [$account->state], ['state' => DirectAdminAccount::STATE_TERMINATED]);
        });
    }

    /** @param string|array<string, mixed> $plan */
    public function changePlan(ServiceInstanceInfo $instance, string|array $plan): void
    {
        $settings = is_array($plan) ? ($plan['provider_settings'] ?? null) : ['package' => $plan];
        $package = is_array($settings) ? trim((string) ($settings['package'] ?? '')) : '';

        $this->locked($instance, function () use ($instance, $package): void {
            if ($package === '') {
                throw new ServerProviderException('errors.invalid_product');
            }

            [$account, $api] = $this->ownedAccount($instance, [DirectAdminAccount::STATE_ACTIVE, DirectAdminAccount::STATE_SUSPENDED]);
            if (! in_array($package, $api->packages(), true)) {
                throw new ServerProviderException('errors.plan_unavailable');
            }

            $api->changePlan($account->remote_id, ['package' => $package]);
            if (($api->getServer($account->remote_id)['package'] ?? null) !== $package) {
                throw new ServerProviderException('errors.provider_failed');
            }

            $this->transition($account, [$account->state], ['plan' => $package]);
        });
    }

    public function syncStatus(ServiceInstanceInfo $instance): ServiceInstanceInfo
    {
        return $this->locked($instance, function () use ($instance): ServiceInstanceInfo {
            $account = $this->account($instance->id);
            if ($account === null || $account->state === DirectAdminAccount::STATE_FAILED) {
                return $this->absentInfo($instance);
            }

            $connection = $this->connection($instance);
            $this->assertEndpoint($account, $this->endpoint($connection));
            if ($account->state === DirectAdminAccount::STATE_TERMINATED) {
                return $this->info($instance, $account);
            }

            $api = $this->api($connection);
            if (in_array($account->state, [DirectAdminAccount::STATE_CREATING, DirectAdminAccount::STATE_UNKNOWN], true)) {
                $remote = $api->findServerByExternalId($account->remote_id);
                if ($remote === null) {
                    return $this->absentInfo($instance);
                }
                if (! $this->createdByUs($remote, $account, $connection)) {
                    throw new ServerProviderException('errors.not_owned');
                }
            } else {
                $remote = $api->getServer($account->remote_id);
                if (strtolower((string) ($remote['domain'] ?? '')) !== $account->remote_name) {
                    throw new ServerProviderException('errors.not_owned');
                }
            }

            $state = match ($remote['suspended'] ?? null) {
                'yes' => DirectAdminAccount::STATE_SUSPENDED,
                'no' => DirectAdminAccount::STATE_ACTIVE,
                default => throw new ServerProviderException('errors.malformed'),
            };
            if ($account->state !== $state) {
                $account = $this->transition($account, [$account->state], ['state' => $state]);
            }

            return $this->info($instance, $account);
        });
    }

    public function panel(ServiceInstanceInfo $instance): ?ProvisionerPanelData
    {
        $account = $this->account($instance->id);
        if ($account === null || ! in_array($account->state, [DirectAdminAccount::STATE_ACTIVE, DirectAdminAccount::STATE_SUSPENDED], true)) {
            return null;
        }

        return new ProvisionerPanelData($this->message('panel.title'), [
            ['label' => $this->message('panel.status'), 'value' => $this->message('status.'.$account->state)],
            ['label' => $this->message('panel.login_url'), 'value' => $account->endpoint],
            ['label' => $this->message('panel.username'), 'value' => $account->remote_id],
            ['label' => $this->message('panel.domain'), 'value' => (string) $account->remote_name],
        ]);
    }

    /** @return list<string> */
    protected function requiredConnectionKeys(): array
    {
        return ['api_url', 'api_username', 'api_token'];
    }

    /** @param array<string, mixed> $connection */
    private function claimAndCreate(ServiceInstanceInfo $instance, array $connection, string $endpoint): void
    {
        $payload = $this->createPayload($instance, $connection);

        try {
            $account = DirectAdminAccount::query()->create([
                'service_instance_id' => $instance->id,
                'endpoint' => $endpoint,
                'remote_id' => $this->usernames->generate(),
                'remote_name' => $payload['domain'],
                'plan' => $payload['package'],
                'state' => DirectAdminAccount::STATE_CREATING,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new ServerProviderException('errors.identifier_claimed');
        }

        $this->createRemote($account, $payload, $connection);
    }

    /**
     * Reads the claimed username only. A user that exists but was not created by this claim is refused.
     *
     * @param  array<string, mixed>  $connection
     */
    private function reconcile(ServiceInstanceInfo $instance, DirectAdminAccount $account, array $connection): void
    {
        try {
            $remote = $this->api($connection)->findServerByExternalId($account->remote_id);
        } catch (ServerProviderException $exception) {
            $this->markUnknown($account);

            throw $exception;
        }

        if ($remote === null) {
            $payload = ['domain' => (string) $account->remote_name] + $this->createPayload($instance, $connection);
            $account = $this->transition($account, [$account->state], [
                'state' => DirectAdminAccount::STATE_CREATING,
                'plan' => $payload['package'],
            ]);
            $this->createRemote($account, $payload, $connection);

            return;
        }

        if ($account->state !== DirectAdminAccount::STATE_FAILED && $this->createdByUs($remote, $account, $connection)) {
            $this->transition($account, [$account->state], ['state' => DirectAdminAccount::STATE_ACTIVE]);

            return;
        }

        $this->markUnknown($account);

        throw new ServerProviderException('errors.not_owned');
    }

    /**
     * The HTTP call runs outside any database transaction; the outcome is written with a revision check.
     *
     * @param  array{domain: string, package: string, ip: string, email: string}  $payload
     * @param  array<string, mixed>  $connection
     */
    private function createRemote(DirectAdminAccount $account, array $payload, array $connection): void
    {
        try {
            $this->api($connection)->createServer($payload + [
                'username' => $account->remote_id,
                'password' => Str::password(24),
            ]);
        } catch (ServerProviderException $exception) {
            $state = $exception->errorKey === 'errors.provider_rejected'
                ? DirectAdminAccount::STATE_FAILED
                : DirectAdminAccount::STATE_UNKNOWN;
            $this->transition($account, [DirectAdminAccount::STATE_CREATING], ['state' => $state]);

            throw $exception;
        }

        $this->transition($account, [DirectAdminAccount::STATE_CREATING], ['state' => DirectAdminAccount::STATE_ACTIVE]);
    }

    private function markUnknown(DirectAdminAccount $account): void
    {
        if ($account->state === DirectAdminAccount::STATE_CREATING) {
            $this->transition($account, [DirectAdminAccount::STATE_CREATING], ['state' => DirectAdminAccount::STATE_UNKNOWN]);
        }
    }

    /** @param Closure(DirectAdminApi, string): void $remote */
    private function changeState(ServiceInstanceInfo $instance, string $from, string $to, Closure $remote): void
    {
        $this->locked($instance, function () use ($instance, $from, $to, $remote): void {
            [$account, $api] = $this->ownedAccount($instance, [$from, $to]);
            if ($account->state === $to) {
                return;
            }

            $remote($api, $account->remote_id);
            $this->transition($account, [$from], ['state' => $to]);
        });
    }

    /**
     * @param  list<string>  $states
     * @return array{0: DirectAdminAccount, 1: DirectAdminApi}
     */
    private function ownedAccount(ServiceInstanceInfo $instance, array $states): array
    {
        $account = $this->account($instance->id) ?? throw new ServerProviderException('errors.not_provisioned');
        $connection = $this->connection($instance);
        $this->assertEndpoint($account, $this->endpoint($connection));
        if (! in_array($account->state, $states, true)) {
            throw new ServerProviderException('errors.invalid_state');
        }

        return [$account, $this->api($connection)];
    }

    /**
     * Compare-and-swap on id, revision and expected state; exactly one row must change.
     *
     * @param  list<string>  $from
     * @param  array<string, string>  $changes
     */
    private function transition(DirectAdminAccount $account, array $from, array $changes): DirectAdminAccount
    {
        $updated = DirectAdminAccount::query()
            ->whereKey($account->id)
            ->where('revision', $account->revision)
            ->whereIn('state', $from)
            ->update($changes + ['revision' => $account->revision + 1]);
        if ($updated !== 1) {
            throw new ServerProviderException('errors.stale_claim');
        }

        return $account->refresh();
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  array<string, mixed>  $connection
     */
    private function createdByUs(array $remote, DirectAdminAccount $account, array $connection): bool
    {
        $apiUsername = trim((string) ($connection['api_username'] ?? ''));
        $creator = str_contains($apiUsername, '|') ? substr($apiUsername, strrpos($apiUsername, '|') + 1) : $apiUsername;

        return ($remote['username'] ?? null) === $account->remote_id
            && ($remote['usertype'] ?? null) === 'user'
            && ($remote['creator'] ?? null) === $creator
            && strtolower((string) ($remote['domain'] ?? '')) === $account->remote_name;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array{domain: string, package: string, ip: string, email: string}
     */
    private function createPayload(ServiceInstanceInfo $instance, array $connection): array
    {
        foreach ($this->requiredConnectionKeys() as $key) {
            if (trim((string) ($connection[$key] ?? '')) === '') {
                throw new ServerProviderException('errors.not_configured');
            }
        }

        $settings = $instance->providerSettings ?? [];
        $domain = strtolower(trim((string) ($settings['domain'] ?? '')));
        $package = trim((string) ($settings['package'] ?? ''));
        $ip = trim((string) ($connection['ip'] ?? ''));
        $email = (string) ServiceInstance::query()->whereKey($instance->id)->value('customer_email');

        if ($package === ''
            || ! str_contains($domain, '.')
            || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || filter_var($ip, FILTER_VALIDATE_IP) === false
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new ServerProviderException('errors.invalid_product');
        }

        return ['domain' => $domain, 'package' => $package, 'ip' => $ip, 'email' => $email];
    }

    private function info(ServiceInstanceInfo $instance, DirectAdminAccount $account): ServiceInstanceInfo
    {
        $meta = $instance->meta;
        unset($meta['provider_reconciliation']);
        $meta['provider_mapping'] = [
            'provider_id' => $account->remote_id,
            'username' => $account->remote_id,
            'domain' => (string) $account->remote_name,
        ];

        return new ServiceInstanceInfo(
            id: $instance->id,
            label: $instance->label,
            status: $account->state,
            providerKey: $this->id(),
            externalRef: $account->remote_id,
            meta: $meta,
        );
    }

    private function absentInfo(ServiceInstanceInfo $instance): ServiceInstanceInfo
    {
        $meta = $instance->meta;
        unset($meta['provider_mapping']);
        $meta['provider_reconciliation'] = 'absent';

        return new ServiceInstanceInfo(
            id: $instance->id,
            label: $instance->label,
            status: $instance->status,
            providerKey: $this->id(),
            externalRef: null,
            meta: $meta,
        );
    }

    private function account(int $serviceInstanceId): ?DirectAdminAccount
    {
        return DirectAdminAccount::query()->where('service_instance_id', $serviceInstanceId)->first();
    }

    private function assertEndpoint(DirectAdminAccount $account, string $endpoint): void
    {
        if ($account->endpoint !== $endpoint) {
            throw new ServerProviderException('errors.endpoint_changed');
        }
    }

    /** @param array<string, mixed> $connection */
    private function endpoint(array $connection): string
    {
        return DirectAdminEndpoint::normalize((string) ($connection['api_url'] ?? ''));
    }

    /** @param array<string, mixed> $connection */
    private function api(array $connection): DirectAdminApi
    {
        return $this->directAdmin->withConnection($connection);
    }

    /** @return array<string, mixed> */
    private function connection(ServiceInstanceInfo $instance): array
    {
        $settings = $instance->serverSettings ?? [];
        if ($settings !== []) {
            return $settings;
        }
        if (($instance->meta['server_settings_required'] ?? false) === true) {
            throw new ServerProviderException('errors.not_configured');
        }

        $repository = [];
        foreach ($this->serverSettings() as $definition) {
            $repository[$definition->key] = $this->settings->get($this->id(), $definition->key, $definition->default);
        }

        return $repository;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(ServiceInstanceInfo $instance, Closure $callback): mixed
    {
        $lock = Cache::lock('directadmin:account:'.$instance->id, 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['instance' => $this->message('errors.busy')]);
        }

        try {
            return $callback();
        } catch (ServerProviderException $exception) {
            throw ValidationException::withMessages(['instance' => $this->message($exception->errorKey)]);
        } finally {
            $lock->release();
        }
    }
}
