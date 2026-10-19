# VirtFusion Provisioning Extension

First-party Agovena Provisioning Extension for VirtFusion virtual servers.
Implements `Provisioner`, `ProvisionerLifecycle`, `ProvisionerPanel` and
`ConfiguresProvisionedProducts` through the shared `AbstractServerProvisioner`.
It is not a Module; the Provisioning Module stays generic.

## Readiness

`production_ready: true`. Create, suspend, unsuspend, terminate, plan change,
package check, status sync, panel and health are implemented against the
VirtFusion API v1 documentation and covered by
`agovena-platform/tests/Feature/Provisioning/VirtfusionProvisioningLifecycleTest.php`
(`Http::fake`, no network). Live acceptance against a merchant panel is a
separate operator check.

Plan change first confirms that the target package exists and is enabled
(`GET /packages`), then sends `PUT /servers/{serverId}/package/{packageId}`
with every documented resource flag set to follow the new package. Only a 200
answer with an `info` list is accepted; the stored plan changes afterwards
under the revision check. VirtFusion never shrinks a primary disk and reports
such skipped resources in `info`.

## Merchant setup

1. Admin, Extensions: install and enable **VirtFusion**.
2. Save the panel URL as `scheme://host[:port]` without a path (for example
   `https://cp.example.com`) and a VirtFusion API token. The token is
   encrypted at rest.
3. Keep **Verify TLS** on. Production refuses plain HTTP and unverified TLS.
4. Run **Test connection** (read-only `GET /connect`).
5. On each provisionable Product select this provider and set **Package ID**
   (an enabled VirtFusion package), **Hypervisor group ID** and **Operating
   system template ID**.

## VirtFusion API calls

All calls go to `https://host:port/api/v1/...` with `Authorization: Bearer ***`
and `Accept: application/json`, never follow redirects, verify TLS and pin DNS
through the shared `AbstractHttpServerApi`. They are refused in the `demo`
environment. Response shapes are validated strictly; anything else fails closed.

| Operation | Method and path | Sent | Success check | Documentation |
| --- | --- | --- | --- | --- |
| Health check | `GET /connect` | none | empty array body | [testConnection](https://docs.virtfusion.com/api/testConnection/) |
| Package check (create) | `GET /packages` | none | `data[]` with integer `id` and boolean `enabled`; the product package must be enabled | [listPackages](https://docs.virtfusion.com/api/listPackages/) |
| Create user | `POST /users` | `name`, `email`, `relStr`, `sendMail=true` | `201` with integer `data.id` | [createUser](https://docs.virtfusion.com/api/createUser/) |
| Reconcile an interrupted user create | `GET /users/{relStr}/byExtRelation?relStr=true` | none | integer `data.id` | [getUserByExtRelation](https://docs.virtfusion.com/api/getUserByExtRelation/) |
| Create server | `POST /servers` | `packageId`, `userId`, `hypervisorId` | `201` with integer `data.id` and `data.ownerId` equal to the recorded user | [createServer](https://docs.virtfusion.com/api/createServer/) |
| Build server | `POST /servers/{id}/build` | `operatingSystemId`, `email=true` | `data.id` equals the server id | [buildServer](https://docs.virtfusion.com/api/buildServer/) |
| Status sync, reconcile a build | `GET /servers/{id}` | none | `data.id`, `ownerId`, boolean `suspended` and `buildFailed`, nullable `built` | [getServer](https://docs.virtfusion.com/api/getServer/) |
| Suspend | `POST /servers/{id}/suspend` | none | `204` without body | [suspendServer](https://docs.virtfusion.com/api/suspendServer/) |
| Unsuspend | `POST /servers/{id}/unsuspend` | none | `204` without body | [unsuspendServer](https://docs.virtfusion.com/api/unsuspendServer/) |
| Terminate | `DELETE /servers/{id}` | no `delay`, deletion is queued now | `204` without body; documented `404` means already gone | [deleteServer](https://docs.virtfusion.com/api/deleteServer/) |
| Plan change | `PUT /servers/{id}/package/{packageId}` | every documented resource flag `true` | `200` with an `info` list of strings; plan stored afterwards | [changeServerPackage](https://docs.virtfusion.com/api/changeServerPackage/) |

## Ownership

Ownership is proven only by the `virtfusion_accounts` claim row (migration
`database/migrations/2026_10_05_000000_create_virtfusion_accounts_table.php`):
unique `service_instance_id`, unique `(endpoint, remote_id)` and
`(endpoint, user_relation)`, `revision` for compare-and-swap. `endpoint` is the
normalized `scheme://host:port` of the panel URL without credentials.

- The claim row is inserted with `remote_id = pending:<service id>` and a random
  relation string `agovena-<base36 service id>-<16 random characters>` before
  any create call. VirtFusion's integer `extRelationId` cannot be namespaced,
  so the documented relation string (`relStr`) carries the link instead.
- A VirtFusion user is created per customer and endpoint. A later service of
  the same Agovena customer on the same panel reuses the user id that an
  earlier Agovena create recorded; a user found only by email or lookup is
  never adopted (a duplicate email is a documented `409` and fails the claim).
- `409` and `422` from user or server create, and refused credentials, prove
  that nothing was created: the claim becomes `failed` and a retry creates
  again. Timeouts, other errors and unexpected bodies leave the claim
  `unknown`; the orchestrator moves the service to manual review.
- Reconciliation reads only recorded identifiers: the returned server id
  (owner must match the recorded user; an unbuilt server is built again, which
  VirtFusion refuses with `423` while a build runs) or the relation string of a
  user create whose response was lost. A server create without a returned id
  is never retried.
- Lifecycle calls require the claim state and the current endpoint to match;
  a re-pointed panel or a hand-written `provider_mapping` without a claim row is
  refused without network. Terminate keeps the row as `terminated`.
- The VirtFusion user password from the create response is never stored;
  VirtFusion emails the access details (`sendMail`, build `email`).

## Not supported

- Power actions, customer single sign-on and capacity checks.
- Deleting VirtFusion users; terminate removes only the server.
