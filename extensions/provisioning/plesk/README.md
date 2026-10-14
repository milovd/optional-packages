# Plesk Provisioning Extension

First-party Agovena Provisioning Extension for Plesk customers and hosting
subscriptions. Implements `Provisioner`, `ProvisionerLifecycle`,
`ProvisionerPanel` and `ConfiguresProvisionedProducts` through the shared
`AbstractServerProvisioner`. It is not a Module; the Provisioning Module stays
generic.

## Readiness

`production_ready: true`. Every advertised operation below is implemented
against the Plesk Obsidian XML API documentation and covered by
`agovena-platform/tests/Feature/Provisioning/PleskProvisioningLifecycleTest.php`
(`Http::fake`, no network).

## Merchant setup

1. Admin, Extensions: install and enable **Plesk**.
2. Save the Plesk URL as `scheme://host:port` only (for example
   `https://server.example.com:8443`), the administrator secret key and the IP
   address that new subscriptions are hosted on. The key is encrypted at rest.
3. Keep **Verify TLS** on. Production refuses plain HTTP and unverified TLS.
4. Run **Test connection** (read-only `server` `get_protos` packet).
5. On each provisionable Product select this provider and set **Service plan**
   to the exact name of an existing administrator service plan and **Domain**
   to the subscription domain.

## XML API calls

