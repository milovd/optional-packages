# Agovena Optional Packages

Official monorepo of optional **Modules** and **Extensions** for [Agovena](https://github.com/milovd/Agovena) — the open-source modular commerce platform.

Agovena Core stays generic. Install only the capabilities your shop needs.

## Repository layout

```text
optional-packages/
├── modules/
│   ├── inventory/
│   ├── shipping/
│   ├── digital/
│   ├── digital-delivery/
│   ├── subscriptions/
│   ├── provisioning/
│   └── events/
└── extensions/
    ├── payments/
    │   ├── manual-payment/
    │   ├── mollie/
    │   └── stripe/
    ├── provisioning/
    │   └── pterodactyl/
    └── shipping/
        └── postnl/
```

Package identity comes from each package manifest (`module.json` or `extension.json` `id` field), not from the folder path.

## Installation (from Agovena Core)

1. Set in Agovena `.env`:

   ```env
   AGOVENA_PACKAGES_MONOREPO_URL=https://github.com/milovd/optional-packages
   ```

2. In **Admin → Modules** or **Admin → Extensions**, use **Install** on an available package.

3. Agovena clones this repository (cached under `storage/app/packages/monorepo-cache/`), copies the mapped subdirectory into `storage/app/packages/modules/{id}` or `storage/app/packages/extensions/{id}`, registers autoloading, and runs the package lifecycle (`install` → `enable`).

## Updates

When a new version is tagged or merged to `main`, use **Update** in Admin on an installed monorepo package. Agovena re-fetches the configured git ref (default `main` or a semver tag) and refreshes the materialized copy.

## Development

Clone this repo beside Agovena Core for local development without repeated git checkouts:

```env
AGOVENA_OPTIONAL_PACKAGES_PATH=../optional-packages
```

Core will discover packages from that path via `extra_module_paths` / `extra_extension_paths`.

## License

MIT — see [LICENSE](LICENSE).
