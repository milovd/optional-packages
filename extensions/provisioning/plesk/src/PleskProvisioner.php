<?php

declare(strict_types=1);

namespace Agovena\Extensions\Plesk;

use Agovena\Modules\Provisioning\Models\ServiceInstance;
use Agovena\Modules\Provisioning\Support\AbstractServerProvisioner;
use Agovena\Modules\Provisioning\Support\ServerProviderException;
use App\Agovena\Extensions\ExtensionSettingDefinition;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Provisioning\ProvisionerPanelData;
use App\Agovena\Provisioning\ServiceInstanceInfo;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ownership of a Plesk customer and subscription is proven only by a plesk_accounts claim
 * row that Agovena wrote before its own create calls. Lookups never adopt foreign objects.
 */
final class PleskProvisioner extends AbstractServerProvisioner
{
    /** Documented objectStatus values: 0 active; 16, 32 and 64 disabled; 256 expired. */
    private const STATUS_ACTIVE = 0;

    private const STATUS_DISABLED_BY_ADMIN = 16;

    private const STATUSES_SUSPENDED = [16, 32, 64, 256];

    /** Documented customer password maximum. */
    private const PASSWORD_LENGTH = 14;

    private const PENDING_PREFIX = 'pending:';

    private const PENDING_STATES = [PleskAccount::STATE_CREATING, PleskAccount::STATE_UNKNOWN, PleskAccount::STATE_FAILED];

    /** Errors proving that Plesk did not create anything for the request. */
    private const DEFINITIVE_ERRORS = ['errors.rejected', 'errors.unauthorized', 'errors.demo_disabled', 'errors.not_configured', 'errors.invalid_mapping'];

    public function __construct(ExtensionSettingsRepository $settings, private readonly PleskApi $plesk)
    {
        parent::__construct($settings, $plesk);
    }

    public function id(): string
    {
        return 'plesk';
    }

