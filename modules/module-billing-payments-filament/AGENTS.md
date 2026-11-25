# Module agent guidance

<!-- module-architecture:begin -->
## Payments — Filament package boundary

Read README.md, the [capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/billing/filament/payments.md), [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) and [module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md). Exact source key: `liberusoftware/module-billing-payments`. Actual Composer identity: `liberusoftware/module-billing-payments-filament`.

- Preserve public source/package identities and application extension hooks. Do not silently replace canonical domain owners or import host App classes into reusable code.
- Keep policy, tenant context, validation, transactions, owned records and durable events authoritative in their core packages. Register only this capability's reviewed Filament resources/pages/widgets.
- Follow actual manifests and lockfiles. Use declared test/build scripts for implementation changes and meaningful allowed/denied/wrong-tenant checks for protected behavior.
- Keep secrets outside source. Distinguish installed, enabled, entitled and authorized states; do not treat UI visibility as authorization.
- Preserve existing implementation and upstream notices. Skeleton availability is not proof of feature parity or a published release.
<!-- module-architecture:end -->
