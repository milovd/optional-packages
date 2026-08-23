# Modules

Optional **business capabilities** that Agovena Core can enable independently.

Modules are not merchant “store types”. A merchant may sell physical goods, digital keys, downloads, subscriptions, provisioned services, and event tickets in any compatible combination — including none of the optional Modules.

## Two levels of understanding

| Audience | Thinks in |
|----------|-----------|
| **Merchant** | Selling intents (Physical Products, Digital Products, Downloadable Products, Subscriptions & Memberships, Hosting & Provisioned Services, Events & Ticketing, Custom) |
| **Developer** | Reusable Module capabilities + Extension providers |

Store presets and product quick-start choices are helpers. They enable Modules / preselect capabilities. They never persist a permanent `store_type` or rigid `product_type` used as Core business logic.

## First-party Modules

| Stable id | Display name | Typical merchant intents |
|-----------|--------------|--------------------------|
| `inventory` | Inventory | Physical products (stock) |
| `shipping` | Shipping & Fulfillment | Physical delivery, shipments, returns |
| `digital` | Downloads | Downloadable files / entitlements |
| `digital-delivery` | Digital Delivery | Keys, codes, licenses, credentials |
| `subscriptions` | Subscriptions | Recurring billing / memberships |
| `provisioning` | Provisioning | Hosted / provisioned services (provider via Extension) |
| `events` | Events & Ticketing | Ticketed events / check-in |

**Downloads (`digital`) ≠ Digital Delivery (`digital-delivery`).** Files and secrets are separate capabilities on purpose.

True technical dependencies are declared in each Module’s `module.json`. Presets may *recommend* combinations (e.g. Inventory + Shipping) without creating hard dependencies.

## Layout

```
modules/{module-id}/
  module.json
  src/
  database/migrations/   # optional
  lang/                  # optional
```

Enable / disable from Admin → Modules. Disabling removes runtime capability and keeps Module data unless the operator explicitly purges.
