# Security and failure model

## Trust boundaries

- The administrator owns all apps/devices. This is a single-administrator hub,
  not a multi-tenant platform; no role hierarchy or public registration.
- An app producer holds a 256-bit random publisher key. It may replace only that
  app's bounded display data. There are no dynamic PHP includes or executable plugins.
- A device holds a separate random key and reads assigned display data only.
- A physically accessible Badger does not provide secure secret storage.
- TLS terminator, PHP worker, filesystem and database are trusted infrastructure.

## Controls

| Threat | Control |
|---|---|
| Credential guessing | Long password, password hashing, global and per-IP login limits |
| API key theft from DB dump | SHA-256 hashes of random 256-bit keys; one-time display |
| Cross-device/app access | Scope derived from authenticated key; not client-supplied ID |
| Cross-site mutation | CSRF token, Origin and Fetch Metadata checks, SameSite=Strict |
| Script injection | DOM textContent, strict CSP, no inline scripts/eval/HTML rendering |
| SQL injection | Parameterized statements; dynamic table names from fixed allowlist |
| SSRF / slow upstreams | No outbound network calls in the hub |
| Concurrent overwrites | Version preconditions and short BEGIN IMMEDIATE transactions |
| Lock hangs | Nonblocking session flock with 1s deadline; SQLite busy timeout 2s |
| Slow clients/native hangs | Web-server read limits and PHP-FPM hard process timeout |
| Broken Wi-Fi/server | Offline cache, bounded retries, five-failure pause, fixed local menu |
| Device native DNS/TLS hang | 8s hardware watchdog, boot recovery without automatic requests |
| Corrupt/truncated payload | Byte/count/schema limits and validation before replacement |
| Power loss during cache save | Alternate slots; previous valid file retained |
| Stale content | TTL and explicit offline/old-data indicators |

The standard firmware has no mechanism for PHP/JavaScript execution. MicroPython
is therefore restricted to the display client. Its API accepts only data.

## Operational limits

The application is not a denial-of-service perimeter. Configure edge request and
connection limits; protect the administration behind a VPN or trusted access proxy
where appropriate. A single global login cap trades availability for bounded
password hashing and can be exhausted intentionally. Failed authentication events
should be observed at the web server; admin audit does not store passwords, IPs,
payloads, API keys or request headers. Publisher changes do not flood audit history.

HTTPS verification fails closed if the firmware, CA, host or clock is unsuitable.
No insecure transport toggle is shipped. Certificate/RTC provisioning is an
installation responsibility, and certificate renewal must be tested.

A disabled device can retain old cached data. E-Ink can retain a readable image
without power. Do not put secrets on the screen. Delete caches and credentials
physically when decommissioning a badge.

Bounded software waits do not establish a guarantee against faulty hardware,
filesystem stalls or operating-system failures. Keep physical maintenance access.
The device's C+reset maintenance route deliberately runs before enabling WDT.

## Release scope

No third-party Composer/npm packages are used by the runtime. Dependency auditing
therefore applies to the maintained OS/PHP/SQLite/MicroPython/Pimoroni components.
Development-only browser tooling is not included in the deployed app.

Review the hardware acceptance checklist and hosting settings before unattended
use. No real-hardware test or external penetration test is claimed.

## Data-bound layouts

Hub-managed apps accept bounded scalar snapshots through `publish-values`. Publisher
tokens cannot write their layouts. Field bindings are literal identifiers, not code,
expressions or URLs. Data and layout revisions are separate; data writes atomically
check revision, current token, enabled state and mode. Mode changes clear stored
values and advance their revision. Legacy page publishing is restricted to `pages`
mode. Missing fields become placeholders and display overflow is explicit.

Schema v1 upgrades are transactional under the existing bounded SQLite write lock.
No network I/O or session lock is held during migration. Back up before deployment.
