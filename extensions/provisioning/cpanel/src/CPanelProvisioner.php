<?php

declare(strict_types=1);

namespace Agovena\Extensions\CPanel;

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
 * Ownership of a cPanel account is proven only by a cpanel_accounts claim row that
 * Agovena wrote before its own createacct call. Lookups never adopt an account.
 */
final class CPanelProvisioner extends AbstractServerProvisioner
{
    /** Clock skew tolerated between Agovena and WHM when matching an ambiguous create. */
    private const CREATE_CLOCK_SKEW_SECONDS = 300;

    /** Documented createacct username maximum. */
    private const USERNAME_MAX_LENGTH = 16;

    public function __construct(ExtensionSettingsRepository $settings, private readonly CPanelApi $cpanel)
    {
        parent::__construct($settings, $cpanel);
    }

    public function id(): string
    {
        return 'cpanel';
    }

    public function label(): string
    {
        return __('cpanel::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'cpanel::messages.settings.api_url', required: true, help: 'cpanel::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'cpanel::messages.settings.api_token', secret: true, required: true, help: 'cpanel::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('api_username', 'cpanel::messages.settings.api_username', required: true, help: 'cpanel::messages.settings.api_username_help'),
            new ExtensionSettingDefinition('verify_tls', 'cpanel::messages.settings.verify_tls', type: 'boolean', default: true, help: 'cpanel::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'cpanel::messages.settings.timeout', default: '20', help: 'cpanel::messages.settings.timeout_help'),
        ];
    }

    /** @return list<ExtensionSettingDefinition> */
    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('package', 'cpanel::messages.product.package', required: true, help: 'cpanel::messages.product.package_help'),
        ];
    }

    public function provision(ServiceInstanceInfo $instance): void
    {
        $package = $this->package($instance->providerSettings ?? []);
        $connection = $this->connection($instance);
        $endpoint = $this->endpoint($connection);

        try {
            Cache::lock('agovena:cpanel:provision:'.$instance->id, 120)
                ->block(10, fn () => $this->provisionLocked($instance, $connection, $endpoint, $package));
        } catch (LockTimeoutException) {
            throw $this->refusal('errors.conflict');
        }
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        $this->switchSuspension($instance, CPanelAccount::STATE_ACTIVE, CPanelAccount::STATE_SUSPENDED);
    }

    public function unsuspend(ServiceInstanceInfo $instance): void
    {
        $this->switchSuspension($instance, CPanelAccount::STATE_SUSPENDED, CPanelAccount::STATE_ACTIVE);
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
        if ($claim->state === CPanelAccount::STATE_TERMINATED) {
            return;
        }

        // A rejected create left nothing on the server, so the claim closes without a remote call.
        if ($claim->state !== CPanelAccount::STATE_FAILED) {
            $this->assertState($claim, [CPanelAccount::STATE_ACTIVE, CPanelAccount::STATE_SUSPENDED]);
            $api = $this->cpanel->withConnection($connection);
            $this->remote(fn () => $api->terminate($claim->remote_id));
        }

        $this->transition($claim, [$claim->state], ['state' => CPanelAccount::STATE_TERMINATED]);
    }

    /** @param string|array<string, mixed> $plan */
    public function changePlan(ServiceInstanceInfo $instance, string|array $plan): void
    {
        $providerSettings = is_array($plan) ? ($plan['provider_settings'] ?? null) : null;
        $package = $this->package(is_array($providerSettings) ? $providerSettings : []);
        $serverSettings = is_array($plan) ? ($plan['server_settings'] ?? null) : null;
        $connection = is_array($serverSettings) && $serverSettings !== [] ? $serverSettings : $this->connection($instance);

        $claim = $this->ownedClaim($instance, $connection);
        $this->assertState($claim, [CPanelAccount::STATE_ACTIVE, CPanelAccount::STATE_SUSPENDED]);
        if ($claim->plan === $package) {
            return;
        }

        $api = $this->cpanel->withConnection($connection);
        $this->remote(function () use ($api, $claim, $package): void {
            if (! in_array($package, $api->creatablePackages(), true)) {
                throw new ServerProviderException('errors.package_unavailable');
            }
            $api->changePlan($claim->remote_id, ['package' => $package]);
        });

        $this->transition($claim, [$claim->state], ['plan' => $package]);
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
        if ($claim->state === CPanelAccount::STATE_TERMINATED) {
            return $this->info($instance, 'terminated', $claim->remote_id, []);
        }
        if ($claim->state === CPanelAccount::STATE_FAILED) {
            return $this->info($instance, $instance->status, $instance->externalRef, ['provider_reconciliation' => 'absent']);
        }
        $this->assertState($claim, [CPanelAccount::STATE_ACTIVE, CPanelAccount::STATE_SUSPENDED]);

        $api = $this->cpanel->withConnection($connection);
        $account = $this->remote(fn (): array => $api->getServer($claim->remote_id));
        $suspended = $account['suspended'] ?? null;
        $plan = $account['plan'] ?? null;
        $domain = $this->domain($account);
        if (! in_array($suspended, [0, 1], true) || ! is_string($plan) || $plan === '' || $domain === null) {
            throw $this->refusal('errors.malformed');
        }

        $state = $suspended === 1 ? CPanelAccount::STATE_SUSPENDED : CPanelAccount::STATE_ACTIVE;
        if ([$claim->state, $claim->plan, $claim->remote_name] !== [$state, $plan, $domain]) {
            $this->transition($claim, [$claim->state], ['state' => $state, 'plan' => $plan, 'remote_name' => $domain]);
        }

        return $this->info($instance, $state, $claim->remote_id, [
            'provider_mapping' => ['provider_id' => $claim->remote_id, 'domain' => $domain, 'plan' => $plan],
        ]);
    }

    public function panel(ServiceInstanceInfo $instance): ?ProvisionerPanelData
    {
        $claim = $this->claim($instance->id);
        if ($claim === null || ! in_array($claim->state, [CPanelAccount::STATE_ACTIVE, CPanelAccount::STATE_SUSPENDED], true)) {
            return null;
        }

        $fields = [
            ['label' => $this->message('panel.status'), 'value' => $this->message('status.'.$claim->state)],
            ['label' => $this->message('panel.username'), 'value' => $claim->remote_id],
            ['label' => $this->message('panel.package'), 'value' => (string) $claim->plan],
        ];
        if ($claim->remote_name !== null) {
            $fields[] = ['label' => $this->message('panel.domain'), 'value' => $claim->remote_name];
        }

        return new ProvisionerPanelData($this->message('panel.title'), $fields);
    }

    /** @return list<string> */
    protected function requiredConnectionKeys(): array
    {
        return ['api_url', 'api_token', 'api_username'];
    }

    /** @param array<string, mixed> $connection */
    private function provisionLocked(ServiceInstanceInfo $instance, array $connection, string $endpoint, string $package): void
    {
        $api = $this->cpanel->withConnection($connection);
        $claim = $this->claim($instance->id);
        if ($claim === null) {
            $this->assertNoUnclaimedMapping($instance);
            $this->create($api, $this->insertClaim($instance->id, $endpoint, $package), $package);

            return;
        }

        $this->assertEndpoint($claim, $endpoint);
        if (in_array($claim->state, [CPanelAccount::STATE_CREATING, CPanelAccount::STATE_UNKNOWN], true)) {
            $this->reconcile($api, $claim, $package, trim((string) ($connection['api_username'] ?? '')));
        } elseif ($claim->state === CPanelAccount::STATE_FAILED) {
            $this->retryRejected($api, $claim, $package);
        } else {
            $this->assertState($claim, [CPanelAccount::STATE_ACTIVE, CPanelAccount::STATE_SUSPENDED]);
        }
    }

    private function insertClaim(int $instanceId, string $endpoint, string $package): CPanelAccount
    {
        try {
            return CPanelAccount::query()->create([
                'service_instance_id' => $instanceId,
                'endpoint' => $endpoint,
                'remote_id' => $this->username($instanceId),
                'plan' => $package,
                'state' => CPanelAccount::STATE_CREATING,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->refusal('errors.claimed');
        }
    }

    private function create(CPanelApi $api, CPanelAccount $claim, string $package): void
    {
        try {
            if (! in_array($package, $api->creatablePackages(), true)) {
                throw new ServerProviderException('errors.package_unavailable');
            }
        } catch (ServerProviderException $exception) {
            $this->transition($claim, [CPanelAccount::STATE_CREATING], ['state' => CPanelAccount::STATE_FAILED]);
            throw $this->refusal($exception->errorKey);
        }

        try {
            $api->createServer(['username' => $claim->remote_id, 'plan' => $package]);
        } catch (ServerProviderException $exception) {
            // Only a documented rejection or refused credentials prove that WHM created nothing.
            $rejected = in_array($exception->errorKey, ['errors.rejected', 'errors.unauthorized'], true);
            $this->transition($claim, [CPanelAccount::STATE_CREATING], [
                'state' => $rejected ? CPanelAccount::STATE_FAILED : CPanelAccount::STATE_UNKNOWN,
            ]);
            throw $this->refusal($exception->errorKey);
        }

        $this->transition($claim, [CPanelAccount::STATE_CREATING], ['state' => CPanelAccount::STATE_ACTIVE]);
    }

    /**
     * An interrupted create is resolved by reading only the username Agovena generated and
     * claimed. The account is accepted only when it matches the recorded create.
     */
    private function reconcile(CPanelApi $api, CPanelAccount $claim, string $package, string $owner): void
    {
        $pending = [CPanelAccount::STATE_CREATING, CPanelAccount::STATE_UNKNOWN];
        try {
            $account = $api->findServerByExternalId($claim->remote_id);
        } catch (ServerProviderException) {
            $this->transition($claim, $pending, ['state' => CPanelAccount::STATE_UNKNOWN]);
            throw $this->refusal('errors.unverified');
        }

        if ($account === null) {
            $this->transition($claim, $pending, ['state' => CPanelAccount::STATE_CREATING, 'plan' => $package]);
            $this->create($api, $claim, $package);

            return;
        }

        if (! $this->matchesRecordedCreate($claim, $account, $owner)) {
            $this->transition($claim, $pending, ['state' => CPanelAccount::STATE_UNKNOWN]);
            throw $this->refusal('errors.unverified');
        }

        $this->transition($claim, $pending, ['state' => CPanelAccount::STATE_ACTIVE, 'remote_name' => $this->domain($account)]);
    }

    /**
     * WHM rejected the previous create. A fresh username is used because the rejected one
     * may collide with an existing account on its first eight characters.
     */
    private function retryRejected(CPanelApi $api, CPanelAccount $claim, string $package): void
    {
        $account = $this->remote(fn (): ?array => $api->findServerByExternalId($claim->remote_id));
        if ($account !== null) {
            throw $this->refusal('errors.unverified');
        }

        $this->transition($claim, [CPanelAccount::STATE_FAILED], [
            'state' => CPanelAccount::STATE_CREATING,
            'remote_id' => $this->username($claim->service_instance_id),
            'plan' => $package,
        ]);
        $this->create($api, $claim, $package);
    }

    /** @param array<string, mixed> $account */
    private function matchesRecordedCreate(CPanelAccount $claim, array $account, string $owner): bool
    {
        $createdAt = $account['unix_startdate'] ?? null;

        return ($account['owner'] ?? null) === $owner
            && ($account['plan'] ?? null) === $claim->plan
            && is_int($createdAt)
            && $createdAt >= $claim->created_at->getTimestamp() - self::CREATE_CLOCK_SKEW_SECONDS;
    }

    private function switchSuspension(ServiceInstanceInfo $instance, string $from, string $to): void
    {
        $connection = $this->connection($instance);
        $claim = $this->ownedClaim($instance, $connection);
        if ($claim->state === $to) {
            return;
        }
        $this->assertState($claim, [$from]);

        $api = $this->cpanel->withConnection($connection);
        $this->remote(function () use ($api, $claim, $to): void {
            if ($to === CPanelAccount::STATE_SUSPENDED) {
                $api->suspend($claim->remote_id);
            } else {
                $api->unsuspend($claim->remote_id);
            }
        });

        $this->transition($claim, [$from], ['state' => $to]);
    }

    /**
     * Compare-and-swap on revision and state; a concurrent change is never overwritten.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $changes
     */
    private function transition(CPanelAccount $claim, array $from, array $changes): void
    {
        $changes['revision'] = $claim->revision + 1;
        try {
            $updated = CPanelAccount::query()
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
    private function ownedClaim(ServiceInstanceInfo $instance, array $connection): CPanelAccount
    {
        $claim = $this->claim($instance->id) ?? throw $this->refusal('errors.not_provisioned');
        $this->assertEndpoint($claim, $this->endpoint($connection));

        return $claim;
    }

    private function claim(int $instanceId): ?CPanelAccount
    {
        return CPanelAccount::query()->where('service_instance_id', $instanceId)->first();
    }

    private function assertEndpoint(CPanelAccount $claim, string $endpoint): void
    {
        if ($claim->endpoint !== $endpoint) {
            throw $this->refusal('errors.endpoint_changed');
        }
    }

    /** @param list<string> $allowed */
    private function assertState(CPanelAccount $claim, array $allowed): void
    {
        if (in_array($claim->state, $allowed, true)) {
            return;
        }

        throw $this->refusal(match ($claim->state) {
            CPanelAccount::STATE_TERMINATED => 'errors.terminated',
            CPanelAccount::STATE_CREATING, CPanelAccount::STATE_UNKNOWN => 'errors.unverified',
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

    /** Random first eight characters honour the WHM first-eight-unique rule; the base36 id keeps it traceable. */
    private function username(int $instanceId): string
    {
        $username = 'a'.Str::lower(Str::random(7)).base_convert((string) $instanceId, 10, 36);
        if (strlen($username) > self::USERNAME_MAX_LENGTH) {
            throw $this->refusal('errors.invalid_mapping');
        }

        return $username;
    }

    /** @param array<string, mixed> $settings */
    private function package(array $settings): string
    {
        $package = $settings['package'] ?? null;
        if (! is_string($package) || trim($package) === '') {
            throw $this->refusal('errors.invalid_mapping');
        }

        return trim($package);
    }

    /** @param array<string, mixed> $account */
    private function domain(array $account): ?string
    {
        $domain = $account['domain'] ?? null;

        return is_string($domain) && $domain !== '' ? $domain : null;
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
            return CPanelEndpoint::normalize((string) ($connection['api_url'] ?? ''));
        } catch (ServerProviderException $exception) {
            throw $this->refusal($exception->errorKey);
        }
    }

    /**
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
            throw $this->refusal($exception->errorKey);
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
