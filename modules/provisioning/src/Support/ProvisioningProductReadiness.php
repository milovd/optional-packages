<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Support;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Provisioning\Contracts\ConfiguresProvisioningServers;
use App\Agovena\Provisioning\ProvisionerRegistry;
use App\Models\ProvisioningServer;

/**
 * Decides whether a provisionable product can be ordered right now, without network I/O.
 *
 * A product stays visible on the storefront either way. It is orderable only when its
 * provider Extension is enabled and the connection the product will use has every
 * required setting filled in: the selected provisioning server, or the Extension
 * settings when no server is selected. Live reachability and capacity are still
 * checked at checkout before payment.
 */
final class ProvisioningProductReadiness
{
    public function __construct(
        private readonly ProvisionerRegistry $provisioners,
        private readonly ExtensionSettingsRepository $settings,
    ) {}

    /** @param array<string, mixed> $config */
    public function isOrderable(array $config): bool
    {
        $providerKey = $config['provider_key'] ?? null;
        if (! is_string($providerKey) || trim($providerKey) === '') {
            return true;
        }

        $providerKey = trim($providerKey);
        $provider = $this->provisioners->get($providerKey);
        if ($provider === null) {
            return false;
        }

        if (! $provider instanceof ConfiguresProvisioningServers) {
            return true;
        }

        $serverSettings = null;
        $rawServerId = $config['server_id'] ?? null;
        if ($rawServerId !== null && $rawServerId !== '') {
            if (! is_numeric($rawServerId) || (int) $rawServerId < 1) {
                return false;
            }

            $server = ProvisioningServer::query()->where('is_active', true)->find((int) $rawServerId);
            if ($server === null || $server->provider_key !== $providerKey) {
                return false;
            }

            $serverSettings = is_array($server->settings) ? $server->settings : [];
        }

        foreach ($provider->serverSettings() as $definition) {
            if (! $definition->required) {
                continue;
            }

            $value = $serverSettings !== null
                ? ($serverSettings[$definition->key] ?? null)
                : $this->settings->get($providerKey, $definition->key, $definition->default);

            if (! is_scalar($value) || trim((string) $value) === '') {
                return false;
            }
        }

        return true;
    }
}
