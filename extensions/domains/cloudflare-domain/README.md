# Cloudflare Domains

Optional Agovena extension for the `domains` module. It registers two providers:

- `cloudflare-registrar` (`DomainRegistrar`): availability check and registration through the Cloudflare Registrar API.
- `cloudflare-dns` (`DomainDnsProvider`): zone preparation and DNS record management through the Cloudflare DNS API.

Status: `production_ready: true`. See "Readiness" below.

## Settings

| Key | Notes |
| --- | --- |
| `account_id` | Cloudflare account ID (alphanumeric, max 32 characters). Used in every Registrar path and as the zone owner. |
| `api_token` | Stored as a secret. Needs Registrar permissions for registration and `Zone Edit` or `DNS Edit` for zones and records. |

Registration also requires, outside this extension: a billing profile with a valid default payment method and a default registrant address book entry with the accepted agreement in the Cloudflare dashboard (express mode, no contacts are sent).

## Supported operations

All endpoints are relative to `https://api.cloudflare.com/client/v4` and were checked against the official API reference at `https://developers.cloudflare.com/api/resources/registrar/` and `https://developers.cloudflare.com/api/resources/dns/` / `https://developers.cloudflare.com/api/resources/zones/`.

| Operation | Endpoint | Behaviour |
| --- | --- | --- |
| Availability (`availability_check`) | `POST /accounts/{account_id}/registrar/domain-check` | Available only when `registrable: true` and `tier` is not `premium` (premium registration is not supported by the API). Price comes from `pricing.registration_cost`. |
| Registration (`registration`) | `GET .../registrar/registrations/{domain}/registration-status`, then `POST .../registrar/registrations` (default synchronous window, 201 or 202) | See "Registration workflow". |
| Zone preparation (`zone_management`) | `GET /zones?name=&account.id=&per_page=50`, else `POST /zones` with `{name, account.id, type: full}` | Only an exact name match is reused. |
| Records (`records`) | `GET/POST /zones/{zone_id}/dns_records`, `PUT/DELETE /zones/{zone_id}/dns_records/{id}` | Listing follows `result_info.total_pages`. Types A, AAAA, CNAME, MX, NS, TXT. Names are sent as complete names inside the zone (`@` and relative names are expanded). MX requires `priority` (0 to 65535). `proxied` is only sent as true for A, AAAA and CNAME. SRV, CAA and other data-object types are refused. |

## Registration workflow

1. Read the existing workflow for the domain. Cloudflare allows one registration per domain, so an existing `pending`, `in_progress`, `blocked`, `action_required` or `succeeded` workflow is returned as-is and no second billable request is sent. Calling register again therefore acts as a status refresh. `action_required` is never resubmitted.
2. Run the real-time `domain-check`. Unavailable or premium domains are refused before any billable request.
3. Submit `POST /registrations` with `domain_name` and `auto_renew`, without `Prefer: respond-async`. Cloudflare holds the request for its synchronous window and returns `201` with a completed workflow in most cases, or `202` while still processing. The workflow `state` in the body is authoritative for both codes. The client timeout for this call is 45 seconds.
4. If the request fails (timeout, transport error or rejection), the workflow status is read once. A newly started workflow is reported instead of a failure; otherwise the original error is raised and the record is marked failed by the module. A later Register on that failed record reads the workflow first, so it resumes instead of paying twice.

Workflow state mapping: `pending`, `in_progress`, `blocked`, `action_required` map to `registering`; `succeeded` maps to `active` (expiry from `context.registration.expires_at`); `failed` maps to `failed` with `error.code` and `error.message` kept in the provider meta. Responses without a documented `state`, with an inconsistent `completed` flag, or for another domain are rejected.

`auto_renew: true` authorises Cloudflare to charge the account's default payment method for renewals up to 30 days before expiry. That charge happens outside Agovena billing.

## Explicitly unsupported

- Renewal: the Registrar API exposes no renewal endpoint. `renewal` is not advertised and `renew()` throws `CloudflareRegistrarOperationNotSupported`.
- Transfers, nameserver changes, contact updates, premium registrations and multi-year terms are not implemented and not advertised.
- No health check callback is registered.
- All HTTP calls are refused in the `demo` environment, redirects are never followed, and error messages never include the API token.

## Status refresh

When Cloudflare answers `202`, or the workflow is `pending`, `in_progress`, `blocked` or `action_required`, the registration stays `registering`. The Domains Module runs `agovena:refresh-domain-registrations` every five minutes. It reads `GET /registration-status` only and never submits a second billable registration. A `succeeded` workflow becomes `active` and prepares the DNS zone; `failed` becomes `failed` with the provider error kept in the registration meta. A workflow in `action_required` keeps its `context.action` in the meta so the merchant can complete it in the Cloudflare dashboard. A failed status lookup leaves the registration unchanged for the next run.

Customer DNS forms send an MX `priority` (0 to 65535). Records refused by Cloudflare validation show a form error instead of failing the request.

## Readiness

`production_ready` is `true` for the operations above. Renewal, transfers and premium registrations stay explicitly unsupported. Live Cloudflare acceptance (a real registration and DNS change on a merchant account) is a separate operator check.

Regression tests live in Core: `tests/Feature/CloudflareDomainReadinessTest.php` and `tests/Feature/CustomerDomainDnsTest.php`.
