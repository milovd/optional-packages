# Pterodactyl Provisioning Extension

First-party Agovena Provisioning Extension. Implements `Provisioner`,
`ProvisionerLifecycle`, `ProvisionerActions`, `ProvisionerPanel`, and
`ConfiguresProvisionedProducts`.

It is **not** a Module. The Provisioning Module stays generic.

## Merchant setup

1. Admin → Extensions → install and enable **Pterodactyl**
2. Save the panel URL and an Application API key (encrypted at rest; never redisplayed)
3. Set the Pterodactyl user id that will own created servers
4. Optional Client API key enables customer start/stop/restart
5. Optional: `AGOVENA_EXT_PTERODACTYL_APPLICATION_API_KEY` / `AGOVENA_EXT_PTERODACTYL_CLIENT_API_KEY`
6. Run **Test connection**
7. On each provisionable Product, select this provider and fill location / nest / egg / limits

## URL and TLS

Panel URL must be `http` or `https` without userinfo. Private-network hosts are
allowed because self-hosted panels are commonly on LAN.

**SSRF tradeoff:** an Admin who can configure this Extension can point Agovena
at an internal URL. That is an accepted operator capability, not a customer
input. Redirects are disabled. TLS verification defaults to on; disable only
for a known self-signed panel.

The Application API key is never shown to customers.

## Product mapping

Location, nest, egg, memory, disk, CPU, and related limits live in the
provisionable capability `provider_settings`. They are not Core Product columns.

## Idempotency

Creates send `external_id = agovena-{serviceInstanceId}`. Retries look up that
id before creating another server. Mapping is stored in `pterodactyl_servers`.

Disable preserves Service Instance rows and this mapping table.
