# Agovena Optional Packages

Official Modules and Extensions for [Agovena](https://github.com/milovd/Agovena), an open-source, self-hosted and modular commerce platform.

This repository contains first-party packages that add optional commerce capabilities and provider integrations without making them part of Agovena Core.

## Packages

- **Modules** add capabilities such as downloads, digital delivery, domains, events and provisioning.
- **Extensions** connect providers for payments, domains, provisioning and shipping.

Each package declares its identity and contract in a `module.json` or `extension.json` manifest. A package may declare `"logo": "resources/images/logo.png"` for a bundled package mark. This optional field is presentation metadata, not a package ID, dependency, provider, installation source or permission.

A consuming UI must use its generic icon when the field is absent or unsafe. Accept only package-local relative PNG paths under `resources/images/` after rejecting traversal, URLs, symlinks, missing files and invalid image bytes. Never load a manifest URL or use an image path to influence installation. The Core image resolver and fallback are not implemented in this repository. Themes and their optional `theme.json` previews live in Core, not here.

A package owns its own views, translations and migrations. Views live in `resources/views/` and are registered under the package namespace with `loadViewsFrom()` (for example `provisioning::admin.show` or `paypal::checkout`); storefront pages render the active Theme's views through `$theme->view(...)` so Themes control storefront presentation.

## Versioning

Bump a package's `version` whenever its shipped files change; installed packages only change when they are updated. The `agovena` field declares the Core versions the package runs on. While Core is `0.x`, Core patch releases keep packages working and minor releases may not, so declare a range such as `>=0.0.1 <0.1.0` rather than a caret constraint. Core can also require a minimum version of a first-party package; see the Core `CONTRIBUTING.md`.

## Using the packages

Install and manage packages from Agovena Core through the Admin interface. The [Agovena documentation](https://agovena.com/docs) covers installation, package management and operations.

For architecture and local development, see the [developer documentation](https://agovena.com/development). Available first-party packages are listed in the [Agovena Marketplace](https://agovena.com/marketplace).

## Contributing

Keep package code, manifests and documentation within the package boundary. Changes that affect the shared package contract belong in the [Agovena Core repository](https://github.com/milovd/Agovena).

Please open focused issues and pull requests with the affected package clearly identified.

## License

MIT. See [LICENSE](LICENSE).
