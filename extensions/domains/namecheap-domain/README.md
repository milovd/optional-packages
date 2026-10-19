# Namecheap Domains

Optional Agovena extension for the `domains` module. It registers two providers:

- `namecheap-registrar` (`DomainRegistrar`, `RefreshesRegistrationStatus`): availability check, registration and renewal through the Namecheap XML API.
- `namecheap-dns` (`DomainDnsProvider`): host record management on Namecheap BasicDNS for domains in the same Namecheap account.

Status: `production_ready: true`. See "Readiness" below.

## Settings

| Key | Notes |
| --- | --- |
| `api_user` | Namecheap API user (`ApiUser`). |
| `api_key` | Stored as a secret. Sent only in the POST body, never in a URL, log or exception message. |
| `username` | Namecheap account the commands run for (`UserName`). |
| `client_ip` | Public IPv4 address of this server (`ClientIp`). It must be on the API allowlist in the Namecheap dashboard; Namecheap accepts IPv4 only, so other values are refused before any request. |
| `sandbox` | `true` (default) uses the sandbox host, `false` uses the live host. |

## Hosts

| Environment | Endpoint |
| --- | --- |
| Sandbox | `https://api.sandbox.namecheap.com/xml.response` |
| Live | `https://api.namecheap.com/xml.response` |

Sandbox and live accounts, API keys and allowlists are separate. Every command is an HTTP POST (form encoded) to the endpoint above with the global parameters `ApiUser`, `ApiKey`, `UserName`, `ClientIp` and `Command`.

## Endpoints

All commands were checked against the official reference at `https://www.namecheap.com/support/api/methods/`.

| Command | Used for | Parameters sent | Response read |
| --- | --- | --- | --- |
| `namecheap.domains.check` | Availability, and the pre-registration check | `DomainList` | `DomainCheckResult@Domain, Available, ErrorNo, IsPremiumName, PremiumRegistrationPrice, EapFee, IcannFee` |
| `namecheap.users.getPricing` | Price shown with an available domain (cached for one hour) | `ProductType=DOMAIN`, `ActionName=REGISTER`, `ProductName=<TLD>` | `ProductCategory[REGISTER]/Product/Price@Duration, DurationType, Price, Currency` |
| `namecheap.domains.getInfo` | Ownership, expiry and idempotency checks | `DomainName` | `DomainGetInfoResult@ID, DomainName, IsOwner, IsPremium, Status` and `DomainDetails/ExpiredDate` |
| `namecheap.domains.create` | Registration (billable) | `DomainName`, `Years`, and for each of `Registrant`, `Tech`, `Admin`, `AuxBilling`: `FirstName`, `LastName`, `Address1`, `City`, `StateProvince`, `PostalCode`, `Country`, `Phone`, `EmailAddress`, plus `OrganizationName` and `Address2` when present | `DomainCreateResult@Domain, Registered, NonRealTimeDomain, DomainID, OrderID, TransactionID, ChargedAmount` |
| `namecheap.domains.renew` | Renewal (billable) | `DomainName`, `Years` | `DomainRenewResult@DomainName, Renew, DomainID, OrderID, TransactionID, ChargedAmount` and `DomainDetails/ExpiredDate` |
| `namecheap.domains.dns.getList` | Zone check | `SLD`, `TLD` | `DomainDNSGetListResult@Domain, IsUsingOurDNS` and `Nameserver` |
| `namecheap.domains.dns.getHosts` | Listing records, and the read step of every change | `SLD`, `TLD` | `DomainDNSGetHostsResult@Domain, IsUsingOurDNS, EmailType` and `host@Name, Type, Address, MXPref, TTL` |
| `namecheap.domains.dns.setHosts` | The write step of every record change | `SLD`, `TLD`, `EmailType`, `HostName[n]`, `RecordType[n]`, `Address[n]`, `MXPref[n]` (MX only), `TTL[n]` | `DomainDNSSetHostsResult@Domain, IsSuccess` |