    public function label(): string
    {
        return __('plesk::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'plesk::messages.settings.api_url', required: true, help: 'plesk::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'plesk::messages.settings.api_token', secret: true, required: true, help: 'plesk::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('ip_address', 'plesk::messages.settings.ip_address', required: true, help: 'plesk::messages.settings.ip_address_help'),
            new ExtensionSettingDefinition('verify_tls', 'plesk::messages.settings.verify_tls', type: 'boolean', default: true, help: 'plesk::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'plesk::messages.settings.timeout', default: '20', help: 'plesk::messages.settings.timeout_help'),
        ];
    }

    /** @return list<ExtensionSettingDefinition> */
    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('service_plan', 'plesk::messages.product.service_plan', required: true, help: 'plesk::messages.product.service_plan_help'),
            new ExtensionSettingDefinition('domain', 'plesk::messages.product.domain', required: true, help: 'plesk::messages.product.domain_help'),
        ];
    }

    public function provision(ServiceInstanceInfo $instance): void
    {
        $connection = $this->connection($instance);
        $order = $this->order($instance, $connection);
        $endpoint = $this->endpoint($connection);

        try {
            Cache::lock('agovena:plesk:provision:'.$instance->id, 120)
                ->block(10, fn () => $this->provisionLocked($instance, $connection, $endpoint, $order));
        } catch (LockTimeoutException) {
            throw $this->refusal('errors.conflict');
        }
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        $this->switchSuspension($instance, PleskAccount::STATE_ACTIVE, PleskAccount::STATE_SUSPENDED, self::STATUS_DISABLED_BY_ADMIN);
    }

    public function unsuspend(ServiceInstanceInfo $instance): void
    {
        $this->switchSuspension($instance, PleskAccount::STATE_SUSPENDED, PleskAccount::STATE_ACTIVE, self::STATUS_ACTIVE);
    }

    public function terminate(ServiceInstanceInfo $instance): void
    {
        $claim = $this->claim($instance->id);
        if ($claim === null) {
            $this->assertNoUnclaimedMapping($instance);

            return;
        }

        $connection = $this->connection($instance);
        $this->assertEndpoint($claim, $this->endpoint($connection));
        $api = $this->plesk->withConnection($connection);
        if ($claim->state !== PleskAccount::STATE_TERMINATED) {
            // A failed create never produced a subscription, so only the customer may remain.
            if ($claim->state !== PleskAccount::STATE_FAILED) {
                $this->assertState($claim, [PleskAccount::STATE_ACTIVE, PleskAccount::STATE_SUSPENDED]);
                $subscriptionId = (int) $claim->remote_id;
                $this->remote(fn () => $api->deleteSubscription($subscriptionId));
            }
            $this->transition($claim, [$claim->state], ['state' => PleskAccount::STATE_TERMINATED]);
        }

        $this->removeCustomer($api, $claim);
    }

    /** @param string|array<string, mixed> $plan */
    public function changePlan(ServiceInstanceInfo $instance, string|array $plan): void
    {
        $providerSettings = is_array($plan) ? ($plan['provider_settings'] ?? null) : null;
        $target = trim((string) (is_array($providerSettings) ? ($providerSettings['service_plan'] ?? '') : ''));
        if ($target === '') {
            throw $this->refusal('errors.invalid_mapping');
        }
        $serverSettings = is_array($plan) ? ($plan['server_settings'] ?? null) : null;
        $connection = is_array($serverSettings) && $serverSettings !== [] ? $serverSettings : $this->connection($instance);

        $claim = $this->ownedClaim($instance, $connection);
        $this->assertState($claim, [PleskAccount::STATE_ACTIVE, PleskAccount::STATE_SUSPENDED]);
        if ($claim->plan === $target) {
            return;
        }

        $api = $this->plesk->withConnection($connection);
        $subscriptionId = (int) $claim->remote_id;
        $this->remote(function () use ($api, $subscriptionId, $target): void {
            $guid = $api->servicePlans()[$target] ?? throw new ServerProviderException('errors.package_unavailable');
            $api->switchPlan($subscriptionId, $guid);
            if (! in_array($guid, $api->subscription($subscriptionId)['plan_guids'], true)) {
                throw new ServerProviderException('errors.unverified');
            }
        });

        $this->transition($claim, [$claim->state], ['plan' => $target]);
    }

    public function syncStatus(ServiceInstanceInfo $instance): ServiceInstanceInfo
    {
        $claim = $this->claim($instance->id);
        if ($claim === null) {
            $this->assertNoUnclaimedMapping($instance);

            return $this->info($instance, $instance->status, $instance->externalRef, ['provider_reconciliation' => 'absent']);
        }

        $connection = $this->connection($instance);
        $this->assertEndpoint($claim, $this->endpoint($connection));
        if ($claim->state === PleskAccount::STATE_TERMINATED) {
            return $this->info($instance, 'terminated', $claim->remote_id, []);
        }
        if ($claim->state === PleskAccount::STATE_FAILED) {
            return $this->info($instance, $instance->status, $instance->externalRef, ['provider_reconciliation' => 'absent']);
        }
        $this->assertState($claim, [PleskAccount::STATE_ACTIVE, PleskAccount::STATE_SUSPENDED]);

        $api = $this->plesk->withConnection($connection);
        $subscriptionId = (int) $claim->remote_id;
        $subscription = $this->remote(fn (): array => $api->subscription($subscriptionId));
        if ($subscription['owner_id'] !== $claim->customer_id) {
            throw $this->refusal('errors.unverified');
        }

        $state = match (true) {
            $subscription['status'] === self::STATUS_ACTIVE => PleskAccount::STATE_ACTIVE,
            in_array($subscription['status'], self::STATUSES_SUSPENDED, true) => PleskAccount::STATE_SUSPENDED,
            default => throw $this->refusal('errors.malformed'),
        };
        if ($state !== $claim->state) {
            $this->transition($claim, [$claim->state], ['state' => $state]);
        }

        return $this->info($instance, $state, $claim->remote_id, [
            'provider_mapping' => ['provider_id' => $claim->remote_id, 'domain' => $claim->remote_name, 'plan' => $claim->plan],
        ]);
    }

    public function panel(ServiceInstanceInfo $instance): ?ProvisionerPanelData
    {
        $claim = $this->claim($instance->id);
        if ($claim === null || ! in_array($claim->state, [PleskAccount::STATE_ACTIVE, PleskAccount::STATE_SUSPENDED], true)) {
            return null;
        }

        return new ProvisionerPanelData($this->message('panel.title'), [
            ['label' => $this->message('panel.status'), 'value' => $this->message('status.'.$claim->state)],
            ['label' => $this->message('panel.username'), 'value' => $claim->customer_login],
            ['label' => $this->message('panel.plan'), 'value' => (string) $claim->plan],
            ['label' => $this->message('panel.domain'), 'value' => (string) $claim->remote_name],
        ]);
    }

    /** @return list<string> */
    protected function requiredConnectionKeys(): array
    {
        return ['api_url', 'api_token'];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  array{plan: string, domain: string, ip: string}  $order
     */
    private function provisionLocked(ServiceInstanceInfo $instance, array $connection, string $endpoint, array $order): void
    {
        $api = $this->plesk->withConnection($connection);
        $claim = $this->claim($instance->id);
        if ($claim === null) {
            $this->assertNoUnclaimedMapping($instance);
            $this->create($api, $instance, $this->insertClaim($instance->id, $endpoint, $order), $order['ip'], true);

            return;
        }

        $this->assertEndpoint($claim, $endpoint);
        if (! in_array($claim->state, self::PENDING_STATES, true)) {
            $this->assertState($claim, [PleskAccount::STATE_ACTIVE, PleskAccount::STATE_SUSPENDED]);

            return;
        }
        // A rejected create left no subscription, so the retry may use the current product plan.
        if ($claim->state === PleskAccount::STATE_FAILED) {
            $this->transition($claim, [PleskAccount::STATE_FAILED], ['state' => PleskAccount::STATE_CREATING, 'plan' => $order['plan']]);
        }
        $this->create($api, $instance, $claim, $order['ip'], false);
    }

    /** @param array{plan: string, domain: string, ip: string} $order */
    private function insertClaim(int $instanceId, string $endpoint, array $order): PleskAccount
    {
        try {
            return PleskAccount::query()->create([
                'service_instance_id' => $instanceId,
                'endpoint' => $endpoint,
                'remote_id' => self::PENDING_PREFIX.$instanceId,
                'remote_name' => $order['domain'],
                'customer_login' => $this->login($instanceId),
                'plan' => $order['plan'],
                'state' => PleskAccount::STATE_CREATING,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->refusal('errors.claimed');
        }
    }

    /**
     * Creates the customer and then the subscription. A fresh claim creates directly; a
     * pending claim first reads the stored login and domain, because an earlier attempt may
     * have succeeded without Agovena seeing the response.
     */
    private function create(PleskApi $api, ServiceInstanceInfo $instance, PleskAccount $claim, string $ip, bool $fresh): void
    {
        try {
            if (! array_key_exists((string) $claim->plan, $api->servicePlans())) {
                throw new ServerProviderException('errors.package_unavailable');
            }
        } catch (ServerProviderException $exception) {
            if ($fresh) {
                $this->transition($claim, self::PENDING_STATES, ['state' => PleskAccount::STATE_FAILED]);
            }
            throw $this->refusal($exception->errorKey);
        }

        if ($claim->customer_id === null) {
            $customerId = $fresh ? null : $this->lookup($claim, fn (): int => $api->customerByLogin($claim->customer_login));
            $customerId ??= $this->add($claim, fn (): int => $api->addCustomer($this->customer($instance, $claim)), rotateLogin: true);
            $this->transition($claim, self::PENDING_STATES, ['customer_id' => $customerId]);
        }

        $subscription = $fresh ? null : $this->lookup($claim, fn (): array => $api->subscriptionByName((string) $claim->remote_name));
        if ($subscription !== null && $subscription['owner_id'] !== $claim->customer_id) {
            $this->halt($claim, ['state' => PleskAccount::STATE_UNKNOWN], 'errors.unverified');
        }
        $subscriptionId = $subscription['id'] ?? $this->add($claim, fn (): int => $api->addSubscription([
            'name' => (string) $claim->remote_name,
            'owner_id' => (int) $claim->customer_id,
            'ip' => $ip,
            'plan' => (string) $claim->plan,
            'ftp_login' => $claim->customer_login,
            'ftp_password' => Str::password(self::PASSWORD_LENGTH),
        ]));

        $this->transition($claim, self::PENDING_STATES, ['remote_id' => (string) $subscriptionId, 'state' => PleskAccount::STATE_ACTIVE]);
    }

    /**
     * Reads an object by an identifier Agovena generated. Only a documented "does not exist"
     * result means absent; anything else keeps the claim unknown for manual review.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T|null
     */
    private function lookup(PleskAccount $claim, Closure $read): mixed
    {
        try {
            return $read();
        } catch (ServerProviderException $exception) {
            if ($exception->errorKey === 'errors.not_found') {
                return null;
            }
            $this->halt($claim, ['state' => PleskAccount::STATE_UNKNOWN], 'errors.unverified');
        }
    }

    /**
     * Only a documented rejection proves that nothing was created; any other failure leaves the
     * outcome unknown. A rejected customer login may belong to someone else, so it is replaced.
     *
     * @param  Closure(): int  $create
     */
    private function add(PleskAccount $claim, Closure $create, bool $rotateLogin = false): int
    {
        try {
            return $create();
        } catch (ServerProviderException $exception) {
            $rejected = in_array($exception->errorKey, self::DEFINITIVE_ERRORS, true);
            $changes = ['state' => $rejected ? PleskAccount::STATE_FAILED : PleskAccount::STATE_UNKNOWN];
            if ($rejected && $rotateLogin) {
                $changes['customer_login'] = $this->login($claim->service_instance_id);
            }
            $this->halt($claim, $changes, $exception->errorKey);
        }
    }

    /** @param array<string, mixed> $changes */
    private function halt(PleskAccount $claim, array $changes, string $key): never
    {
        $this->transition($claim, self::PENDING_STATES, $changes);

        throw $this->refusal($key);
    }

    /**
     * The generated password is sent once and never stored; the customer resets it through
     * the Plesk login page with the recorded email address.
     *
     * @return array{login: string, name: string, password: string, email: string|null}
     */
    private function customer(ServiceInstanceInfo $instance, PleskAccount $claim): array
    {
        $owner = ServiceInstance::query()->find($instance->id);
        $name = mb_substr(trim((string) $owner?->getAttribute('customer_name')), 0, 60);
        $email = trim((string) $owner?->getAttribute('customer_email'));

        return [
            'login' => $claim->customer_login,
            'name' => $name === '' ? $claim->customer_login : $name,
            'password' => Str::password(self::PASSWORD_LENGTH),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email,
        ];
    }

    /** The customer Agovena created is deleted only when it owns no other subscription. */
    private function removeCustomer(PleskApi $api, PleskAccount $claim): void
    {
        $customerId = $claim->customer_id;
        if ($customerId === null) {
            return;
        }

        $this->remote(function () use ($api, $customerId): void {
            if ($api->subscriptionsOwnedBy($customerId) === []) {
                $api->deleteCustomer($customerId);
            }
        });
        $this->transition($claim, [PleskAccount::STATE_TERMINATED], ['customer_id' => null]);
    }

    private function switchSuspension(ServiceInstanceInfo $instance, string $from, string $to, int $status): void
    {
        $connection = $this->connection($instance);
        $claim = $this->ownedClaim($instance, $connection);
        if ($claim->state === $to) {
            return;
        }
        $this->assertState($claim, [$from]);

        $api = $this->plesk->withConnection($connection);
        $subscriptionId = (int) $claim->remote_id;
        $this->remote(fn () => $api->setSubscriptionStatus($subscriptionId, $status));

        $this->transition($claim, [$from], ['state' => $to]);
    }

    /**
     * Compare-and-swap on revision and state; a concurrent change is never overwritten.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $changes
     */
    private function transition(PleskAccount $claim, array $from, array $changes): void
    {
        $changes['revision'] = $claim->revision + 1;
        try {
            $updated = PleskAccount::query()
                ->whereKey($claim->id)
                ->where('revision', $claim->revision)
                ->whereIn('state', $from)
                ->update($changes);
        } catch (UniqueConstraintViolationException) {
            throw $this->refusal('errors.claimed');
        }

        if ($updated !== 1) {
            throw $this->refusal('errors.conflict');
        }

        $claim->forceFill($changes)->syncOriginal();
    }

    /** @param array<string, mixed> $connection */
    private function ownedClaim(ServiceInstanceInfo $instance, array $connection): PleskAccount
    {
        $claim = $this->claim($instance->id) ?? throw $this->refusal('errors.not_provisioned');
        $this->assertEndpoint($claim, $this->endpoint($connection));

        return $claim;
    }

    private function claim(int $instanceId): ?PleskAccount
    {
        return PleskAccount::query()->where('service_instance_id', $instanceId)->first();
    }

    private function assertEndpoint(PleskAccount $claim, string $endpoint): void
    {
        if ($claim->endpoint !== $endpoint) {
            throw $this->refusal('errors.endpoint_changed');
        }
    }

    /** @param list<string> $allowed */
    private function assertState(PleskAccount $claim, array $allowed): void
    {
        if (in_array($claim->state, $allowed, true)) {
            return;
        }

        throw $this->refusal(match ($claim->state) {
            PleskAccount::STATE_TERMINATED => 'errors.terminated',
            PleskAccount::STATE_CREATING, PleskAccount::STATE_UNKNOWN => 'errors.unverified',
            default => 'errors.not_provisioned',
        });
    }

    /** A provider reference without a claim row was not created by Agovena and is never trusted. */
    private function assertNoUnclaimedMapping(ServiceInstanceInfo $instance): void
    {
        if (trim((string) $instance->externalRef) !== '' || ($instance->meta['provider_mapping'] ?? []) !== []) {
            throw $this->refusal('errors.not_provisioned');
        }
    }

    /** A random part keeps logins unguessable; the base36 service id keeps them traceable. */
    private function login(int $instanceId): string
    {
        return 'agv'.Str::lower(Str::random(8)).base_convert((string) $instanceId, 10, 36);
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array{plan: string, domain: string, ip: string}
     */
    private function order(ServiceInstanceInfo $instance, array $connection): array
    {
        $settings = $instance->providerSettings ?? [];
        $plan = trim((string) ($settings['service_plan'] ?? ''));
        $domain = strtolower(trim((string) ($settings['domain'] ?? '')));
        $ip = trim((string) ($connection['ip_address'] ?? ''));
        if ($plan === ''
            || ! str_contains($domain, '.')
            || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || filter_var($ip, FILTER_VALIDATE_IP) === false
        ) {
            throw $this->refusal('errors.invalid_mapping');
        }

        return ['plan' => $plan, 'domain' => $domain, 'ip' => $ip];
    }

    /** @return array<string, mixed> */
    private function connection(ServiceInstanceInfo $instance): array
    {
        $settings = $instance->serverSettings ?? [];
        if ($settings !== []) {
            return $settings;
        }
        if (($instance->meta['server_settings_required'] ?? false) === true) {
            throw $this->refusal('errors.not_configured');
        }

        foreach ($this->serverSettings() as $definition) {
            $settings[$definition->key] = $this->settings->get($this->id(), $definition->key, $definition->default);
        }

        return $settings;
    }

    /** @param array<string, mixed> $connection */
    private function endpoint(array $connection): string
    {
        try {
            return PleskEndpoint::normalize((string) ($connection['api_url'] ?? ''));
        } catch (ServerProviderException $exception) {
            throw $this->refusal($exception->errorKey);
        }
    }

    /**
     * A documented "does not exist" answer for an object Agovena created is never read as a
     * termination; it needs manual review.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function remote(Closure $call): mixed
    {
        try {
            return $call();
        } catch (ServerProviderException $exception) {
            throw $this->refusal($exception->errorKey === 'errors.not_found' ? 'errors.unverified' : $exception->errorKey);
        }
    }

    /** @param array<string, mixed> $meta */
    private function info(ServiceInstanceInfo $instance, string $status, ?string $externalRef, array $meta): ServiceInstanceInfo
    {
        return new ServiceInstanceInfo(
            id: $instance->id,
            label: $instance->label,
            status: $status,
            providerKey: $this->id(),
            externalRef: $externalRef,
            meta: array_merge(Arr::except($instance->meta, ['provider_mapping']), $meta),
            serverSettings: $instance->serverSettings,
            providerSettings: $instance->providerSettings,
        );
    }

    private function refusal(string $key): ValidationException
    {
        return ValidationException::withMessages(['instance' => $this->message($key)]);
    }
}
