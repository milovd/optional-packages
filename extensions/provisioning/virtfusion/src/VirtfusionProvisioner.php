<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtfusion;

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
 * Ownership of a VirtFusion server is proven only by a virtfusion_accounts claim row
 * that Agovena wrote before its own create calls. Lookups never adopt a server or user.
 *
 * @phpstan-type Order array{package: int, group: int, template: int, customer: int|null, name: string, email: string}
 */
final class VirtfusionProvisioner extends AbstractServerProvisioner
{
    private const PENDING_STATES = [VirtfusionAccount::STATE_CREATING, VirtfusionAccount::STATE_UNKNOWN];

    private const OWNED_STATES = [VirtfusionAccount::STATE_ACTIVE, VirtfusionAccount::STATE_SUSPENDED];

    /** Documented rejections after which VirtFusion created nothing (POST /users, POST /servers). */
    private const REJECTED_STATUSES = [409, 422];

    public function __construct(ExtensionSettingsRepository $settings, private readonly VirtfusionApi $virtfusion)
    {
        parent::__construct($settings, $virtfusion);
    }

    public function id(): string
    {
        return 'virtfusion';
    }

    public function label(): string
    {
        return __('virtfusion::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'virtfusion::messages.settings.api_url', required: true, help: 'virtfusion::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'virtfusion::messages.settings.api_token', secret: true, required: true, help: 'virtfusion::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('verify_tls', 'virtfusion::messages.settings.verify_tls', type: 'boolean', default: true, help: 'virtfusion::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'virtfusion::messages.settings.timeout', default: '20', help: 'virtfusion::messages.settings.timeout_help'),
        ];
    }

    /** @return list<ExtensionSettingDefinition> */
    public function productSettings(): array
    {
        return [
            new ExtensionSettingDefinition('package_id', 'virtfusion::messages.product.package_id', required: true, help: 'virtfusion::messages.product.package_id_help'),
            new ExtensionSettingDefinition('hypervisor_group_id', 'virtfusion::messages.product.hypervisor_group_id', required: true, help: 'virtfusion::messages.product.hypervisor_group_id_help'),
            new ExtensionSettingDefinition('template_id', 'virtfusion::messages.product.template_id', required: true, help: 'virtfusion::messages.product.template_id_help'),
        ];
    }

    public function provision(ServiceInstanceInfo $instance): void
    {
        $order = $this->order($instance);
        $connection = $this->connection($instance);
        $endpoint = $this->endpoint($connection);

        try {
            Cache::lock('agovena:virtfusion:provision:'.$instance->id, 120)
                ->block(10, fn () => $this->provisionLocked($instance, $connection, $endpoint, $order));
        } catch (LockTimeoutException) {
            throw $this->refusal('errors.conflict');
        }
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        $this->switchSuspension($instance, VirtfusionAccount::STATE_ACTIVE, VirtfusionAccount::STATE_SUSPENDED);
    }

    public function unsuspend(ServiceInstanceInfo $instance): void
    {
        $this->switchSuspension($instance, VirtfusionAccount::STATE_SUSPENDED, VirtfusionAccount::STATE_ACTIVE);
    }

    /** @param string|array<string, mixed> $plan */
    public function changePlan(ServiceInstanceInfo $instance, string|array $plan): void
    {
        $providerSettings = is_array($plan) ? ($plan['provider_settings'] ?? null) : null;
        $target = filter_var(is_array($providerSettings) ? ($providerSettings['package_id'] ?? null) : null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
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

        $api = $this->virtfusion->withConnection($connection);
        $this->remote(function () use ($api, $claim, $target): void {
            if (! in_array($target, $api->enabledPackageIds(), true)) {
                throw new ServerProviderException('errors.package_unavailable');
            }
            $api->changePackage($claim->remote_id, $target);
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
        if ($claim->state === VirtfusionAccount::STATE_TERMINATED) {
            return;
        }

        // A rejected create left no server behind, so the claim closes without a remote call.
        if ($claim->state !== VirtfusionAccount::STATE_FAILED) {
            $this->assertState($claim, self::OWNED_STATES);
            try {
                $this->virtfusion->withConnection($connection)->terminate($claim->remote_id);
            } catch (ServerProviderException $exception) {
                // The documented 404 proves that the server id Agovena created no longer exists.
                if ($exception->status !== 404) {
                    throw $this->refusal($exception->errorKey);
                }
            }
        }

        $this->transition($claim, [$claim->state], ['state' => VirtfusionAccount::STATE_TERMINATED]);
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
        if ($claim->state === VirtfusionAccount::STATE_TERMINATED) {
            return $this->info($instance, 'terminated', $claim->remote_id, []);
        }
        if ($claim->state === VirtfusionAccount::STATE_FAILED) {
            return $this->info($instance, $instance->status, $instance->externalRef, ['provider_reconciliation' => 'absent']);
        }
        $this->assertState($claim, self::OWNED_STATES);

        $api = $this->virtfusion->withConnection($connection);
        $server = $this->remote(fn (): array => $api->getServer($claim->remote_id));
        if ($server['ownerId'] !== $claim->user_id) {
            throw $this->refusal('errors.unverified');
        }
        if ($server['buildFailed']) {
            throw $this->refusal('errors.build_failed');
        }

        $state = $server['suspended'] ? VirtfusionAccount::STATE_SUSPENDED : VirtfusionAccount::STATE_ACTIVE;
        if ([$claim->state, $claim->remote_name] !== [$state, $server['name']]) {
            $this->transition($claim, [$claim->state], ['state' => $state, 'remote_name' => $server['name']]);
        }

        return $this->info($instance, $state === VirtfusionAccount::STATE_ACTIVE && $server['built'] === null ? 'provisioning' : $state, $claim->remote_id, [
            'provider_mapping' => ['provider_id' => $claim->remote_id, 'user_id' => $claim->user_id, 'package_id' => $claim->plan],
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
            ['label' => $this->message('panel.server_id'), 'value' => $claim->remote_id],
            ['label' => $this->message('panel.package'), 'value' => (string) $claim->plan],
        ];
        if ($claim->remote_name !== null) {
            $fields[] = ['label' => $this->message('panel.name'), 'value' => $claim->remote_name];
        }

        return new ProvisionerPanelData($this->message('panel.title'), $fields);
    }

    /** @return list<string> */
    protected function requiredConnectionKeys(): array
    {
        return ['api_url', 'api_token'];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  Order  $order
     */
    private function provisionLocked(ServiceInstanceInfo $instance, array $connection, string $endpoint, array $order): void
    {
        $api = $this->virtfusion->withConnection($connection);
        $claim = $this->claim($instance->id);
        if ($claim === null) {
            $this->assertNoUnclaimedMapping($instance);
            $this->create($api, $this->insertClaim($instance->id, $endpoint, $order['package']), $order);

            return;
        }

        $this->assertEndpoint($claim, $endpoint);
        if (in_array($claim->state, self::PENDING_STATES, true)) {
            $this->reconcile($api, $claim, $order);
        } elseif ($claim->state === VirtfusionAccount::STATE_FAILED) {
            $this->transition($claim, [VirtfusionAccount::STATE_FAILED], [
                'state' => VirtfusionAccount::STATE_CREATING,
                'plan' => (string) $order['package'],
            ]);
            $this->create($api, $claim, $order);
        } else {
            $this->assertState($claim, self::OWNED_STATES);
        }
    }

    private function insertClaim(int $instanceId, string $endpoint, int $package): VirtfusionAccount
    {
        try {
            return VirtfusionAccount::query()->create([
                'service_instance_id' => $instanceId,
                'endpoint' => $endpoint,
                'remote_id' => 'pending:'.$instanceId,
                'user_relation' => 'agovena-'.base_convert((string) $instanceId, 10, 36).'-'.Str::lower(Str::random(16)),
                'plan' => (string) $package,
                'state' => VirtfusionAccount::STATE_CREATING,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->refusal('errors.claimed');
        }
    }

    /** @param Order $order */
    private function create(VirtfusionApi $api, VirtfusionAccount $claim, array $order): void
    {
        try {
            if (! in_array($order['package'], $api->enabledPackageIds(), true)) {
                throw new ServerProviderException('errors.package_unavailable');
            }
        } catch (ServerProviderException $exception) {
            $this->halt($claim, VirtfusionAccount::STATE_FAILED, $exception->errorKey);
        }

        if ($claim->user_id === null) {
            $userId = $this->customerUserId($claim, $order['customer']);
            if ($userId !== null) {
                $this->transition($claim, [VirtfusionAccount::STATE_CREATING], ['user_id' => $userId, 'user_relation' => null]);
            } else {
                $relation = $claim->user_relation ?? throw $this->refusal('errors.unverified');
                $userId = $this->attempt($claim, fn (): int => $api->createUser($order['name'], $order['email'], $relation));
                $this->transition($claim, [VirtfusionAccount::STATE_CREATING], ['user_id' => $userId]);
            }
        }

        $server = $this->attempt($claim, fn (): array => $api->createServer([
            'packageId' => $order['package'],
            'userId' => $claim->user_id,
            'hypervisorId' => $order['group'],
        ]));
        if ($server['ownerId'] !== $claim->user_id) {
            $this->halt($claim, VirtfusionAccount::STATE_UNKNOWN, 'errors.unverified');
        }

        $this->transition($claim, [VirtfusionAccount::STATE_CREATING], [
            'remote_id' => (string) $server['id'],
            'remote_name' => $server['name'],
        ]);
        $this->build($api, $claim, $order['template']);
    }

    /**
     * An interrupted create is resolved only through identifiers Agovena recorded: the
     * server id returned to it, or the unguessable relation string of its own user.
     *
     * @param  Order  $order
     */
    private function reconcile(VirtfusionApi $api, VirtfusionAccount $claim, array $order): void
    {
        if (ctype_digit($claim->remote_id)) {
            $server = $this->lookup($claim, fn (): array => $api->getServer($claim->remote_id));
            if ($server['ownerId'] !== $claim->user_id) {
                $this->halt($claim, VirtfusionAccount::STATE_UNKNOWN, 'errors.unverified');
            }
            if ($server['built'] === null) {
                $this->build($api, $claim, $order['template']);
            } else {
                $this->transition($claim, self::PENDING_STATES, ['state' => VirtfusionAccount::STATE_ACTIVE]);
            }

            return;
        }

        // The server create may have reached VirtFusion without returning an id; never retry it blindly.
        $relation = $claim->user_relation;
        if ($claim->user_id !== null || $relation === null) {
            $this->halt($claim, VirtfusionAccount::STATE_UNKNOWN, 'errors.unverified');
        }

        $userId = $this->lookup($claim, fn (): int => $api->userIdByRelation($relation));
        $this->transition($claim, self::PENDING_STATES, ['state' => VirtfusionAccount::STATE_CREATING, 'user_id' => $userId]);
        $this->create($api, $claim, $order);
    }

    private function build(VirtfusionApi $api, VirtfusionAccount $claim, int $template): void
    {
        try {
            $api->buildServer($claim->remote_id, $template);
        } catch (ServerProviderException $exception) {
            $this->halt($claim, VirtfusionAccount::STATE_UNKNOWN, $exception->errorKey);
        }

        $this->transition($claim, self::PENDING_STATES, ['state' => VirtfusionAccount::STATE_ACTIVE]);
    }

    /**
     * Runs one create call. Only refused credentials or a documented rejection prove
     * that nothing was created; any other failure leaves the outcome unknown.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function attempt(VirtfusionAccount $claim, Closure $call): mixed
    {
        try {
            return $call();
        } catch (ServerProviderException $exception) {
            $rejected = $exception->errorKey === 'errors.unauthorized' || in_array($exception->status, self::REJECTED_STATUSES, true);
            $this->halt($claim, $rejected ? VirtfusionAccount::STATE_FAILED : VirtfusionAccount::STATE_UNKNOWN, $exception->errorKey);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    private function lookup(VirtfusionAccount $claim, Closure $read): mixed
    {
        try {
            return $read();
        } catch (ServerProviderException) {
            $this->halt($claim, VirtfusionAccount::STATE_UNKNOWN, 'errors.unverified');
        }
    }

    private function halt(VirtfusionAccount $claim, string $state, string $key): never
    {
        $this->transition($claim, self::PENDING_STATES, ['state' => $state]);

        throw $this->refusal($key);
    }

    /** VirtFusion emails are unique, so a customer keeps the user that an earlier Agovena create recorded. */
    private function customerUserId(VirtfusionAccount $claim, ?int $customerId): ?int
    {
        if ($customerId === null) {
            return null;
        }

        $userId = VirtfusionAccount::query()
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

        $api = $this->virtfusion->withConnection($connection);
        $this->remote(function () use ($api, $claim, $to): void {
            if ($to === VirtfusionAccount::STATE_SUSPENDED) {
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
    private function transition(VirtfusionAccount $claim, array $from, array $changes): void
    {
        $changes['revision'] = $claim->revision + 1;
        try {
            $updated = VirtfusionAccount::query()
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

    private function claim(int $instanceId): ?VirtfusionAccount
    {
        return VirtfusionAccount::query()->where('service_instance_id', $instanceId)->first();
    }

    private function assertEndpoint(VirtfusionAccount $claim, string $endpoint): void
    {
        if ($claim->endpoint !== $endpoint) {
            throw $this->refusal('errors.endpoint_changed');
        }
    }

    /** @param list<string> $allowed */
    private function assertState(VirtfusionAccount $claim, array $allowed): void
    {
        if (in_array($claim->state, $allowed, true)) {
            return;
        }

        throw $this->refusal(match ($claim->state) {
            VirtfusionAccount::STATE_TERMINATED => 'errors.terminated',
            VirtfusionAccount::STATE_CREATING, VirtfusionAccount::STATE_UNKNOWN => 'errors.unverified',
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
        foreach (['package' => 'package_id', 'group' => 'hypervisor_group_id', 'template' => 'template_id'] as $name => $key) {
            $id = filter_var($settings[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
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
        $name = trim((string) $owner?->getAttribute('customer_name'));
        $customerId = $owner?->getAttribute('customer_id');

        return [
            'package' => $ids['package'],
            'group' => $ids['group'],
            'template' => $ids['template'],
            'customer' => is_numeric($customerId) ? (int) $customerId : null,
            'name' => $name === '' ? $email : $name,
            'email' => $email,
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
            return VirtfusionEndpoint::normalize((string) ($connection['api_url'] ?? ''));
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
