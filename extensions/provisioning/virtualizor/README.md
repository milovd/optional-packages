# Virtualizor Provisioning Extension

First-party Agovena Provisioning Extension for Virtualizor VPSs. Implements
`Provisioner`, `ProvisionerLifecycle`, `ProvisionerPanel` and
`ConfiguresProvisionedProducts` through the shared `AbstractServerProvisioner`.
It is not a Module; the Provisioning Module stays generic.

## Readiness

`production_ready: true`. Create, suspend, unsuspend, terminate, plan change,
plan listing, status sync, panel and health are implemented against the
Virtualizor Admin API documentation and covered by
`agovena-platform/tests/Feature/VirtualizorProvisioningLifecycleTest.php`
(`Http::fake`, no network, failure paths, secret-leak test). Live acceptance
against a merchant panel is a separate operator check.

## Merchant setup

1. Admin, Extensions: install and enable **Virtualizor**.
2. Save the admin panel URL as `scheme://host:port` without a path (for example
   `https://vz.example.com:4085`) and the Admin API key and password from
   Configuration, Server Info on the master. Both secrets are encrypted at rest.
3. Keep **Verify TLS** on. Production refuses plain HTTP and unverified TLS.
4. Run **Test connection** (read-only plan list).
5. On each provisionable Product select this provider and set **Plan ID** (an
   enabled Virtualizor plan), **Operating system template ID** and **Server
   group ID** (0 is the default group).

## Virtualizor Admin API calls

Every call goes to `https://host:port/index.php?act=...&api=json&adminapikey=***&adminapipass=***`
(`adminapikey` and `adminapipass` as in the documented request URL, `api=json`
for JSON output). Documented POST parameters are sent as form data. Calls never
follow redirects, verify TLS, validate the URL and pin DNS like the shared
`AbstractHttpServerApi`, and are refused in the `demo` environment. Because the
credentials are query parameters, request URLs are never put in exceptions,
messages or logs; transport errors surface only as translation keys.

Virtualizor answers failures with HTTP 200, so every response must be a JSON
object, any non-empty `error` fails the call, and the documented success field
is checked. Anything else fails closed.

| Operation | Request | Sent | Success check | Documentation |
| --- | --- | --- | --- | --- |
| Health check, plan check, list plans | `POST act=plans` | `reslen=1000` | `plans` object keyed by `plid`, each with `plid`, `virt`, `is_enabled` and digit resources | [List Plans](https://www.virtualizor.com/docs/admin-api/list-plans) |
| Foreign user check (create) | `POST act=users` | `email` | `users` object; any user with the same email blocks the create | [List Users](https://www.virtualizor.com/docs/admin-api/list-users) |
| Create VPS and user | `POST act=addvs` | `addvps=1`, `virt`, `plid`, `osid`, `node_select=1`, `server_group`, `hostname`, `rootpass`, `user_email`, `user_pass`, `fname`, `lname`, `num_ips`, `space`, `ram`, `bandwidth`, `cores`, `uid` for a recorded user | `error` is an empty list and `vs_info` has digit `vpsid` and `uid` | [Create VPS](https://www.virtualizor.com/docs/admin-api/create-vps) |
| Status sync, ownership check | `GET act=vs&vpsid={id}&search=Search` | none | `vs.{id}` with matching `vpsid`, recorded `uid`, `plid`, `virt`, `suspended` 0 or 1 | [List Virtual Servers](https://www.virtualizor.com/docs/admin-api/list-virtual-servers) |
| Suspend | `GET act=vs&suspend={id}` | none | `done` is 1 | [Suspend VPS](https://www.virtualizor.com/docs/admin-api/suspend-vps) |
| Unsuspend | `GET act=vs&unsuspend={id}` | none | `done` is 1 | [Unsuspend VPS](https://www.virtualizor.com/docs/admin-api/unsuspend-vps) |
| Terminate | `GET act=vs&delete={id}` after the ownership check | none | `done` is true | [Delete VPS](https://www.virtualizor.com/docs/admin-api/delete-vps) |
| Plan change | `POST act=managevps&vpsid={id}` after the plan and ownership checks | `vpsid`, `plid`, `apply_plan=1`, `editvps=1` | `done.done` is true, then the VPS read shows the new `plid` | [Manage VPS](https://www.virtualizor.com/docs/admin-api/api-manage-vps) |

`editvps=1` is the submit flag that the official SDK `managevps()` call used in
the documented sample sends; the plan change is accepted only when a fresh VPS
read reports the new `plid`.

## Ownership

Ownership is proven only by the `virtualizor_accounts` claim row (migration
`database/migrations/2026_10_05_000000_create_virtualizor_accounts_table.php`):
unique `service_instance_id`, unique `(endpoint, remote_id)`, `user_id` of the
Virtualizor user that Agovena's own create returned, and `revision` for
compare-and-swap. `endpoint` is the normalized `scheme://host:port` of the panel
URL without credentials.

- The claim row is inserted with `remote_id = pending:<service id>` under a
  per-service lock before any create call; the vpsid replaces it in the same
  conditional update that marks the claim active.
- A later service of the same Agovena customer on the same panel passes the
  `uid` that an earlier Agovena create recorded, and the returned `uid` must
  match. Without a recorded user, any existing Virtualizor user with the same
  email blocks the create (`addvs` would attach the VPS to that user), so a user
  found only by lookup is never adopted.
- A non-empty `error` list or refused credentials prove that nothing was
  created: the claim becomes `failed` and a retry creates again. A timeout, an
  HTTP error or an unexpected body leaves the claim `unknown`; because no vpsid
  was recorded, it is never retried and the orchestrator moves the service to
  manual review.
- Lifecycle calls require the claim state and the current endpoint to match; a
  re-pointed panel or a hand-written `provider_mapping` without a claim row is
  refused without network. Terminate, plan change and status sync first read
  the VPS by its recorded vpsid and require the recorded `uid`; a missing VPS
  goes to manual review. Terminate keeps the row as `terminated`.
- The generated root and user passwords are sent once and never stored;
  Virtualizor emails the access details to the customer.

## Not supported

- Power actions, customer single sign-on and capacity checks.
- Deleting Virtualizor users; terminate removes only the VPS.
- Automatic recovery of an interrupted create; it always needs manual review.

## Known limits

- Plan change applies the plan values through `apply_plan`. The Manage VPS
  documentation warns that disks missing from a `space` array are removed;
  Agovena sends no `space` array, so test plan changes on multi-disk VPSs before
  offering them.
- Plan and user lookups read one page (`reslen=1000` for plans, the default page
  for users); a plan beyond that page is reported as unavailable.
