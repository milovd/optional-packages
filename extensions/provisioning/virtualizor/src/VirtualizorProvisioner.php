<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtualizor;

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
 * Ownership of a Virtualizor VPS is proven only by a virtualizor_accounts claim row
 * that Agovena wrote before its own create call. Lookups never adopt a VPS or user.
 *
 * @phpstan-import-type Vps from VirtualizorApi
 *
 * @phpstan-type Order array{plan: int, os: int, group: int, customer: int|null, email: string, first_name: string, last_name: string}
 */
final class VirtualizorProvisioner extends AbstractServerProvisioner
{
    private const PENDING_STATES = [VirtualizorAccount::STATE_CREATING, VirtualizorAccount::STATE_UNKNOWN];

    private const OWNED_STATES = [VirtualizorAccount::STATE_ACTIVE, VirtualizorAccount::STATE_SUSPENDED];

    /** Failures that prove the create call created nothing. */
    private const REJECTIONS = ['errors.unauthorized', 'errors.provider_rejected'];

    public function __construct(ExtensionSettingsRepository $settings, private readonly VirtualizorApi $virtualizor)
    {
        parent::__construct($settings, $virtualizor);
    }

    public function id(): string
    {
        return 'virtualizor';
    }

    public function label(): string
    {
        return __('virtualizor::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'virtualizor::messages.settings.api_url', required: true, help: 'virtualizor::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'virtualizor::messages.settings.api_token', secret: true, required: true, help: 'virtualizor::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('api_secret', 'virtualizor::messages.settings.api_secret', secret: true, required: true, help: 'virtualizor::messages.settings.api_secret_help'),
            new ExtensionSettingDefinition('verify_tls', 'virtualizor::messages.settings.verify_tls', type: 'boolean', default: true, help: 'virtualizor::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'virtualizor::messages.settings.timeout', default: '20', help: 'virtualizor::messages.settings.timeout_help'),
        ];
    }

    /** @return list<ExtensionSettingDefinition> */
    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('plan_id', 'virtualizor::messages.product.plan_id', required: true, help: 'virtualizor::messages.product.plan_id_help'),
            new ExtensionSettingDefinition('os_id', 'virtualizor::messages.product.os_id', required: true, help: 'virtualizor::messages.product.os_id_help'),
            new ExtensionSettingDefinition('server_group', 'virtualizor::messages.product.server_group', required: true, help: 'virtualizor::messages.product.server_group_help'),
        ];
    }

    public function provision(ServiceInstanceInfo $instance): void
    {
        $order = $this->order($instance);
        $connection = $this->connection($instance);
        $endpoint = $this->endpoint($connection);

        try {
            Cache::lock('agovena:virtualizor:provision:'.$instance->id, 120)
                ->block(10, fn () => $this->provisionLocked($instance, $connection, $endpoint, $order));
        } catch (LockTimeoutException) {
            throw $this->refusal('errors.conflict');
        }
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        $this->switchSuspension($instance, VirtualizorAccount::STATE_ACTIVE, VirtualizorAccount::STATE_SUSPENDED);
    }

    public function unsuspend(ServiceInstanceInfo $instance): void
    {
        $this->switchSuspension($instance, VirtualizorAccount::STATE_SUSPENDED, VirtualizorAccount::STATE_ACTIVE);
    }

    /** @param string|array<string, mixed> $plan */
    public function changePlan(ServiceInstanceInfo $instance, string|array $plan): void
    {
        $providerSettings = is_array($plan) ? ($plan['provider_settings'] ?? null) : null;
        $target = filter_var(is_array($providerSettings) ? ($providerSettings['plan_id'] ?? null) : null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($target === false) {
            throw $this->refusal('errors.invalid_mapping');
        }

        $connection = $this->connection($instance);
        $claim = $this->claim($instance->id) ?? throw $this->refusal('errors.not_provisioned');
        $this->assertEndpoint($claim, $this->endpoint($connection));
        $this->assertState($claim, self::OWNED_STATES);
        if ($claim->plan === (string) $target) {
            return;
        }

        $api = $this->virtualizor->withConnection($connection);
        $this->remote(function () use ($api, $claim, $target): void {
            $plan = $api->plans()[$target] ?? null;
            if ($plan === null || ! $plan['enabled'] || $plan['virt'] !== $this->ownedVps($api, $claim)['virt']) {
                throw new ServerProviderException('errors.plan_unavailable');
            }
            $api->applyPlan($claim->remote_id, $target);
            if ($this->ownedVps($api, $claim)['plid'] !== $target) {
                throw new ServerProviderException('errors.plan_not_applied');
            }
        });

        $this->transition($claim, [$claim->state], ['plan' => (string) $target]);
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
        if ($claim->state === VirtualizorAccount::STATE_TERMINATED) {
            return;
        }

        // A rejected create left no VPS behind, so the claim closes without a remote call.
        if ($claim->state !== VirtualizorAccount::STATE_FAILED) {
            $this->assertState($claim, self::OWNED_STATES);
            $api = $this->virtualizor->withConnection($connection);
            $this->remote(function () use ($api, $claim): void {
                $this->ownedVps($api, $claim);
                $api->deleteVps($claim->remote_id);
            });
        }

        $this->transition($claim, [$claim->state], ['state' => VirtualizorAccount::STATE_TERMINATED]);
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
        if ($claim->state === VirtualizorAccount::STATE_TERMINATED) {
            return $this->info($instance, 'terminated', $claim->remote_id, []);
        }
        if ($claim->state === VirtualizorAccount::STATE_FAILED) {
            return $this->info($instance, $instance->status, $instance->externalRef, ['provider_reconciliation' => 'absent']);
        }
        $this->assertState($claim, self::OWNED_STATES);

        $api = $this->virtualizor->withConnection($connection);
        $vps = $this->remote(fn (): array => $this->ownedVps($api, $claim));
        $state = $vps['suspended'] ? VirtualizorAccount::STATE_SUSPENDED : VirtualizorAccount::STATE_ACTIVE;
        if ([$claim->state, $claim->remote_name] !== [$state, $vps['hostname']]) {
            $this->transition($claim, [$claim->state], ['state' => $state, 'remote_name' => $vps['hostname']]);
        }

        return $this->info($instance, $state, $claim->remote_id, [
            'provider_mapping' => ['provider_id' => $claim->remote_id, 'user_id' => $claim->user_id, 'plan_id' => $claim->plan],
        ]);
    }

    public function panel(ServiceInstanceInfo $instance): ?ProvisionerPanelData
    {
        $claim = $this->claim($instance->id);
        if ($claim === null || ! in_array($claim->state, self::OWNED_STATES, true)) {
            return null;
        }

        $fields = [
            ['label' => $this->message('panel.status'), 'value' => $this->message('status.'.$claim->state)],
            ['label' => $this->message('panel.vps_id'), 'value' => $claim->remote_id],
            ['label' => $this->message('panel.plan'), 'value' => (string) $claim->plan],
        ];
        if ($claim->remote_name !== null) {
            $fields[] = ['label' => $this->message('panel.hostname'), 'value' => $claim->remote_name];
        }

        return new ProvisionerPanelData($this->message('panel.title'), $fields);
    }

    /** @return list<string> */
    protected function requiredConnectionKeys(): array
    {
        return ['api_url', 'api_token', 'api_secret'];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  Order  $order
     */
    private function provisionLocked(ServiceInstanceInfo $instance, array $connection, string $endpoint, array $order): void
    {
        $api = $this->virtualizor->withConnection($connection);
        $claim = $this->claim($instance->id);
        if ($claim === null) {
            $this->assertNoUnclaimedMapping($instance);
            $this->create($api, $this->insertClaim($instance->id, $endpoint, $order['plan']), $order);

            return;
        }

        $this->assertEndpoint($claim, $endpoint);
        if (in_array($claim->state, self::PENDING_STATES, true)) {
            // No vpsid was recorded, so the create may have reached Virtualizor: never retry it blindly.
            $this->halt($claim, VirtualizorAccount::STATE_UNKNOWN, 'errors.unverified');
        } elseif ($claim->state === VirtualizorAccount::STATE_FAILED) {
            $this->transition($claim, [VirtualizorAccount::STATE_FAILED], [
                'state' => VirtualizorAccount::STATE_CREATING,
                'plan' => (string) $order['plan'],
            ]);
            $this->create($api, $claim, $order);
        } else {
            $this->assertState($claim, self::OWNED_STATES);
        }
    }

    private function insertClaim(int $instanceId, string $endpoint, int $plan): VirtualizorAccount
    {
        try {
            return VirtualizorAccount::query()->create([
                'service_instance_id' => $instanceId,
                'endpoint' => $endpoint,
                'remote_id' => 'pending:'.$instanceId,
                'plan' => (string) $plan,
                'state' => VirtualizorAccount::STATE_CREATING,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->refusal('errors.claimed');
        }
    }

    /** @param Order $order */
    private function create(VirtualizorApi $api, VirtualizorAccount $claim, array $order): void
    {
        $userId = $this->customerUserId($claim, $order['customer']);
        try {
            $plan = $api->plans()[$order['plan']] ?? null;
            if ($plan === null || ! $plan['enabled']) {
                throw new ServerProviderException('errors.plan_unavailable');
            }
            // addvs attaches a VPS to an existing user with the same email, which would adopt a foreign user.
            if ($userId === null && $api->userIdsByEmail($order['email']) !== []) {
                throw new ServerProviderException('errors.user_exists');
            }
        } catch (ServerProviderException $exception) {
            $this->halt($claim, VirtualizorAccount::STATE_FAILED, $exception->errorKey);
        }

        $form = [
            'addvps' => '1',
            'virt' => $plan['virt'],
            'plid' => (string) $order['plan'],
            'osid' => (string) $order['os'],
            'node_select' => '1',
            'server_group' => (string) $order['group'],
            'hostname' => 'agovena-'.$claim->service_instance_id,
            'rootpass' => Str::password(24, symbols: false),
            'user_email' => $order['email'],
            'user_pass' => Str::password(24, symbols: false),
            'fname' => $order['first_name'],
            'lname' => $order['last_name'],
            'num_ips' => (string) $plan['ips'],
            'space' => (string) $plan['space'],
            'ram' => (string) $plan['ram'],
            'bandwidth' => (string) $plan['bandwidth'],
            'cores' => (string) $plan['cores'],
        ];
        if ($userId !== null) {
            $form['uid'] = (string) $userId;
        }

        try {
            $vps = $api->createVps($form);
        } catch (ServerProviderException $exception) {
            $rejected = in_array($exception->errorKey, self::REJECTIONS, true);
            $this->halt($claim, $rejected ? VirtualizorAccount::STATE_FAILED : VirtualizorAccount::STATE_UNKNOWN, $exception->errorKey);
        }
        if ($userId !== null && $vps['uid'] !== $userId) {
            $this->halt($claim, VirtualizorAccount::STATE_UNKNOWN, 'errors.unverified');
        }

        $this->transition($claim, [VirtualizorAccount::STATE_CREATING], [
            'state' => VirtualizorAccount::STATE_ACTIVE,
            'remote_id' => $vps['vpsid'],
            'remote_name' => $vps['hostname'],
            'user_id' => $vps['uid'],
        ]);
    }

    /** @return Vps */
    private function ownedVps(VirtualizorApi $api, VirtualizorAccount $claim): array
    {
        $vps = $api->vps($claim->remote_id);
        if ($vps['uid'] !== $claim->user_id) {
            throw new ServerProviderException('errors.unverified');
        }

        return $vps;
    }

    private function halt(VirtualizorAccount $claim, string $state, string $key): never
    {
        $this->transition($claim, self::PENDING_STATES, ['state' => $state]);

        throw $this->refusal($key);
    }

    /** A customer keeps the Virtualizor user that an earlier Agovena create on this panel returned. */
    private function customerUserId(VirtualizorAccount $claim, ?int $customerId): ?int
    {
        if ($customerId === null) {
            return null;
        }

        $userId = VirtualizorAccount::query()
            ->where('endpoint', $claim->endpoint)
            ->whereNotNull('user_id')
            ->whereIn('service_instance_id', ServiceInstance::query()->select('id')->where('customer_id', $customerId))
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }

    private function switchSuspension(ServiceInstanceInfo $instance, string $from, string $to): void
    {
        $connection = $this->connection($instance);
        $claim = $this->claim($instance->id) ?? throw $this->refusal('errors.not_provisioned');
        $this->assertEndpoint($claim, $this->endpoint($connection));
        if ($claim->state === $to) {
            return;
        }
        $this->assertState($claim, [$from]);

        $api = $this->virtualizor->withConnection($connection);
        $this->remote(function () use ($api, $claim, $to): void {
            if ($to === VirtualizorAccount::STATE_SUSPENDED) {
                $api->suspendVps($claim->remote_id);
            } else {
                $api->unsuspendVps($claim->remote_id);
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
    private function transition(VirtualizorAccount $claim, array $from, array $changes): void
    {
        $changes['revision'] = $claim->revision + 1;
        try {
            $updated = VirtualizorAccount::query()
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

    private function claim(int $instanceId): ?VirtualizorAccount
    {
        return VirtualizorAccount::query()->where('service_instance_id', $instanceId)->first();
    }

    private function assertEndpoint(VirtualizorAccount $claim, string $endpoint): void
    {
        if ($claim->endpoint !== $endpoint) {
            throw $this->refusal('errors.endpoint_changed');
        }
    }

    /** @param list<string> $allowed */
    private function assertState(VirtualizorAccount $claim, array $allowed): void
    {
        if (in_array($claim->state, $allowed, true)) {
            return;
        }

        throw $this->refusal(match ($claim->state) {
            VirtualizorAccount::STATE_TERMINATED => 'errors.terminated',
            VirtualizorAccount::STATE_CREATING, VirtualizorAccount::STATE_UNKNOWN => 'errors.unverified',
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
        $ids = [];
        foreach (['plan' => ['plan_id', 1], 'os' => ['os_id', 1], 'group' => ['server_group', 0]] as $name => [$key, $min]) {
            $id = filter_var($settings[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]]);
            if ($id === false) {
                throw $this->refusal('errors.invalid_mapping');
            }
            $ids[$name] = $id;
        }

        $owner = ServiceInstance::query()->find($instance->id);
        $email = trim((string) $owner?->getAttribute('customer_email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw $this->refusal('errors.invalid_mapping');
        }
        $names = explode(' ', trim((string) $owner?->getAttribute('customer_name')), 2);
        $customerId = $owner?->getAttribute('customer_id');

        return [
            'plan' => $ids['plan'],
            'os' => $ids['os'],
            'group' => $ids['group'],
            'customer' => is_numeric($customerId) ? (int) $customerId : null,
            'email' => $email,
            'first_name' => $names[0],
            'last_name' => $names[1] ?? '',
        ];
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
            return VirtualizorEndpoint::normalize((string) ($connection['api_url'] ?? ''));
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
