<?php

declare(strict_types=1);

namespace Agovena\Extensions\Enhance;

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
use Illuminate\Validation\ValidationException;

/**
 * Ownership of an Enhance customer org, subscription and website is proven only by an
 * enhance_accounts claim row that Agovena wrote before its own create calls. Lookups
 * never adopt foreign objects.
 *
 * @phpstan-type Order array{plan: int, domain: string, name: string}
 */
final class EnhanceProvisioner extends AbstractServerProvisioner
{
    private const PENDING_PREFIX = 'pending:';

    private const PENDING_STATES = [EnhanceAccount::STATE_CREATING, EnhanceAccount::STATE_UNKNOWN];

    private const OWNED_STATES = [EnhanceAccount::STATE_ACTIVE, EnhanceAccount::STATE_SUSPENDED];

    /** Errors raised before a request reached Enhance or refused by it; nothing was created. */
    private const DEFINITIVE_ERRORS = ['errors.unauthorized', 'errors.demo_disabled', 'errors.not_configured', 'errors.invalid_mapping'];

    /** Documented create rejections (invalid input, not found, already exists). */
    private const REJECTED_STATUSES = [400, 404, 409];

    public function __construct(ExtensionSettingsRepository $settings, private readonly EnhanceApi $enhance)
    {
        parent::__construct($settings, $enhance);
    }

    public function id(): string
    {
        return 'enhance';
    }

