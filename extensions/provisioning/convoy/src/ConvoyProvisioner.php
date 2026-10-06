<?php

declare(strict_types=1);

namespace Agovena\Extensions\Convoy;

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
 * Ownership of a Convoy server is proven only by a convoy_accounts claim row that
 * Agovena wrote before its own create calls. Lookups never adopt a server or user.
 *
 * @phpstan-import-type Limits from ConvoyApi
 * @phpstan-import-type Server from ConvoyApi
 *
 * @phpstan-type Order array{node: int, template: string, limits: Limits, customer: int|null, name: string, email: string}
 */
final class ConvoyProvisioner extends AbstractServerProvisioner
{
    private const PENDING_STATES = [ConvoyAccount::STATE_CREATING, ConvoyAccount::STATE_UNKNOWN];

    private const OWNED_STATES = [ConvoyAccount::STATE_ACTIVE, ConvoyAccount::STATE_SUSPENDED];

    /** Failures raised before any request was sent, or refused credentials: Convoy created nothing. */
    private const REJECTED_KEYS = ['errors.unauthorized', 'errors.demo_disabled', 'errors.not_configured', 'errors.invalid_mapping'];

    private const MIB = 1048576;

    private const GIB = 1073741824;

    public function __construct(ExtensionSettingsRepository $settings, private readonly ConvoyApi $convoy)
    {
        parent::__construct($settings, $convoy);
    }

    public function id(): string
    {
        return 'convoy';
    }

    public function label(): string
    {
        return __('convoy::messages.name');
    }

    /** @return list<ExtensionSettingDefinition> */
    public function serverSettings(): array
    {
        return [
            new ExtensionSettingDefinition('api_url', 'convoy::messages.settings.api_url', required: true, help: 'convoy::messages.settings.api_url_help'),
            new ExtensionSettingDefinition('api_token', 'convoy::messages.settings.api_token', secret: true, required: true, help: 'convoy::messages.settings.api_token_help'),
            new ExtensionSettingDefinition('verify_tls', 'convoy::messages.settings.verify_tls', type: 'boolean', default: true, help: 'convoy::messages.settings.verify_tls_help'),
            new ExtensionSettingDefinition('timeout', 'convoy::messages.settings.timeout', default: '20', help: 'convoy::messages.settings.timeout_help'),
        ];
    }

    /** @return list<ExtensionSettingDefinition> */
    public function productSettings(): array
    {
        $definitions = [];
        foreach (['node_id' => [true, null], 'template_uuid' => [true, null], 'cpu' => [true, null], 'memory_mb' => [true, null], 'disk_mb' => [true, null], 'bandwidth_gb' => [false, ''], 'snapshots' => [false, '0'], 'backups' => [false, '0']] as $key => [$required, $default]) {
            $definitions[] = new ExtensionSettingDefinition($key, 'convoy::messages.product.'.$key, required: $required, default: $default, help: 'convoy::messages.product.'.$key.'_help');
        }

        return $definitions;
    }

    public function provision(ServiceInstanceInfo $instance): void
    {
        $order = $this->order($instance);
        $connection = $this->connection($instance);
        $endpoint = $this->endpoint($connection);

        try {
            Cache::lock('agovena:convoy:provision:'.$instance->id, 120)
                ->block(10, fn () => $this->provisionLocked($instance, $connection, $endpoint, $order));
        } catch (LockTimeoutException) {
            throw $this->refusal('errors.conflict');
        }
    }

    public function suspend(ServiceInstanceInfo $instance): void
    {
        $this->switchSuspension($instance, ConvoyAccount::STATE_ACTIVE, ConvoyAccount::STATE_SUSPENDED);
    }

    public function unsuspend(ServiceInstanceInfo $instance): void
    {
        $this->switchSuspension($instance, ConvoyAccount::STATE_SUSPENDED, ConvoyAccount::STATE_ACTIVE);
    }