`SLD` is the first label of the registered domain and `TLD` the rest (`example.co.uk` is `example` + `co.uk`).

## Response handling

- Namecheap reports errors inside an HTTP 200 body. Only `ApiResponse Status="OK"` with a `CommandResponse` for the requested command is accepted. `Status="ERROR"` raises `NamecheapRequestRejected` with the documented error number only; the provider error text is dropped because it can echo request values.
- Any other answer (non 2xx status, a redirect, an empty or non XML body, a different root element, a missing or unknown status, a missing result node) is a failure. XML with a `DOCTYPE` is refused before parsing and external entities are never loaded.
- Redirects are never followed, so the API key cannot be forwarded to another host.
- For the billable commands (`domains.create`, `domains.renew`) every non definitive answer, including timeouts, is reported as "outcome is unknown; reconcile the domain before retrying". Retrying is safe because both workflows start with read-only checks (see below).
- `getInfo` errors `2019166` (domain not found) and `2016166` (domain is not associated with your account) mean "not in this account". Every other error is raised.
- All HTTP calls are refused in the `demo` environment, before any request is built.

## Supported operations

### Availability (`availability_check`)

1. IDN names (`xn--` labels) and TLDs for which `domains.create` documents mandatory extended attributes (`.us`, `.eu`, `.ca`, `.co.uk`, `.org.uk`, `.me.uk`, `.nu`, `.com.au`, `.net.au`, `.org.au`, `.es`, `.nom.es`, `.com.es`, `.org.es`, `.de`, `.fr`) are reported unavailable (`idn_not_supported`, `tld_not_supported`) without calling Namecheap.
2. `domains.check` must return the requested domain with `ErrorNo="0"`, `Available="true"`, not premium, and without a premium registration price or EAP fee. Otherwise the reason is `domain_not_returned`, `provider_error`, `unavailable` or `premium_not_supported`. A failed check returns `provider_unavailable` instead of throwing.
3. The price is the account's one year `REGISTER` price from `users.getPricing`. Without a usable price the domain is still available with `price_minor: null` and reason `price_unavailable`. ICANN fees, when charged, are part of `ChargedAmount`.

### Registration (`registration`)

1. Unsupported TLDs and IDN names are refused with `NamecheapOperationNotSupported` before any call.
2. The contact is built from the customer's default billing address (or the oldest address) and used for all four roles. A first and last name, address line, city, region (`StateProvince`), postal code, ISO 3166-1 alpha-2 country, an email address and a phone number written as `+<country code> <number>` are required; the phone is sent as `+NNN.NNNNNNNNNN`. Missing or invalid fields are refused before any call; the message names the field and never repeats customer data.
3. `domains.getInfo`: a domain already in this account is reported as `active` with its expiry (`meta.reconciled: true`) and is not registered again. A domain in the account that the API user does not own is refused.
4. `domains.check` must confirm a regular, available domain, otherwise the registration is refused before the billable call.
5. `domains.create` for the configured term (`provider_settings.years`, 1 to 10). `Registered="false"` or a result for another domain maps to `failed`. `NonRealTimeDomain="true"` maps to `registering`.
6. For a real-time registration `domains.getInfo` is read again for the expiry: an owned domain with an expiry maps to `active`; otherwise the registration stays `registering` and the status refresh confirms it.

Domain privacy is not requested (`AddFreeWhoisguard` and `WGEnabled` keep their default `no`). No custom nameservers are sent, so new domains use Namecheap BasicDNS.

### Status refresh

`NamecheapRegistrar` implements `RefreshesRegistrationStatus`. The Domains Module runs `agovena:refresh-domain-registrations` for registrations in `registering`. The refresh only reads `domains.getInfo` and never submits `domains.create`: an owned domain becomes `active` with its expiry, a domain that is not yet in the account stays `registering` with its earlier provider meta kept. A failed lookup leaves the registration unchanged for the next run.

### Renewal (`renewal`)

