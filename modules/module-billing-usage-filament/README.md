# Billing Usage Filament

Filament 5 adapter for managing authorized usage meters and reviewing usage records.

<!-- module-presentation-guide:begin -->
## Presentation architecture and project hosts

- Capability/source key: `liberusoftware/module-billing-usage`.
- Actual Composer identity: `liberusoftware/module-billing-usage-filament`.
- [Filament capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/billing/filament/usage.md) · [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) · [Module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md).

The application owns panel composition, routing, session integration and deployment. This module owns its focused presentation and delegates domain behavior to reviewed public owner contracts. Keep host App aliases, duplicate records and client-only authorization outside reusable package boundaries.

Documented consumer project families:

- [Billing](https://github.com/liberusoftware/project-billing-laravel-filament): Subscriptions, pricing, payments and provisioning.

Other web, mobile and desktop presentations are listed in the [project catalog](https://github.com/liberusoftware/documentation/blob/main/projects/REPOSITORIES.md). Those skeletons provide starting structures; links do not establish feature parity, compatible releases or completed device/API integrations. Independent presentation packages keep their own Composer, npm or pub metadata.
<!-- module-presentation-guide:end -->
