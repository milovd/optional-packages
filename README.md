# Agovena Optional Packages

Official Modules and Extensions for [Agovena](https://github.com/milovd/Agovena), an open-source, self-hosted and modular commerce platform.

This repository contains first-party packages that add optional commerce capabilities and provider integrations without making them part of Agovena Core.

## Packages

- **Modules** add capabilities such as downloads, digital delivery, domains, events and provisioning.
- **Extensions** connect providers for payments, domains, provisioning and shipping.

Each package declares its identity and contract in a `module.json` or `extension.json` manifest.

## Using the packages

Install and manage packages from Agovena Core through the Admin interface. The [Agovena documentation](https://agovena.com/docs) covers installation, package management and operations.

For architecture and local development, see the [developer documentation](https://agovena.com/development). Available first-party packages are listed in the [Agovena Marketplace](https://agovena.com/marketplace).

## Contributing

Keep package code, manifests and documentation within the package boundary. Changes that affect the shared package contract belong in the [Agovena Core repository](https://github.com/milovd/Agovena).

Please open focused issues and pull requests with the affected package clearly identified.

## License

MIT. See [LICENSE](LICENSE).