1. `domains.getInfo` must show the domain in this account, owned by the API user and not premium (premium renewal needs a premium price and is refused with `NamecheapOperationNotSupported`).
2. The registration needs a recorded expiry date.
3. If the provider expiry already equals the recorded expiry plus the requested years (one day tolerance), an earlier renewal whose response was lost is reported as `active` with the new expiry (`meta.reconciled: true`) and nothing is charged.
4. If the provider expiry equals the recorded expiry (one day tolerance), `domains.renew` is sent. Any other expiry is refused as a mismatch that needs manual reconciliation.
5. `Renew="true"` for the same domain maps to `active` with the expiry from `DomainDetails/ExpiredDate` (read from `domains.getInfo` when the response has none). Anything else maps to `failed` and keeps the recorded expiry.

### DNS zone (`zone_management`)

`ensureZone` reads `domains.dns.getList`. It is read-only: when `IsUsingOurDNS` is true it returns the domain as zone reference and the Namecheap nameservers. A domain delegated to other nameservers is refused with `NamecheapOperationNotSupported`; nameservers are never changed.

### DNS records (`records`)

- Listing returns every host with `type`, `name` (relative, `@` for the apex), `content`, `ttl`, `priority` for MX, and an `id`. Namecheap host IDs are not stable across `setHosts`, so the `id` is a fingerprint of type, name, value and MX priority. A record that changed or disappeared since it was listed no longer matches and the change is refused.
- Create, update and delete are read-modify-write: `getHosts`, change one entry, `setHosts` with the complete list. All other hosts are written back exactly as read, including MX priority (`MXPref`) and TTL, and including types this extension does not edit (URL, URL301, FRAME, CAA, ALIAS, NS, MXE). The sequence runs under a cache lock per domain (`namecheap-domain:dns:<domain>`, 60 seconds, 20 second wait), so concurrent changes cannot overwrite each other. The cache store must support atomic locks (Redis, database, file, array, Memcached, DynamoDB).
- Editable types: A, AAAA, CNAME, MX and TXT. A requires IPv4, AAAA requires IPv6, MX requires a priority between 0 and 65535, and the TTL must be between 60 and 60000 seconds (the documented Namecheap range). Names may be `@`, relative, or complete names inside the domain.
- `EmailType`: custom MX hosts are written with `EmailType=MX`. When the domain uses a Namecheap mail setting (for example `FWD` email forwarding or `OX` Private Email), adding or keeping MX hosts is refused instead of switching that mail setup. Without MX hosts the current `EmailType` is sent back unchanged.
- Changes on a domain that is not on Namecheap BasicDNS are refused, because Namecheap would not serve them.

## Explicitly unsupported

- TLDs that need extended attributes, IDN registrations, premium and Early Access registrations, and premium renewals.
- Transfers, reactivation, contact updates, domain privacy, registrar lock, auth codes and nameserver changes (including `domains.dns.setDefault` and `domains.dns.setCustom`).
- Editing URL, URL301, FRAME, CAA, ALIAS, NS and MXE hosts (they are preserved and can be deleted), email forwarding and dynamic DNS.
- Child nameservers and no health check callback.

These operations are not advertised. Through the provider contracts, unsupported TLD and IDN registrations, premium renewals and domains that are not on Namecheap BasicDNS throw `NamecheapOperationNotSupported`; premium and Early Access registrations are refused by the pre-registration check before any billable call.

## Readiness

Every advertised operation (`availability_check`, `registration`, `renewal`, `zone_management`, `records`) and the status refresh are implemented against the documented commands above and covered by Core regression tests: `tests/Unit/NamecheapApiTest.php`, `tests/Feature/Domains/NamecheapDomainReadinessTest.php` and `tests/Feature/Domains/UnifiedDomainExtensionTest.php`, with `Http::fake` and in-memory fakes only.

`production_ready` is `true` for the operations above. Everything listed under "Explicitly unsupported" stays unsupported.

Live Namecheap acceptance (a sandbox and a live registration, renewal and DNS change from an allowlisted IPv4 address) is a separate operator check.