    public function label(): string
    {
        return __('enhance::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'enhance::messages.settings.api_url', required: true, help: 'enhance::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'enhance::messages.settings.api_token', secret: true, required: true, help: 'enhance::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('account_id', 'enhance::messages.settings.account_id', required: true, help: 'enhance::messages.settings.account_id_help'),
            new ExtensionSettingDefinition('verify_tls', 'enhance::messages.settings.verify_tls', type: 'boolean', default: true, help: 'enhance::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'enhance::messages.settings.timeout', default: '20', help: 'enhance::messages.settings.timeout_help'),
        ];
    }

    /** @return list<ExtensionSettingDefinition> */
    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('plan', 'enhance::messages.product.plan', required: true, help: 'enhance::messages.product.plan_help'),
            new ExtensionSettingDefinition('domain', 'enhance::messages.product.domain', required: true, help: 'enhance::messages.product.domain_help'),
        ];
    }

    public function provision(ServiceInstanceInfo $instance): void
    {
        $connection = $this->connection($instance);
        $order = $this->order($instance);
        $endpoint = $this->endpoint($connection);
        $this->account($connection);

        $this->locked($instance->id, function () use ($instance, $connection, $endpoint, $order): void {
            $api = $this->enhance->withConnection($connection);
            $claim = $this->claim($instance->id);
            if ($claim === null) {
                $this->assertNoUnclaimedMapping($instance);
                $this->create($api, $this->insertClaim($instance->id, $endpoint, $order), $order['name'], true);

                return;
            }

            $this->assertEndpoint($claim, $endpoint);
            if ($claim->state === EnhanceAccount::STATE_FAILED) {
                // A rejected step created nothing, so the retry may use the current product plan.
                $changes = ['state' => EnhanceAccount::STATE_CREATING];
                if ($this->pending($claim)) {
                    $changes['plan'] = (string) $order['plan'];
                }
                $this->transition($claim, [EnhanceAccount::STATE_FAILED], $changes);
                $this->create($api, $claim, $order['name'], true);
            } elseif (in_array($claim->state, self::PENDING_STATES, true)) {
                $this->create($api, $claim, $order['name'], false);
            } else {
                $this->assertState($claim, self::OWNED_STATES);
            }
        });
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        $this->switchSuspension($instance, EnhanceAccount::STATE_ACTIVE, EnhanceAccount::STATE_SUSPENDED);
    }

    public function unsuspend(ServiceInstanceInfo $instance): void
    {
        $this->switchSuspension($instance, EnhanceAccount::STATE_SUSPENDED, EnhanceAccount::STATE_ACTIVE);
    }

    public function terminate(ServiceInstanceInfo $instance): void
    {
        $connection = $this->connection($instance);
        $this->locked($instance->id, function () use ($instance, $connection): void {
            $claim = $this->claim($instance->id);
            if ($claim === null) {
                $this->assertNoUnclaimedMapping($instance);

                return;
            }

            $this->assertEndpoint($claim, $this->endpoint($connection));
            $api = $this->enhance->withConnection($connection);
            if ($claim->state !== EnhanceAccount::STATE_TERMINATED) {
                // A failed claim may hold a customer org and even a subscription from earlier steps.
                $this->assertState($claim, [...self::OWNED_STATES, EnhanceAccount::STATE_FAILED]);
                if (! $this->pending($claim)) {
                    $this->remote(fn () => $api->deleteSubscription((string) $claim->customer_org_id, (int) $claim->remote_id));
                }
                $this->transition($claim, [$claim->state], ['state' => EnhanceAccount::STATE_TERMINATED]);
            }

            $this->removeCustomerOrg($api, $claim);
        });
    }

    /** @param string|array<string, mixed> $plan */
    public function changePlan(ServiceInstanceInfo $instance, string|array $plan): void
    {
        $settings = is_array($plan) ? ($plan['provider_settings'] ?? null) : ['plan' => $plan];
        $target = filter_var(is_array($settings) ? ($settings['plan'] ?? null) : null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($target === false) {
            throw $this->refusal('errors.invalid_mapping');
        }
        $serverSettings = is_array($plan) ? ($plan['server_settings'] ?? null) : null;
        $connection = is_array($serverSettings) && $serverSettings !== [] ? $serverSettings : $this->connection($instance);
        $account = $this->account($connection);

        $this->locked($instance->id, function () use ($instance, $connection, $account, $target): void {
            $claim = $this->ownedClaim($instance, $connection);
            $this->assertState($claim, self::OWNED_STATES);
            if ($claim->plan === (string) $target) {
                return;
            }

            $api = $this->enhance->withConnection($connection);
            $this->remote(function () use ($api, $claim, $account, $target): void {
                if (! in_array($target, $api->planIds(), true)) {
                    throw new ServerProviderException('errors.plan_unavailable');
                }
                $api->updateSubscription((string) $claim->customer_org_id, (int) $claim->remote_id, ['planId' => $target]);
                $subscription = $api->subscription((string) $claim->customer_org_id, (int) $claim->remote_id);
                if ($subscription['planId'] !== $target || ! $this->ours($subscription, $claim, $account)) {
                    throw new ServerProviderException('errors.unverified');
                }
            });

            $this->transition($claim, [$claim->state], ['plan' => (string) $target]);
        });
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
        if ($claim->state === EnhanceAccount::STATE_TERMINATED) {
            return $this->info($instance, 'terminated', $claim->remote_id, []);
        }
        if ($claim->state === EnhanceAccount::STATE_FAILED) {
            return $this->info($instance, $instance->status, $instance->externalRef, ['provider_reconciliation' => 'absent']);
        }
        $this->assertState($claim, self::OWNED_STATES);
        $account = $this->account($connection);

        return $this->locked($instance->id, function () use ($instance, $connection, $account, $claim): ServiceInstanceInfo {
            $api = $this->enhance->withConnection($connection);
            $subscription = $this->remote(fn (): array => $api->subscription((string) $claim->customer_org_id, (int) $claim->remote_id));
            if ($subscription['deleted'] || ! $this->ours($subscription, $claim, $account)) {
                throw $this->refusal('errors.unverified');
            }

            $state = $subscription['suspended'] ? EnhanceAccount::STATE_SUSPENDED : EnhanceAccount::STATE_ACTIVE;
            $plan = (string) $subscription['planId'];
            if ([$claim->state, $claim->plan] !== [$state, $plan]) {
                $this->transition($claim, [$claim->state], ['state' => $state, 'plan' => $plan]);
            }

            return $this->info($instance, $state, $claim->remote_id, [
                'provider_mapping' => [
                    'provider_id' => $claim->remote_id,
                    'customer_org_id' => $claim->customer_org_id,
                    'website_id' => $claim->website_id,
                    'domain' => $claim->remote_name,
                    'plan' => $claim->plan,
                ],
            ]);
        });
    }

    public function panel(ServiceInstanceInfo $instance): ?ProvisionerPanelData
    {
        $claim = $this->claim($instance->id);
        if ($claim === null || ! in_array($claim->state, self::OWNED_STATES, true)) {
            return null;
        }

        return new ProvisionerPanelData($this->message('panel.title'), [
            ['label' => $this->message('panel.status'), 'value' => $this->message('status.'.$claim->state)],
            ['label' => $this->message('panel.domain'), 'value' => (string) $claim->remote_name],
            ['label' => $this->message('panel.plan'), 'value' => (string) $claim->plan],
            ['label' => $this->message('panel.subscription_id'), 'value' => $claim->remote_id],
        ]);
    }

    /** @return list<string> */
    protected function requiredConnectionKeys(): array
    {
        return ['api_url', 'api_token', 'account_id'];
    }

    /** @param Order $order */
    private function insertClaim(int $instanceId, string $endpoint, array $order): EnhanceAccount
    {
        try {
            return EnhanceAccount::query()->create([
                'service_instance_id' => $instanceId,
                'endpoint' => $endpoint,
                'remote_id' => self::PENDING_PREFIX.$instanceId,
                'remote_name' => $order['domain'],
                'plan' => (string) $order['plan'],
                'state' => EnhanceAccount::STATE_CREATING,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->refusal('errors.claimed');
        }
    }

    /**
     * Creates the customer org, the subscription and the website, skipping the steps whose
     * id is recorded. A step without a recorded id after an interrupted attempt is created
     * again only when a read inside the org Agovena created proves it absent; an interrupted
     * customer org create is never retried.
     */
    private function create(EnhanceApi $api, EnhanceAccount $claim, string $name, bool $fresh): void
    {
        if ($claim->customer_org_id === null && ! $fresh) {
            $this->halt($claim, EnhanceAccount::STATE_UNKNOWN, 'errors.unverified');
        }

        if ($this->pending($claim)) {
            try {
                if (! in_array((int) $claim->plan, $api->planIds(), true)) {
                    throw new ServerProviderException('errors.plan_unavailable');
                }
            } catch (ServerProviderException $exception) {
                $this->halt($claim, $fresh ? EnhanceAccount::STATE_FAILED : EnhanceAccount::STATE_UNKNOWN, $exception->errorKey);
            }
        }

        if ($claim->customer_org_id === null) {
            $orgId = $this->attempt($claim, fn (): string => $api->createCustomer($name));
            $this->transition($claim, self::PENDING_STATES, ['customer_org_id' => $orgId]);
        }
        $orgId = (string) $claim->customer_org_id;

        if ($this->pending($claim)) {
            if (! $fresh) {
                $this->assertAbsent($claim, fn (): array => $api->subscriptionStatuses($orgId));
            }
            $subscriptionId = $this->attempt($claim, fn (): int => $api->createSubscription($orgId, (int) $claim->plan));
            $this->transition($claim, self::PENDING_STATES, ['remote_id' => (string) $subscriptionId]);
        } elseif (! $fresh) {
            $this->assertAbsent($claim, fn (): array => $api->websiteStatuses($orgId, (int) $claim->remote_id));
        }

        $websiteId = $this->attempt($claim, fn (): string => $api->createWebsite($orgId, (string) $claim->remote_name, (int) $claim->remote_id));
        $this->transition($claim, self::PENDING_STATES, ['website_id' => $websiteId, 'state' => EnhanceAccount::STATE_ACTIVE]);
    }

    /**
     * Runs one create call. Only a documented rejection proves that nothing was created;
     * any other failure leaves the outcome unknown for manual review.
     *
     * @template T
     *
     * @param  Closure(): T  $create
     * @return T
     */
    private function attempt(EnhanceAccount $claim, Closure $create): mixed
    {
        try {
            return $create();
        } catch (ServerProviderException $exception) {
            $rejected = in_array($exception->errorKey, self::DEFINITIVE_ERRORS, true) || in_array($exception->status, self::REJECTED_STATUSES, true);
            $this->halt($claim, $rejected ? EnhanceAccount::STATE_FAILED : EnhanceAccount::STATE_UNKNOWN, $exception->errorKey);
        }
    }

    /** @param Closure(): list<string> $read */
    private function assertAbsent(EnhanceAccount $claim, Closure $read): void
    {
        try {
            $absent = $read() === [];
        } catch (ServerProviderException) {
            $absent = false;
        }

        if (! $absent) {
            $this->halt($claim, EnhanceAccount::STATE_UNKNOWN, 'errors.unverified');
        }
    }

    private function halt(EnhanceAccount $claim, string $state, string $key): never
    {
        $this->transition($claim, self::PENDING_STATES, ['state' => $state]);

        throw $this->refusal($key);
    }

    /** The customer org Agovena created is deleted only when it holds nothing else. */
    private function removeCustomerOrg(EnhanceApi $api, EnhanceAccount $claim): void
    {
        $orgId = $claim->customer_org_id;
        if ($orgId === null) {
            return;
        }

        $this->remote(function () use ($api, $orgId): void {
            if (array_diff($api->subscriptionStatuses($orgId), ['deleted']) === []
                && array_diff($api->websiteStatuses($orgId), ['deleted']) === []
            ) {
                $api->deleteOrg($orgId);
            }
        });
        $this->transition($claim, [EnhanceAccount::STATE_TERMINATED], ['customer_org_id' => null]);
    }

    private function switchSuspension(ServiceInstanceInfo $instance, string $from, string $to): void
    {
        $connection = $this->connection($instance);
        $this->locked($instance->id, function () use ($instance, $connection, $from, $to): void {
            $claim = $this->ownedClaim($instance, $connection);
            if ($claim->state === $to) {
                return;
            }
            $this->assertState($claim, [$from]);

            $api = $this->enhance->withConnection($connection);
            $this->remote(fn () => $api->updateSubscription((string) $claim->customer_org_id, (int) $claim->remote_id, [
                'isSuspended' => $to === EnhanceAccount::STATE_SUSPENDED,
            ]));

            $this->transition($claim, [$from], ['state' => $to]);
        });
    }

    /** @param array{planId: int, subscriberId: string, vendorId: string, deleted: bool, suspended: bool} $subscription */
    private function ours(array $subscription, EnhanceAccount $claim, string $account): bool
    {
        return $subscription['subscriberId'] === $claim->customer_org_id && $subscription['vendorId'] === $account;
    }

    /**
     * Compare-and-swap on revision and state; a concurrent change is never overwritten.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $changes
     */
    private function transition(EnhanceAccount $claim, array $from, array $changes): void
    {
        $changes['revision'] = $claim->revision + 1;
        try {
            $updated = EnhanceAccount::query()
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

    /**
     * Serializes all lifecycle work per service; provider calls never run inside a database transaction.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(int $instanceId, Closure $callback): mixed
    {
        try {
            return Cache::lock('agovena:enhance:service:'.$instanceId, 120)->block(10, $callback);
        } catch (LockTimeoutException) {
            throw $this->refusal('errors.conflict');
        }
    }

    private function pending(EnhanceAccount $claim): bool
    {
        return str_starts_with($claim->remote_id, self::PENDING_PREFIX);
    }

    private function claim(int $instanceId): ?EnhanceAccount
    {
        return EnhanceAccount::query()->where('service_instance_id', $instanceId)->first();
    }

    /** @param array<string, mixed> $connection */
    private function ownedClaim(ServiceInstanceInfo $instance, array $connection): EnhanceAccount
    {
        $claim = $this->claim($instance->id) ?? throw $this->refusal('errors.not_provisioned');
        $this->assertEndpoint($claim, $this->endpoint($connection));

        return $claim;
    }

    private function assertEndpoint(EnhanceAccount $claim, string $endpoint): void
    {
        if ($claim->endpoint !== $endpoint) {
            throw $this->refusal('errors.endpoint_changed');
        }
    }

    /** @param list<string> $allowed */
    private function assertState(EnhanceAccount $claim, array $allowed): void
    {
        if (in_array($claim->state, $allowed, true)) {
            return;
        }

        throw $this->refusal(match ($claim->state) {
            EnhanceAccount::STATE_TERMINATED => 'errors.terminated',
            EnhanceAccount::STATE_CREATING, EnhanceAccount::STATE_UNKNOWN => 'errors.unverified',
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

    /** @return Order */
    private function order(ServiceInstanceInfo $instance): array
    {
        $settings = $instance->providerSettings ?? [];
        $plan = filter_var($settings['plan'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $domain = strtolower(trim((string) ($settings['domain'] ?? '')));
        if ($plan === false
            || ! str_contains($domain, '.')
            || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw $this->refusal('errors.invalid_mapping');
        }

        $owner = ServiceInstance::query()->find($instance->id);
        $name = trim((string) ($owner?->getAttribute('customer_name') ?: $owner?->getAttribute('customer_email')));

        return ['plan' => $plan, 'domain' => $domain, 'name' => mb_substr($name === '' ? $domain : $name, 0, 100)];
    }

    /** @param array<string, mixed> $connection */
    private function account(array $connection): string
    {
        $account = strtolower(trim((string) ($connection['account_id'] ?? '')));
        if (preg_match(EnhanceApi::UUID, $account) !== 1) {
            throw $this->refusal('errors.invalid_mapping');
        }

        return $account;
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
            return EnhanceEndpoint::normalize((string) ($connection['api_url'] ?? ''));
        } catch (ServerProviderException $exception) {
            throw $this->refusal($exception->errorKey);
        }
    }

    /**
     * A documented "not found" for an object Agovena created is never read as a
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
