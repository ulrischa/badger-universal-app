# Changelog

## Page editor and integration examples — 2026-10-04

- Added form-based page/row editing, page ordering, limits and live preview.
- Retained explicit JSON import/export with validation before replacement.
- Added bounded, independent Home Assistant publishers for Bitaxe and HA sensors.
- Added local/shared-hosting instructions and clarified one-shot vs scheduled operation.
- Expanded browser coverage for editing, invalid drafts, limits and persistence.

## Documentation update — 2026-10-04

- **Added step-by-step German server, Badger and usage guides.**
- **Documented the retained producer-push/device-pull design with Mermaid diagrams, alternatives, timing and outage behavior.**
- **Clarified that app registration does not start a producer and device refresh does not trigger source collection.**

## Initial implementation — 2026-10-04

- Added a PHP/SQLite app registry, device assignments and scoped publisher API.
- Added German JavaScript administration with preview and key management.
- Added bounded locks, schema validation, CSRF protection, audit and throttling.
- Added a MicroPython device client with fixed local navigation, verified HTTPS,
  alternate offline caches, watchdog recovery and retry limits.
- Added installation, protocol, operational and hardware acceptance documentation.
- Added server, HTTP, navigation, cache, transport and browser tests.

Hardware acceptance is pending. Initial mode is USB-powered read-only display.