Every call is `POST https://host:port/enterprise/control/agent.php` with an XML
packet body (`Content-Type: text/xml`) and the secret key in the `KEY` header
([XML API protocol](https://docs.plesk.com/en-US/obsidian/api-read-me-first/integration-and-automation-capabilities/xml-api-protocol.68677/),
[secret keys](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-secret-keys.37121/)).
Plesk answers HTTP 200 even on failure, so every `result/status` must be `ok`
([handling errors](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/creating-client-software/handling-errors.28729/)).
A packet level `system` error fails the call (`1001` means refused
credentials). A result `error` with code `1013` (object does not exist) is the
only answer read as absent; every other error code is a rejection
([error codes](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/error-codes/reduced-list-of-error-codes.33765/)).
Any other shape, a non-200 status or a redirect fails closed.

| Operation | Packet | Sent | Documentation |
| --- | --- | --- | --- |
| Health check | `server/get_protos` | none | [sending request packets](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/creating-client-software/sending-request-packets.28727/) |
| List plans (create, change plan) | `service-plan/get` | empty `filter` | [service plan get](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-service-plans/getting-information-on-service-plans.32915/), [filters](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-service-plans/available-filters.32907/) |
| Create customer | `customer/add` | `gen_info`: `pname`, `login`, `passwd`, `email` | [customer add](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-customer-accounts/creating-customer-accounts.28789/), [gen_info](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-customer-accounts/customer-settings/general-customer-account-settings/type-clientaddgeninfo.34336/) |
| Create subscription | `webspace/add` | `gen_setup` (`name`, `owner-id`, `htype` `vrt_hst`, `ip_address`), `hosting/vrt_hst` (`ftp_login`, `ftp_password`, `ip_address`), `plan-name` | [webspace add](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/creating-a-subscription.33892/), [gen_setup](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/subscription-settings/general-subscription-information/node-gen_setup.33858/) |
| Reconcile customer | `customer/get` | `filter/login`, `dataset/gen_info` | [customer get](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-customer-accounts/getting-information-about-customer-accounts.28790/) |
| Reconcile subscription, status sync, plan check | `webspace/get` | `filter/name` or `filter/id`, `dataset` `gen_info` and `subscriptions` | [webspace get](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/getting-information-about-subscriptions.33899/), [gen_info](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/subscription-settings/general-subscription-information/node-gen_info-type-domaingeninfotype.33856/), [plans](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/subscription-settings/subscription-statuses-and-associated-plans.66383/) |
| Suspend, unsuspend | `webspace/set` | `filter/id`, `values/gen_setup/status` `16` or `0` | [webspace set](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/setting-subscription-parameters.33907/) |
| Terminate subscription | `webspace/del` | `filter/id` | [webspace del](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/deleting-subscriptions.33914/) |
| Customer cleanup check | `webspace/get` | `filter/owner-id`, `dataset/gen_info` | [webspace get](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/getting-information-about-subscriptions.33899/) |
| Delete customer | `customer/del` | `filter/id` | [customer del](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-customer-accounts/deleting-customer-accounts.28791/) |
| Change plan | `webspace/switch-subscription` | `filter/id`, `plan-guid` | [switch subscription](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/switching-a-subscription-to-a-different-service-plan.66391/) |

Responses are parsed with `DOMDocument` and `LIBXML_NONET`; any document type
declaration or entity is refused, so nothing is fetched or expanded. Results
of single object operations must echo the requested `id`.

## Ownership

Ownership is proven only by a row in `plesk_accounts` that Agovena inserts
before its own create calls. Columns: `service_instance_id` (unique),
`endpoint` (lowercase `scheme://host:port`, credential independent),
`remote_id` (the subscription id, `pending:<service id>` until known),
`remote_name` (subscription domain), `customer_login`, `customer_id`, `plan`,
`state` (`creating`, `active`, `suspended`, `terminated`, `failed`,
`unknown`) and `revision`. `(endpoint, remote_id)`, `(endpoint, remote_name)`
and `(endpoint, customer_login)` are unique.

- Customers and subscriptions are never adopted from `meta.provider_mapping`,
  `external_ref`, product settings or a lookup. A service with such a
  reference and no claim row is refused without a network call.
- Every lifecycle call requires the claim endpoint to equal the endpoint of
  the current connection; a re-pointed server is refused without a network
  call.
- State changes run after the HTTP call, outside any database transaction, as
  a compare-and-swap on `revision` and the expected state. A concurrent change
  is never overwritten.
- Terminate keeps the row as `terminated`, so the domain and login stay
  claimed.

## Create and retries

- The customer login is `agv`, eight random lowercase characters and the
  base36 service id. It is stored in the claim before `customer/add` and is
  also the FTP login of the subscription.
- A per-service cache lock serializes creates. A unique violation on the claim
  refuses without any remote call.
- The service plan must appear in `service-plan/get` before anything is
  created; otherwise the claim is `failed`.
- A result `error` (or refused credentials) marks the claim `failed`. A
  rejected `customer/add` also replaces the stored login, because the old one
  may belong to someone else. A retry of a `failed` claim uses the current
  product plan and reconciles first.
- A timeout, HTTP error or malformed response marks the claim `unknown`. The
  create is never repeated blindly: the retry reads the stored login with
  `customer/get` and the stored domain with `webspace/get`. Code `1013` means
  absent and the object is created; a subscription is accepted only when its
  `owner-id` is the customer Agovena created. Anything else keeps `unknown`
  and the orchestrator moves the service to manual review.
- The customer and FTP passwords are generated per request, sent once and
  never stored, logged or returned. The customer sets a new password through
  the Plesk password reset with the recorded email address.

## Lifecycle

- Suspend and unsuspend set the documented subscription status `16`
  (disabled by administrator) and `0` (active).
- Status sync maps `0` to `active` and `16`, `32`, `64` and `256` to
  `suspended`, after checking the `owner-id`. Any other status, a foreign
  owner or code `1013` is refused for manual review; a missing subscription
  is never reported as terminated.
- Change plan resolves the plan GUID with `service-plan/get`, calls
  `switch-subscription` and then requires the GUID in the subscription plans
  before recording the new plan.
- Terminate deletes only the claimed subscription, then deletes the customer
  Agovena created only when `webspace/get` by `owner-id` returns no other
  subscription. A failed cleanup keeps `customer_id` so a retry resumes it.

## Supported and unsupported

Supported: health check, list plans (plan validation), create, idempotent
retry and reconciliation, status sync, suspend, unsuspend, terminate, change
plan to an existing service plan with verification, read-only customer panel
(status, Plesk username, plan, domain).

Unsupported (not advertised): customer power actions, single sign-on or
password handover, reseller-owned plans, add-on plans, checkout capacity
checks, moving a subscription between servers and adopting existing Plesk
customers or subscriptions.

## Environments

The `demo` environment refuses every Plesk call. Redirects are never followed,
TLS is verified and the host is DNS pinned outside `local` and `testing`.
Secret keys and generated passwords never appear in exceptions, logs, service
meta or panel data.
