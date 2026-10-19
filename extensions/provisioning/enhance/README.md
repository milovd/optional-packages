# Enhance Provisioning Extension

First-party Agovena Provisioning Extension for Enhance control panel hosting.
Implements `Provisioner`, `ProvisionerLifecycle`, `ProvisionerPanel` and
`ConfiguresProvisionedProducts` through the shared `AbstractServerProvisioner`.
It is not a Module; the Provisioning Module stays generic.

## Readiness

`production_ready: true`. Create, suspend, unsuspend, terminate, plan change,
plan listing, status sync, panel and health are implemented against the
official Enhance orchd OpenAPI specification (version 12.25.14,
<https://apidocs.enhance.com/>) and covered by
`agovena-platform/tests/Feature/Provisioning/EnhanceProvisioningLifecycleTest.php`
(`Http::fake`, no network). Live acceptance against a merchant control panel is
a separate operator check.

## Merchant setup

1. Admin, Extensions: install and enable **Enhance**.
2. In Enhance, Settings, Access Tokens: create a token with the **Super Admin**
   role. Note the token and the **Org ID** shown on that page.
3. Save the control panel URL as `scheme://host[:port]` without a path (for
   example `https://cp.example.com`), the access token (encrypted at rest) and
   the Org ID as **Reseller organization id**.
4. Keep **Verify TLS** on. Production refuses plain HTTP and unverified TLS.
5. Run **Test connection** (read-only `getOrg` on the reseller org).
6. On each provisionable Product select this provider and set **Plan id** (a
   numeric plan id of the reseller org) and **Domain**.

## Enhance API calls

All calls go to `https://host:port/api/...` with `Authorization: Bearer ***`
and `Accept: application/json`, never follow redirects, verify TLS and pin DNS
through the shared `AbstractHttpServerApi`. They are refused in the `demo`
environment. `{reseller}` is the configured reseller org id, `{org}` the
customer org id Agovena created. Response shapes are validated strictly;
anything else fails closed. Listings are read page by page (`offset`) until
`total` items were seen.

| Operation | operationId | Method and path | Sent | Success check |
| --- | --- | --- | --- | --- |
| Health check | `getOrg` | `GET /orgs/{reseller}` | none | `id` equals the reseller org id and `status` is `active` |
| List plans (create, plan change) | `getPlans` | `GET /orgs/{reseller}/plans` | `offset` for later pages | `items[]` with integer `id`, integer `total`; the plan must be listed |
| Create customer org | `createCustomer` | `POST /orgs/{reseller}/customers` | `name` | UUID `id` |
| Create subscription | `createCustomerSubscription` | `POST /orgs/{reseller}/customers/{org}/subscriptions` | `planId` | integer `id` |
| Create website | `createWebsite` | `POST /orgs/{org}/websites` | `domain`, `subscriptionId` | UUID `id` |
| Reconcile an interrupted subscription create, terminate check | `getCustomerSubscriptions` | `GET /orgs/{reseller}/customers/{org}/subscriptions` | none | `items[].status` in `active`, `deleted` |
| Reconcile an interrupted website create, terminate check | `getWebsites` | `GET /orgs/{org}/websites` | `subscriptionId` when reconciling | `items[].status` in `active`, `disabled`, `deleted` |
| Status sync, plan change verification | `getSubscription` | `GET /orgs/{org}/subscriptions/{id}` | none | `id`, integer `planId`, `subscriberId` equals `{org}`, `vendorId` equals `{reseller}`, `status` `active`; `suspendedBy` present means suspended |
| Suspend, unsuspend | `updateSubscription` | `PATCH /orgs/{org}/subscriptions/{id}` | `isSuspended` `true` or `false` | `204` without body |
| Plan change | `updateSubscription` | `PATCH /orgs/{org}/subscriptions/{id}` | `planId` | `204` without body, then `getSubscription` must report the new `planId` |
| Terminate | `deleteSubscription` | `DELETE /orgs/{org}/subscriptions/{id}` | no `force` (soft delete, data kept) | `204` without body |
| Remove the customer org | `deleteOrg` | `DELETE /orgs/{org}` | no `force` (soft delete) | `204` without body |

`/orgs/{org_id}/subscriptions` lists the subscriptions an org holds to its
parent's plans, so the single subscription operations address the customer
org that holds the subscription.

## Ownership

Ownership is proven only by the `enhance_accounts` claim row (migration
`database/migrations/2026_10_05_000000_create_enhance_accounts_table.php`):
unique `service_instance_id`, unique `(endpoint, remote_id)` (the subscription
id) and `(endpoint, remote_name)` (the domain), `revision` for
compare-and-swap. `endpoint` is the normalized `scheme://host:port` of the
control panel URL without credentials.

- The claim row is inserted with `remote_id = pending:<service id>` before any
  create call; a domain already claimed on the same panel is refused without a
  provider call. Terminate keeps the row as `terminated`, so the domain and
  subscription id stay claimed.
- Each create step records its returned id (customer org, subscription,
  website) under the revision check. Every lifecycle call holds a per-service
  cache lock; provider calls never run inside a database transaction.
- `400`, `404` and `409` answers, refused credentials and errors raised before
  a request was sent prove that nothing was created: the claim becomes `failed`
  and a retry creates the missing steps. Timeouts, other errors and unexpected
  bodies leave the claim `unknown`; the orchestrator moves the service to
  manual review.
- An interrupted customer org create is never retried and needs manual review;
  nothing found by name is adopted. An interrupted subscription or website
  create is retried only when a read inside the customer org Agovena created
  returns no subscription or no website for the recorded subscription at all;
  anything found there is never adopted and keeps the claim `unknown`.
- Status sync, suspension and plan changes require the claim state and the
  current endpoint to match; a re-pointed panel or a hand-written
  `provider_mapping` without a claim row is refused without network. A
  subscription reported as deleted, held by another org or sold by another
  vendor is refused for manual review.
- Terminate deletes only the recorded subscription (Enhance soft deletes its
  websites with it), then deletes the customer org Agovena created only when
  all its subscriptions and websites are deleted. An org holding anything else
  is kept and released from the claim.
- No passwords are generated or stored, and the access token never appears in
  claim rows, service meta, exceptions or panel data.

## Not supported

- Customer logins and memberships (`createLogin`, `createMember`) and single
  sign-on: customers get panel access through the merchant in Enhance.
- Power actions, capacity checks, dedicated server selection and force
  deletion of data.
