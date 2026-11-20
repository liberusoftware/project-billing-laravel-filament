# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [6.2.0] - 2026-11-20

### Added
- Integrated the `Modules` Pest test suite across foundation, billing, and platform modules with isolated host-side test cases in `tests/ModuleTestCases/` and `tests/ModulePackageTestCase.php`.
- Added idempotent migrations for `liberu_workflow_runs`, `liberu_metric_definitions`, and `liberu_platform_compositions`.

### Changed
- Migrated unit test coverage for Paddle and Stripe to the `liberusoftware/payment-paddle` and `liberusoftware/payment-stripe` adapters.
- Upgraded `liberusoftware/module-manager` to `1.10.0`.

## [Unreleased]

### Added
- Initial documentation structure with CHANGELOG, CONTRIBUTING, and SECURITY policies

### Changed
- Enhanced project documentation and specifications
