# DirectAdmin Provisioning Extension

First-party Agovena Provisioning Extension for DirectAdmin user accounts. It extends
the Provisioning Module's `AbstractServerProvisioner` and implements `Provisioner`,
`ProvisionerLifecycle`, `ProvisionerPanel`, `ConfiguresProvisioningServers` and
`ConfiguresProvisionedProducts`. It is not a Module.

## Merchant setup

1. Admin, Extensions: install and enable **DirectAdmin** (the Provisioning Module is required).
2. Create a dedicated DirectAdmin **Login Key** for the reseller (or admin) account that will own
   the created users. Restrict it to the commands listed below and to the Agovena server IP.
3. Configure the server: API URL (`https://host:2222`, no path, no credentials), API username,
   Login Key (encrypted at rest, never shown again), the free or shared IP for new users, TLS
   verification (always on outside local development) and the request timeout.
4. Run **Test connection**. It lists the user packages of the API account (read-only).
5. On each provisionable Product set the existing DirectAdmin user **package** and the **domain**.

## Supported operations

| Operation | DirectAdmin command |
| --- | --- |
| Create | `POST /CMD_API_ACCOUNT_USER` (`action=create`, `add=Submit`, `username`, `email`, `passwd`, `passwd2`, `domain`, `package`, `ip`, `notify=yes`) |
| Suspend | `POST /CMD_API_SELECT_USERS` (`dosuspend=yes`, `select0`) |
| Unsuspend | `POST /CMD_API_SELECT_USERS` (`dounsuspend=yes`, `select0`) |
| Terminate | `POST /CMD_API_SELECT_USERS` (`confirmed=Confirm`, `delete=yes`, `select0`) |
| Change plan | `GET /CMD_API_PACKAGES_USER`, then `POST /CMD_API_SELECT_USERS` (`dopackage=yes`, `package`, `select0`), then verified with `CMD_API_SHOW_USER_CONFIG` |
| List plans and health | `GET /CMD_API_PACKAGES_USER` |
| Status sync | `GET /CMD_API_SHOW_USER_CONFIG?user=` (`suspended=yes` or `no`) |
| Reconciliation read | `GET /CMD_API_SHOW_USERS`, then `CMD_API_SHOW_USER_CONFIG` |

Not supported: power actions, customer SSO and capacity checks (checkout capacity stays
disabled, as for every provider without a verified capacity API).

## Documentation used

- Authentication (HTTP Basic with a Login Key) and the two API modes:
  https://docs.directadmin.com/developer/api/
- Create user, delete users, list users, user config, user packages, url-encoded response
  format (`error=0|1&text=&details=`, `list[]=`):
  https://docs.directadmin.com/developer/api/legacy-api.html#creating-accounts,
  https://docs.directadmin.com/developer/api/legacy-api.html#deleting-accounts,
  https://docs.directadmin.com/developer/api/legacy-api.html#listing-user-accounts,
  https://docs.directadmin.com/developer/api/legacy-api.html#server-information,
  https://docs.directadmin.com/developer/api/legacy-api.html#reseller-and-user-packages,
  https://docs.directadmin.com/developer/api/legacy-api.html#keypoints-about-the-legacy-api
- Non-toggle suspend and unsuspend (`dosuspend`, `dounsuspend`) and the standard API output of
  `CMD_API_SELECT_USERS`:
  https://docs.directadmin.com/changelog/version-1.31.0.html#create-definitive-suspend-and-activate-buttons-for-accounts-suspension-non-toggle-version-api
- Package assignment through `CMD_SELECT_USERS` (`dopackage`, `package`, `select0`):
  https://docs.directadmin.com/changelog/version-1.59.0.html#ability-to-select-users-and-set-package-in-mass

The user-level `CMD_API_MODIFY_USER` `action=package` form is only documented for resellers
(`CMD_API_MODIFY_RESELLER`), so the documented `dopackage` command is used instead and the result
is verified by reading the user config. Responses are parsed in the documented url-encoded
format; the JSON mode (`json=yes`) has no documented per-command response shape.

## Ownership and idempotency

- Ownership is proven only by a row in `directadmin_accounts` (migration
  `2026_10_05_000000_create_directadmin_accounts_table.php`). The row is inserted with state
  `creating` before the create call. `service_instance_id`, (`endpoint`, `remote_id`) and
  (`endpoint`, `remote_name`) are unique, so two services can never claim the same username or
  domain on one server. A conflicting claim is refused without any remote call.
- `endpoint` is the normalized `scheme://host:port` of the API URL, without path or credentials.
  Every lifecycle action is refused without a remote call when the current server settings point
  elsewhere.
- The username (8 lowercase alphanumeric characters starting with a letter) is random, generated
  once and stored in the claim row, so retries reuse it. Product settings cannot choose it.
- The create call runs outside any database transaction under a per-service cache lock. Its
  outcome is written with a compare-and-swap on `revision` and the expected state; a concurrent
  change is never overwritten.
- `error=1` from DirectAdmin marks the claim `failed`. Timeouts, non-200 responses (redirects are
  never followed) and unparseable bodies mark it `unknown`. A retry of an `unknown` or `creating`
  claim first reads the stored username: absent from the API account's user list means the
  create is repeated with the same username; present and matching (username, domain, creator,
  user type) means active; anything else stays `unknown` and is refused. A `failed` claim never
  adopts an existing user. Existing users are never adopted by lookup.
- Terminate deletes only users of an `active` or `suspended` claim and keeps the row as
  `terminated`, so the username stays claimed.

## Secrets

The generated account password (24 characters) is sent once to DirectAdmin, which mails the
login details to the customer (`notify=yes`). It is never stored, logged or returned. The Login
Key is only sent in the `Authorization` header and never appears in exceptions, meta or
`ServiceInstanceInfo`. All provider HTTP is refused in the `demo` environment. Redirects are not
followed and TLS verification stays on.

## Customer panel

Shows the status, the plain DirectAdmin login URL, the username and the domain of an active or
suspended claimed account. No SSO and no credentials.

## Limitations

- The domain comes from the Product setting, so one Product can create one account per domain
  and server. Per-order customer domains need a checkout field in the Provisioning Module.
- Reconciliation uses the API account's own user list; an admin API user only sees the users it
  created itself.
