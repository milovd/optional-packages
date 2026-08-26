# Extensions

Easy plugins that extend Agovena through public seams. Distinct from Modules.

- **Modules** add platform capabilities/domains (events/tickets, inventory, shipping, digital, provisioning, …).
- **Extensions** plug into existing seams without forking Core: payment/shipping/provisioning gateways, Admin tabs/pages/settings, cart/checkout requirements, invoice presentation.

Agovena exposes explicit contracts for registering packages and connecting them to supported extension seams. Extensions do not fork Core or depend on a specific admin UI framework.

## Distribution

Install from Agovena Admin via the optional-packages monorepo (`AGOVENA_PACKAGES_MONOREPO_URL`) or a local path (`AGOVENA_OPTIONAL_PACKAGES_PATH`). Prefer Composer/GitHub oriented installs. Treat uploaded ZIP PHP as high trust - only use reviewed packages.

## Layout

First-party Extensions are organized by category for clarity. Identity always comes from `extension.json` `id`, **not** the filesystem path.

```
extensions/
  payments/
    mollie/
    stripe/
    paypal/
  provisioning/
    cpanel/
    convoy/
    directadmin/
    enhance/
    plesk/
    pterodactyl/
    proxmox/
    virtfusion/
    virtualizor/
  shipping/
    postnl/
```

Flat `extensions/{id}/` remains supported for third-party and legacy layouts. Category folders may also look like `extensions/{category}/{id}/`.

```
extensions/{category?}/{id}/
  extension.json
  src/
```

Lifecycle: discover → install → enable / disable → uninstall. Disable preserves settings and data.

## Categories

Manifest `category` values: `payment_gateway`, `provisioning`, `shipping`, `authentication`, `storage`, `notifications`, `analytics`, `tax`, `other`

Use `other` (or a matching provider category) for Admin/cart/invoice plugins that are not a gateway. Filesystem folder names for first-party packages use friendlier labels where useful (`payments/` for `payment_gateway`). Folder name is convenience only.

## Public seams

From `Extension::register(ExtensionContext $context)`:

1. `$context->admin()` - navigation, pages, settings, widgets, permissions (for example an invoice-layout tab)
2. `$context->cartRequirements(...)` - contribute or extend checkout/cart requirements
3. `$context->invoiceDocument('theme::invoices.document')` - override invoice HTML/PDF presentation
4. `$context->paymentGateway(...)` / `provisioner(...)` / `shippingCarrier(...)`
5. `$context->setting(...)` and `$context->health(...)`

Extensions must not bypass server-authoritative prices, webhook authority, or Admin permissions.

## Reference

`extensions/payments/mollie` is the first production Payment Extension: hosted checkout, webhooks, refunds, and status sync behind the generic `PaymentGateway` contracts.
`extensions/payments/stripe` is the second production Payment Extension: Stripe Checkout, signed webhooks, refunds, and off-session charges behind the same contracts.
`extensions/payments/paypal` is the third production Payment Extension: PayPal Checkout behind the same contracts.
`extensions/provisioning/cpanel`, `convoy`, `directadmin`, `enhance`, `plesk`, `virtfusion`, and `virtualizor` provide first-party server lifecycle adapters behind the shared provisioning support seam. Their manifests, settings, registry wiring, error handling, and fake HTTP contracts are tested. Live provider sandbox verification remains a separate deployment gate.
`extensions/provisioning/pterodactyl` is the first production Provisioning Extension: panel lifecycle behind the generic `Provisioner` contracts.
`extensions/provisioning/proxmox` is the second production Provisioning Extension: Proxmox VE lifecycle behind the same contracts.
`extensions/shipping/postnl` is the first production Shipping Extension: barcodes, labels, and tracking behind the generic `ShippingCarrier` contracts.

## Implementing a Payment Extension

1. Add `extensions/payments/{id}/extension.json` (or flat `extensions/{id}/`) with `category: payment_gateway`
2. Implement `App\Agovena\Payments\Contracts\PaymentGateway`
3. Register it from `Extension::register()` via `$context->paymentGateway(...)`
4. Store secrets with `$context->setting(..., secret: true)` - encrypted, never redisplayed, never logged
5. Optional seams: `OffersCheckoutMethods`, `SynchronizesPayments`, `CancelsPayments`, `ChargesRecurringPayments`
6. Return URLs are UX only. Verify provider status by fetching the provider resource (or the provider’s documented webhook model). Do not trust customer-supplied status query params.
7. Tests must fake the provider HTTP/SDK. CI must not require live credentials.

## Implementing a Provisioner Extension

1. `category: provisioning`
2. Implement `Provisioner` plus optional `ProvisionerLifecycle`, `ProvisionerActions`, `ProvisionerPanel`, `ConfiguresProvisionedProducts`
3. Register via `$context->provisioner(...)`
4. Product mapping belongs in Extension-owned `provider_settings` on the provisionable capability - never Core columns such as vendor ids
5. Disable preserves Service Instance data

## Implementing a Shipping Extension

1. `category: shipping`
2. Implement `ShippingCarrier` plus optional `QuotesShippingRates`, `QuotesCartRates`, `CreatesCarrierShipments`, `TracksShipments`
3. Register via `$context->shippingCarrier(...)`
4. Provider service codes, labels, and tracking stay Extension-owned. Generic Shipment may store `carrier_id`, `external_ref`, tracking, and a private label path
5. Tests must fake provider HTTP. CI must not require live credentials

Mollie/Stripe/PayPal/Pterodactyl/Proxmox/PostNL-specific types must not appear in Core or Modules.
