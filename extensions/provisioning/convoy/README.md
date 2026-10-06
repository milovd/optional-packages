# Convoy Provisioning Extension

First-party Agovena Provisioning Extension for Convoy virtual servers.
Implements `Provisioner`, `ProvisionerLifecycle`, `ProvisionerPanel` and
`ConfiguresProvisionedProducts` through the shared `AbstractServerProvisioner`.
It is not a Module; the Provisioning Module stays generic.

## Pinned version

Built and verified against the Convoy source at tag
[`v4.6.1`](https://github.com/ConvoyPanel/panel/tree/v4.6.1). The `main` branch
(4.7 release candidate) uses a different route layout; re-verify every call
below before supporting another version.

## Readiness

`production_ready: true`. Create, suspend, unsuspend, terminate, resource limit
change, status sync, panel and health are implemented against the v4.6.1
source and covered by
`agovena-platform/tests/Feature/ConvoyProvisioningLifecycleTest.php`
(`Http::fake`, no network). Live acceptance against a merchant panel is a
separate operator check.

## Merchant setup

1. Admin, Extensions: install and enable **Convoy**.
2. Save the panel URL as `scheme://host[:port]` without a path (for example
   `https://convoy.example.com`) and an application API token of a Convoy
   administrator. The token is encrypted at rest.
3. Keep **Verify TLS** on. Production refuses plain HTTP and unverified TLS.
4. Run **Test connection** (read-only `GET /servers?per_page=1`).
5. On each provisionable Product select this provider and set **Node ID**,
   **Template UUID**, **CPU cores**, **Memory (MiB)**, **Disk (MiB)**,
   **Bandwidth (GiB)** (empty is unlimited), **Snapshot limit** and
   **Backup limit**. Convoy has no packages: these limits are the plan.

## Convoy API calls

All calls go to `https://host:port/api/application/...` with
`Authorization: Bearer <token>` and `Accept: application/json`
([RouteServiceProvider](https://github.com/ConvoyPanel/panel/blob/v4.6.1/app/Providers/RouteServiceProvider.php),
[routes/api-admin.php](https://github.com/ConvoyPanel/panel/blob/v4.6.1/routes/api-admin.php)).
They never follow redirects, verify TLS and pin DNS through the shared
`AbstractHttpServerApi`, and are refused in the `demo` environment. Servers are
addressed by their full uuid. Response shapes are validated strictly; anything
else fails closed. Memory, disk and bandwidth are sent and compared in bytes.

| Operation | Method and path | Sent | Success check | Source |
| --- | --- | --- | --- | --- |
| Health check | `GET /servers?per_page=1` | none | `data` list | [ServerController::index](https://github.com/ConvoyPanel/panel/blob/v4.6.1/app/Http/Controllers/Admin/ServerController.php) |
| Create user | `POST /users` | `name`, `email`, generated `password`, `root_admin=false` | integer `data.id`, `data.root_admin` false | [StoreUserRequest](https://github.com/ConvoyPanel/panel/blob/v4.6.1/app/Http/Requests/Admin/Users/StoreUserRequest.php), [UserTransformer](https://github.com/ConvoyPanel/panel/blob/v4.6.1/app/Transformers/Admin/UserTransformer.php) |
| Create server | `POST /servers` | `name`, `user_id`, `node_id`, `vmid=null`, `hostname`, `limits` (`cpu`, `memory`, `disk`, `snapshots`, `backups`, `bandwidth`), generated `account_password`, `should_create_server=true`, `template_uuid`, `start_on_completion=true` | `data.uuid` and `data.user_id` equal to the recorded user | [StoreServerRequest](https://github.com/ConvoyPanel/panel/blob/v4.6.1/app/Http/Requests/Admin/Servers/StoreServerRequest.php), [ServerController::store](https://github.com/ConvoyPanel/panel/blob/v4.6.1/app/Http/Controllers/Admin/ServerController.php) |
| Status sync, plan change read | `GET /servers/{uuid}` | none | `data.uuid` and `data.user_id` match the claim, known `status`, integer limits, address ids | [ServerBuildTransformer](https://github.com/ConvoyPanel/panel/blob/v4.6.1/app/Transformers/Admin/ServerBuildTransformer.php) |
| Suspend | `POST /servers/{uuid}/settings/suspend` | none | `204` with empty body | [ServerController::suspend](https://github.com/ConvoyPanel/panel/blob/v4.6.1/app/Http/Controllers/Admin/ServerController.php) |
| Unsuspend | `POST /servers/{uuid}/settings/unsuspend` | none | `204` with empty body | [ServerController::unsuspend](https://github.com/ConvoyPanel/panel/blob/v4.6.1/app/Http/Controllers/Admin/ServerController.php) |
| Terminate | `DELETE /servers/{uuid}` | no `no_purge` | `204` with empty body; `404` for the recorded uuid means already gone | [ServerController::destroy](https://github.com/ConvoyPanel/panel/blob/v4.6.1/app/Http/Controllers/Admin/ServerController.php) |
| Plan change | `PATCH /servers/{uuid}/settings/build` | `cpu`, `memory`, `disk`, `snapshot_limit`, `backup_limit`, `bandwidth_limit`, current `address_ids` | server body for the same uuid, then a fresh `GET` must show the new limits and the same address ids | [UpdateBuildRequest](https://github.com/ConvoyPanel/panel/blob/v4.6.1/app/Http/Requests/Admin/Servers/Settings/UpdateBuildRequest.php), [ServerController::updateBuild](https://github.com/ConvoyPanel/panel/blob/v4.6.1/app/Http/Controllers/Admin/ServerController.php) |

Plan change re-sends the address ids read from `GET /servers/{uuid}`
(`limits.addresses.ipv4[].id` and `ipv6[].id`): `updateBuild` detaches every
address missing from `address_ids`. Convoy swallows hypervisor sync failures
inside `updateBuild`, so the stored plan changes only after a fresh read
confirms the limits and addresses.

Status mapping: `null` is active, `suspended` is suspended, `installing`,
`restoring_backup` and `restoring_snapshot` report provisioning,
`install_failed` is refused as a failed build, and `deleting`,
`deletion_failed` or any unknown value is refused for manual review.

## Ownership

Ownership is proven only by the `convoy_accounts` claim row (migration
`database/migrations/2026_10_05_000000_create_convoy_accounts_table.php`):
unique `service_instance_id`, unique `(endpoint, remote_id)`, `revision` for
compare-and-swap. `endpoint` is the normalized `scheme://host:port` of the
panel URL without credentials. `plan` holds the confirmed limits as JSON.

- The claim row is inserted with `remote_id = pending:<service id>` before any
  create call, under a per-service lock; HTTP calls run outside database
  transactions and every state change is a conditional update on `revision`.
- A Convoy user is created per customer and endpoint. A later service of the
  same Agovena customer on the same panel reuses the user id that an earlier
  Agovena create recorded; a user found only by email or lookup is never
  adopted (a duplicate email is a `422` and fails the claim).
- A `422` from user or server create, refused credentials, or a failure
  before sending prove that nothing was created: the claim becomes `failed`
  and a retry creates again, keeping a recorded user. Timeouts, other errors
  and unexpected bodies leave the claim `unknown`; Convoy returns no
  identifier Agovena could have chosen, so the create is never retried and the
  orchestrator moves the service to manual review.
- Lifecycle calls require the claim state and the current endpoint to match;
  a re-pointed panel or a hand-written `provider_mapping` without a claim row is
  refused without network. Terminate keeps the row as `terminated`.
- The generated user password and server `account_password` are sent once and
  never stored, logged or returned. Customers set their own password through
  the Convoy password reset.

## Not supported

- Power actions, customer single sign-on and capacity checks.
- Deleting Convoy users; terminate removes only the server.
- Moving a server to another node or reinstalling a template on plan change.