    /** @param string|array<string, mixed> $plan */
    public function changePlan(ServiceInstanceInfo $instance, string|array $plan): void
    {
        $providerSettings = is_array($plan) ? ($plan['provider_settings'] ?? null) : null;
        $target = $this->limits(is_array($providerSettings) ? $providerSettings : []);
        $connection = $this->connection($instance);
        $claim = $this->claim($instance->id) ?? throw $this->refusal('errors.not_provisioned');
        $this->assertEndpoint($claim, $this->endpoint($connection));
        $this->assertState($claim, self::OWNED_STATES);
        $encoded = json_encode($target, JSON_THROW_ON_ERROR);
        if ($claim->plan === $encoded) {
            return;
        }

        $api = $this->convoy->withConnection($connection);
        $this->remote(function () use ($api, $claim, $target): void {
            // Convoy detaches every address missing from address_ids, so the current ids are always re-sent.
            $before = $this->ownedServer($api, $claim);
            $api->updateBuild($claim->remote_id, $target, $before['addressIds']);

            // Convoy swallows hypervisor sync failures, so only a fresh read proves the change.
            $after = $this->ownedServer($api, $claim);
            if ($after['limits'] !== $target || $after['addressIds'] !== $before['addressIds']) {
                throw new ServerProviderException('errors.plan_unverified');
            }
        });

        $this->transition($claim, [$claim->state], ['plan' => $encoded]);
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
        if ($claim->state === ConvoyAccount::STATE_TERMINATED) {
            return;
        }

        // A rejected create left no server behind, so the claim closes without a remote call.
        if ($claim->state !== ConvoyAccount::STATE_FAILED) {
            $this->assertState($claim, self::OWNED_STATES);
            try {
                $this->convoy->withConnection($connection)->terminate($claim->remote_id);
            } catch (ServerProviderException $exception) {
                // A 404 for the uuid Agovena created proves that the server no longer exists.
                if ($exception->status !== 404) {
                    throw $this->refusal($exception->errorKey);
                }
            }
        }

        $this->transition($claim, [$claim->state], ['state' => ConvoyAccount::STATE_TERMINATED]);
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
        if ($claim->state === ConvoyAccount::STATE_TERMINATED) {
            return $this->info($instance, 'terminated', $claim->remote_id, []);
        }
        if ($claim->state === ConvoyAccount::STATE_FAILED) {
            return $this->info($instance, $instance->status, $instance->externalRef, ['provider_reconciliation' => 'absent']);
        }
        $this->assertState($claim, self::OWNED_STATES);

        $api = $this->convoy->withConnection($connection);
        $server = $this->remote(fn (): array => $this->ownedServer($api, $claim));
        [$state, $status] = match ($server['status']) {
            null => [ConvoyAccount::STATE_ACTIVE, 'active'],
            'suspended' => [ConvoyAccount::STATE_SUSPENDED, 'suspended'],
            'installing', 'restoring_backup', 'restoring_snapshot' => [$claim->state, 'provisioning'],
            'install_failed' => throw $this->refusal('errors.build_failed'),
            default => throw $this->refusal('errors.unverified'),
        };

        if ([$claim->state, $claim->remote_name] !== [$state, $server['hostname']]) {
            $this->transition($claim, [$claim->state], ['state' => $state, 'remote_name' => $server['hostname']]);
        }

        return $this->info($instance, $status, $claim->remote_id, [
            'provider_mapping' => ['provider_id' => $claim->remote_id, 'user_id' => $claim->user_id],
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
        ];
        if ($claim->remote_name !== null) {
            $fields[] = ['label' => $this->message('panel.hostname'), 'value' => $claim->remote_name];
        }

        return new ProvisionerPanelData($this->message('panel.title'), $fields);
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  Order  $order
     */
    private function provisionLocked(ServiceInstanceInfo $instance, array $connection, string $endpoint, array $order): void
    {
        $api = $this->convoy->withConnection($connection);
        $claim = $this->claim($instance->id);
        if ($claim === null) {
            $this->assertNoUnclaimedMapping($instance);
            $this->create($api, $this->insertClaim($instance->id, $endpoint, $order['limits']), $order);

            return;
        }

        $this->assertEndpoint($claim, $endpoint);
        if (in_array($claim->state, self::PENDING_STATES, true)) {
            // No uuid came back, so a create may have reached Convoy; it is never retried blindly.
            $this->halt($claim, ConvoyAccount::STATE_UNKNOWN, 'errors.unverified');
        }
        if ($claim->state === ConvoyAccount::STATE_FAILED) {
            $this->transition($claim, [ConvoyAccount::STATE_FAILED], [
                'state' => ConvoyAccount::STATE_CREATING,
                'plan' => json_encode($order['limits'], JSON_THROW_ON_ERROR),
            ]);
            $this->create($api, $claim, $order);

            return;
        }
        $this->assertState($claim, self::OWNED_STATES);
    }

    /** @param Limits $limits */
    private function insertClaim(int $instanceId, string $endpoint, array $limits): ConvoyAccount
    {
        try {
            return ConvoyAccount::query()->create([
                'service_instance_id' => $instanceId,
                'endpoint' => $endpoint,
                'remote_id' => 'pending:'.$instanceId,
                'plan' => json_encode($limits, JSON_THROW_ON_ERROR),
                'state' => ConvoyAccount::STATE_CREATING,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->refusal('errors.claimed');
        }
    }

    /**
     * Generated passwords are sent once and never stored; the customer resets them in Convoy.
     *
     * @param  Order  $order
     */
    private function create(ConvoyApi $api, ConvoyAccount $claim, array $order): void
    {
        if ($claim->user_id === null) {
            $userId = $this->customerUserId($claim, $order['customer'])
                ?? $this->attempt($claim, fn (): int => $api->createUser($order['name'], $order['email'], Str::password(32)));
            $this->transition($claim, [ConvoyAccount::STATE_CREATING], ['user_id' => $userId]);
        }

        $name = 'agovena-'.$claim->service_instance_id;
        $server = $this->attempt($claim, fn (): array => $api->createServer([
            'name' => $name,
            'user_id' => $claim->user_id,
            'node_id' => $order['node'],
            'vmid' => null,
            'hostname' => $name,
            'limits' => $order['limits'],
            'account_password' => Str::password(32),
            'should_create_server' => true,
            'template_uuid' => $order['template'],
            'start_on_completion' => true,
        ]));
        if ($server['userId'] !== $claim->user_id) {
            $this->halt($claim, ConvoyAccount::STATE_UNKNOWN, 'errors.unverified');
        }

        $this->transition($claim, [ConvoyAccount::STATE_CREATING], [
            'state' => ConvoyAccount::STATE_ACTIVE,
            'remote_id' => $server['uuid'],
            'remote_name' => $server['hostname'],
        ]);
    }

    /**
     * Runs one create call. Only a 422 validation answer or a failure before sending
     * proves that nothing was created; any other failure leaves the outcome unknown.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function attempt(ConvoyAccount $claim, Closure $call): mixed
    {
        try {
            return $call();
        } catch (ServerProviderException $exception) {
            $rejected = in_array($exception->errorKey, self::REJECTED_KEYS, true) || $exception->status === 422;
            $this->halt($claim, $rejected ? ConvoyAccount::STATE_FAILED : ConvoyAccount::STATE_UNKNOWN, $exception->errorKey);
        }
    }

    private function halt(ConvoyAccount $claim, string $state, string $key): never
    {
        $this->transition($claim, self::PENDING_STATES, ['state' => $state]);

        throw $this->refusal($key);
    }

    /** Convoy emails are unique, so a customer keeps the user that an earlier Agovena create recorded. */
    private function customerUserId(ConvoyAccount $claim, ?int $customerId): ?int
    {
        if ($customerId === null) {
            return null;
        }

        $userId = ConvoyAccount::query()
            ->where('endpoint', $claim->endpoint)
            ->whereNotNull('user_id')
            ->whereIn('service_instance_id', ServiceInstance::query()->select('id')->where('customer_id', $customerId))
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }

    /** @return Server */
    private function ownedServer(ConvoyApi $api, ConvoyAccount $claim): array
    {
        $server = $api->getServer($claim->remote_id);
        if ($server['userId'] !== $claim->user_id) {
            throw new ServerProviderException('errors.unverified');
        }

        return $server;
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

        $api = $this->convoy->withConnection($connection);
        $this->remote(function () use ($api, $claim, $to): void {
            if ($to === ConvoyAccount::STATE_SUSPENDED) {
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
    private function transition(ConvoyAccount $claim, array $from, array $changes): void
    {
        $changes['revision'] = $claim->revision + 1;
        try {
            $updated = ConvoyAccount::query()
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

    private function claim(int $instanceId): ?ConvoyAccount
    {
        return ConvoyAccount::query()->where('service_instance_id', $instanceId)->first();
    }

    private function assertEndpoint(ConvoyAccount $claim, string $endpoint): void
    {
        if ($claim->endpoint !== $endpoint) {
            throw $this->refusal('errors.endpoint_changed');
        }
    }

    /** @param list<string> $allowed */
    private function assertState(ConvoyAccount $claim, array $allowed): void
    {
        if (in_array($claim->state, $allowed, true)) {
            return;
        }

        throw $this->refusal(match ($claim->state) {
            ConvoyAccount::STATE_TERMINATED => 'errors.terminated',
            ConvoyAccount::STATE_CREATING, ConvoyAccount::STATE_UNKNOWN => 'errors.unverified',
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
        $node = $this->integer($settings['node_id'] ?? null, 1);
        $template = trim((string) ($settings['template_uuid'] ?? ''));
        if (! Str::isUuid($template)) {
            throw $this->refusal('errors.invalid_mapping');
        }
        $limits = $this->limits($settings);

        $owner = ServiceInstance::query()->find($instance->id);
        $email = trim((string) $owner?->getAttribute('customer_email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw $this->refusal('errors.invalid_mapping');
        }
        $name = trim((string) $owner?->getAttribute('customer_name'));
        $customerId = $owner?->getAttribute('customer_id');

        return [
            'node' => $node,
            'template' => $template,
            'limits' => $limits,
            'customer' => is_numeric($customerId) ? (int) $customerId : null,
            'name' => $name === '' ? $email : $name,
            'email' => $email,
        ];
    }

    /**
     * Product resource settings in MiB and GiB, converted to the bytes Convoy expects.
     * An empty bandwidth means unlimited.
     *
     * @param  array<string, mixed>  $settings
     * @return Limits
     */
    private function limits(array $settings): array
    {
        $bandwidth = trim((string) ($settings['bandwidth_gb'] ?? ''));

        return [
            'cpu' => $this->integer($settings['cpu'] ?? null, 1),
            'memory' => $this->integer($settings['memory_mb'] ?? null, 16) * self::MIB,
            'disk' => $this->integer($settings['disk_mb'] ?? null, 1) * self::MIB,
            'snapshots' => $this->integer($settings['snapshots'] ?? null, 0),
            'backups' => $this->integer($settings['backups'] ?? null, 0),
            'bandwidth' => $bandwidth === '' ? null : $this->integer($bandwidth, 1) * self::GIB,
        ];
    }

    private function integer(mixed $value, int $min): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => intdiv(PHP_INT_MAX, self::GIB)]]);
        if ($integer === false) {
            throw $this->refusal('errors.invalid_mapping');
        }

        return $integer;
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
            return ConvoyEndpoint::normalize((string) ($connection['api_url'] ?? ''));
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
