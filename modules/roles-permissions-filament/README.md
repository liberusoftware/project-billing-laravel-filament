# Liberu Authorization Filament

> Filament role and permission management through the authorization capability.

[Software](https://liberusoftware.com) · [Hosting](https://liberuhosting.com) · [Services](https://liberuservices.com) · [Liberu Group](https://liberugroup.com)

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/) [![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-roles-permissions-filament?sort=semver)](https://github.com/liberusoftware/module-roles-permissions-filament/releases/latest) [![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE.md)

## Feature scope

Filament role and permission management through the authorization capability. This repository owns this capability as an independently versioned Composer package; hosts integrate it through its declared public boundaries rather than application-specific classes.

### Capabilities

- `foundation.authorization.filament`

### Declared features

- Filament role and permission management through the authorization capability

## Requirements and installation

| Dependency | Supported version |
|---|---|
| `php` | `^8.5` |
| `bezhansalleh/filament-shield` | `~4.0` |
| `filament/filament` | `^5.1` |
| `liberusoftware/roles-permissions` | `^1.0` |

Install the released package from the project root:

```bash
composer require liberusoftware/roles-permissions-filament
```

The trusted `liberusoftware/composer-installer` places it in `/modules/roles-permissions-filament`. The installed directory remains tracked by the host repository, while Composer and its lock file remain the source of version truth. Installation does not imply runtime enablement or commercial entitlement.

## Architecture and integration

- Composer package: `liberusoftware/roles-permissions-filament`
- Package type: `liberu-module`
- Installer name: `roles-permissions-filament`
- Category: `presentation`
- Service provider: `Liberu\Foundation\RolesPermissionsFilament\RolesPermissionsFilamentServiceProvider`
- Enabled by default: `no`

### Public contracts

- No separate contract type is declared; use the documented provider and capability boundary.

### Commands

- No console command is provided.

### Events

- No package-owned event class is currently published.

### Persistence and permissions

- This package owns no database migration.

Authorization remains the host application's responsibility unless a public authorizer contract is listed above. Consumers should grant only the permissions needed for the exported capabilities and must not couple to internal tables or classes.

## Testing

The package includes 2 test file(s). From a compatible host checkout, run:

```bash
vendor/bin/pest modules/roles-permissions-filament/tests
```

Changes must preserve the manifest, service-provider integration, package boundaries, and PHP/Laravel compatibility declared above.

## Security

Do not report security vulnerabilities through public GitHub issues. Email `security@liberusoftware.com` with reproduction details and the affected version so the report can be handled privately.

## License

This module is open-source software available under the [MIT License](LICENSE.md). The linked licence text is authoritative.

## Feedback and contributing

Focused issues and tested pull requests are welcome in the [GitHub repository](https://github.com/liberusoftware/module-roles-permissions-filament). Keep changes within this module's capability boundary, update tests and documentation, and record user-visible changes in `CHANGELOG.md`.

## Contributors

Thank you to everyone who helps improve Liberu. [View the contributors graph](https://github.com/liberusoftware/module-roles-permissions-filament/graphs/contributors).

<!-- module-presentation-guide:begin -->
## Presentation architecture and project hosts

- Capability/source key: `liberusoftware/roles-permissions`.
- Actual Composer identity: `liberusoftware/roles-permissions-filament`.
- [Filament capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/boilerplate/filament/roles-permissions.md) · [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) · [Module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md).

The application owns panel composition, routing, session integration and deployment. This module owns its focused presentation and delegates domain behavior to reviewed public owner contracts. Keep host App aliases, duplicate records and client-only authorization outside reusable package boundaries.

Documented consumer project families:

- [Boilerplate](https://github.com/liberusoftware/project-boilerplate-laravel-filament): Shared application foundation and module composition.
- [Accounting ERP](https://github.com/liberusoftware/project-accounting-erp-laravel-filament): Ledgers, tax, reporting and finance workflows.
- [Automation](https://github.com/liberusoftware/project-automation-laravel-filament): AI gateways, approvals, rules and integrations.
- [Billing](https://github.com/liberusoftware/project-billing-laravel-filament): Subscriptions, pricing, payments and provisioning.
- [Browser Game](https://github.com/liberusoftware/project-browser-game-laravel-filament): Game worlds, players and community workflows.
- [Control Panel](https://github.com/liberusoftware/project-control-panel-laravel-filament): Infrastructure and hosting operations.
- [CRM](https://github.com/liberusoftware/project-crm-laravel-filament): Relationships, sales pipelines and customer service.
- [Genealogy](https://github.com/liberusoftware/project-genealogy-laravel-filament): Family trees, research, evidence and collaboration.
- [Maintenance](https://github.com/liberusoftware/project-maintenance-laravel-filament): Assets, work orders, schedules and field operations.
- [Real Estate](https://github.com/liberusoftware/project-real-estate-laravel-filament): Listings, sales, lettings and agency operations.
- [Social Network](https://github.com/liberusoftware/project-social-network-laravel-filament): Profiles, feeds, communities and moderation.

Other web, mobile and desktop presentations are listed in the [project catalog](https://github.com/liberusoftware/documentation/blob/main/projects/REPOSITORIES.md). Those skeletons provide starting structures; links do not establish feature parity, compatible releases or completed device/API integrations. Independent presentation packages keep their own Composer, npm or pub metadata.
<!-- module-presentation-guide:end -->
