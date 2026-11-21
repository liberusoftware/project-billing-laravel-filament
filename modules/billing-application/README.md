# Billing application layout

The Billing Filament host groups application-specific code by capability. Laravel boots
the shared providers and routes in `billing-application` and resolves the existing
`App\` and `Database\` classes through Composer. This keeps queued class names and
stored model references compatible. Keep migration filenames stable so existing databases
recognise their migration records.

The capability directories below are composed by the Billing host. Independently
installable packages use their own Composer metadata, module manifests and
registry lifecycle. Each owns the contracts and data documented by its package.
Host integrations may still use the original application models and tables.

| Directory under `modules/` | Ownership |
| --- | --- |
| `billing-application` | Laravel composition, shared entry points and cross-capability integration tests |
| `billing-audit` | Billing audit log and application adapters |
| `billing-catalog` | Products, services and package groups |
| `billing-collections` | Invoice reminders, late fees, disputes and suspension |
| `billing-communications` | VoIP billing, call rates, email and SMS |
| `billing-customer-portal` | Client records, portal services and storefront |
| `billing-domains` | Availability, renewals, registrars and DNS |
| `billing-files` | Application files, folders and shares |
| `billing-filament` | Panel registration, discovery and shared UI |
| `billing-hosting` | Hosting fulfillment and control-panel integration |
| `billing-identity` | Billing users, teams, roles and account access |
| `billing-integrations` | External connections, Git, webhooks and domain event transport |
| `billing-invoicing` | Invoices, credits, recurring invoice generation and templates |
| `billing-isp` | Access services, RADIUS and IP address management |
| `billing-licensing` | License validation, release delivery and the licensing SDK |
| `billing-money` | Application currency and tax workflows and Billing Core integration tests |
| `billing-orders` | Orders, quotes and order forms |
| `billing-payments` | Gateways, checkout, refunds and payment reconciliation |
| `billing-pricing` | Price and discount calculation |
| `billing-projects` | Projects, tasks, time tracking and project billing |
| `billing-provisioning` | Service provisioning and automation |
| `billing-referrals` | Affiliate accounts, transactions and referral reporting |
| `billing-reporting` | Reports, generation and export |
| `billing-search` | Saved searches and project search integration |
| `billing-settings` | Application site settings and administration |
| `billing-subscriptions` | Subscription lifecycle and change processing |
| `billing-support` | Tickets, knowledge base, canned replies and inbound email |
| `billing-usage` | Usage records and import |

Capabilities place their application classes in `src/` and accompanying migrations,
factories, seeders, views and tests under `database/`, `resources/` and `tests/` as
needed. Capability-specific Filament classes live with their owners. Panel providers
discover those classes and compose the published plugins. The existing extension
loader remains available in `app/Modules`.

The Composer package `liberusoftware/module-manager` loads the registered Billing
packages. Its `php artisan module:list` and `module:validate` commands inspect
that registry. The separate `php artisan module …` commands still use
`app/Modules/` to manage the existing extension format, including `BlogModule`.

Run Artisan, Vite and Pest from the root.

```sh
vendor/bin/pest --testsuite=Unit,Feature,Architecture
vendor/bin/pest --testsuite=Modules
```
