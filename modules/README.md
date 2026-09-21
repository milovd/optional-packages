# Modules

Optional **business capabilities** that Agovena Core can enable independently.

Modules are not merchant “store types”. A merchant may sell physical goods, digital keys, downloads, subscriptions, provisioned services, and event tickets in any compatible combination - including none of the optional Modules.

## Two levels of understanding

| Audience | Thinks in |
|----------|-----------|
| **Merchant** | Selling intents (Physical Products, Digital Products, Downloadable Products, Subscriptions & Memberships, Hosting & Provisioned Services, Events & Ticketing, Custom) |
| **Developer** | Reusable Module capabilities + Extension providers |

Store presets and product quick-start choices are helpers. They enable Modules / preselect capabilities. They never persist a permanent `store_type` or rigid `product_type` used as Core business logic.

## First-party Modules

| Stable id | Display name | Typical merchant intents |
|-----------|--------------|--------------------------|
| `downloads` | Downloads | Downloadable files / entitlements |
| `digital-delivery` | Digital Delivery | Keys, codes, licenses, credentials |
| `domains` | Domains | Domain registration and management |
| `provisioning` | Provisioning | Hosted / provisioned services (provider via Extension) |
| `events` | Events & Ticketing | Ticketed events / check-in |

**Downloads (`downloads`) ≠ Digital Delivery (`digital-delivery`).** Files and secrets are separate capabilities on purpose.

Store presets may recommend combinations of Core capabilities and optional Modules without creating hard dependencies.

## Distribution

First-party Modules ship in this monorepo and are installed into Agovena via Admin (monorepo URL or `AGOVENA_OPTIONAL_PACKAGES_PATH`). Materialized copies live under Core `storage/app/packages/modules/{id}`.

## Layout

```
modules/{module-id}/
  module.json
  src/
  database/migrations/ # optional
  lang/ # optional
```

Enable / disable from Admin → Modules. Disabling removes runtime capability and keeps Module data unless the operator explicitly purges.
