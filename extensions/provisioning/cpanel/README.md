# cPanel Provisioning Extension

First-party Agovena Provisioning Extension for cPanel and WHM hosting accounts.
Implements `Provisioner`, `ProvisionerLifecycle`, `ProvisionerPanel` and
`ConfiguresProvisionedProducts` through the shared `AbstractServerProvisioner`.
It is not a Module; the Provisioning Module stays generic.

## Readiness

`production_ready: true`. Every advertised operation below is implemented
against the WHM API 1 specification and covered by
`agovena-platform/tests/Feature/CPanelProvisioningLifecycleTest.php`
(`Http::fake`, no network).

## Merchant setup

1. Admin, Extensions: install and enable **CPanel**.
2. Save the WHM URL as `scheme://host:port` only (for example
   `https://server.example.com:2087`), the WHM username (`root` or a reseller)
   and a WHM API token of that user. The token is encrypted at rest.
3. Keep **Verify TLS** on. Production refuses plain HTTP and unverified TLS.
4. Run **Test connection** (read-only `version` call).
5. On each provisionable Product select this provider and set **Package** to
   an existing WHM package that the token may assign.

## WHM API calls

All calls are `GET https://host:port/json-api/<function>?api.version=1` with
the header `Authorization: whm USERNAME:TOKEN`
([API tokens in WHM](https://api.docs.cpanel.net/whm/tokens)). A call succeeds
only when `metadata.command` echoes the function and `metadata.result` is `1`;
`metadata.result` `0` is a definitive rejection; any other shape fails closed.

| Operation | WHM function | Parameters sent | Documentation |
| --- | --- | --- | --- |
| Health check | `version` | none | [version](https://api.docs.cpanel.net/specifications/whm.openapi/updates/cpanel-version) |
| Package check (create, plan change) | `listpkgs` | `want=creatable` | [listpkgs](https://api.docs.cpanel.net/specifications/whm.openapi/hosting-plans/packages-listpkgs) |
| Create | `createacct` | `username`, `plan`, `showpass=n` | [createacct](https://api.docs.cpanel.net/specifications/whm.openapi/account-creation/accounts-createacct) |
| Reconcile an interrupted create | `listaccts` | `searchtype=user`, `searchmethod=exact`, `search` | [listaccts](https://api.docs.cpanel.net/specifications/whm.openapi/account-management/accounts-listaccts) |
| Status sync | `accountsummary` | `user` | [accountsummary](https://api.docs.cpanel.net/specifications/whm.openapi/account-management/accounts-accountsummary) |
| Suspend | `suspendacct` | `user`, `reason` | [suspendacct](https://api.docs.cpanel.net/specifications/whm.openapi/suspensions/accounts-suspendacct) |
| Unsuspend | `unsuspendacct` | `user` | [unsuspendacct](https://api.docs.cpanel.net/specifications/whm.openapi/suspensions/accounts-unsuspendacct) |
| Terminate | `removeacct` | `username` | [removeacct](https://api.docs.cpanel.net/specifications/whm.openapi/account-management/accounts-removeacct) |
| Change plan | `changepackage` | `user`, `pkg` | [changepackage](https://api.docs.cpanel.net/specifications/whm.openapi/account-management/accounts-changepackage) |

## Ownership

Ownership is proven only by a row in `cpanel_accounts` that Agovena inserts
before its own `createacct` call. Columns: `service_instance_id` (unique),
`endpoint` (lowercase `scheme://host:port`, credential independent),
`remote_id` (the cPanel username), `remote_name` (main domain), `plan`,
`state` (`creating`, `active`, `suspended`, `terminated`, `failed`,
`unknown`) and `revision`. `(endpoint, remote_id)` and
`(endpoint, remote_name)` are unique.

- Accounts are never adopted from `meta.provider_mapping`, `external_ref`,
  product settings or a lookup. A service with such a reference and no claim
  row is refused without a network call.
- Every lifecycle call requires the claim endpoint to equal the endpoint of the
  current connection; a re-pointed server is refused without a network call.
- State changes run after the HTTP call, outside any database transaction, as a
  compare-and-swap on `revision` and the expected state. A concurrent change is
  never overwritten.
- Terminate keeps the row as `terminated`, so the username stays claimed.

## Create and retries

- The username is `a`, seven random lowercase characters and the base36 service
  id (at most 16 characters). The random first eight characters respect the
  WHM rule that the first eight characters must be unique. It is stored in the
  claim row before `createacct`, so a retry reuses it.
- A per-service cache lock serializes creates. A unique violation on the claim
  refuses without any remote call.
- `createacct` with `metadata.result` `0` or refused credentials marks the
  claim `failed`. A retry checks the old username with `listaccts`: when it is
  clearly absent a fresh username is claimed and created; when it exists the
  claim stays `failed` and the service needs manual review.
- A timeout, HTTP error or malformed response marks the claim `unknown`. The
  create is never repeated blindly: the retry reads the stored username with
  `listaccts`. Absent means create again; present is accepted only when
  `owner` equals the WHM username, `plan` equals the claimed package and
  `unix_startdate` is not older than the claim (300 seconds clock skew).
  Anything else keeps `unknown` and the orchestrator moves the service to
  manual review.
- `accountsummary` is used only for status sync of an `active` or `suspended`
  claim. It maps `suspended` `1` to `suspended` and `0` to `active`, and records
  `plan` and `domain`. Unknown or malformed data is refused.
- No customer password is sent, stored or returned (`showpass=n`); WHM generates
  one.

## Supported and unsupported

Supported: health check, create, idempotent retry and reconciliation, status
sync, suspend, unsuspend, terminate, change plan to an existing creatable
package, read-only customer panel (status, username, package, domain).

Unsupported (not advertised): customer power actions, customer single sign-on
or password handover, customer-chosen main domain (WHM assigns a temporary
domain), checkout capacity checks, and moving an account between servers.

## Environments

The `demo` environment refuses every WHM call. Redirects are never followed and
tokens never appear in exceptions, logs, service meta or panel data.
