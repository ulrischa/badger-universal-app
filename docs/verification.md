# Verification record and hardware acceptance

## Verified in the implementation environment

Date: 2026-10-04. PHP 8.3.6 (Ubuntu-maintained package), PDO SQLite, Python 3,
Node 24, Chromium 153 through Playwright. No Badger hardware was connected.

- All PHP files pass `php -l`; JavaScript passes `node --check`.
- `tests/server.php`: 21 checks covering payload bounds, versions, scopes,
  rollback, key hashes/rotation, deletion, disabled apps, audit and throttling.
- The maximum permitted manifest in the server test is 14,818 bytes, below the
  32 KiB transport budget. Actual MicroPython heap use still needs measurement.
- `tests/test_device.py`: navigation across 10,000 randomized button events,
  empty/deleted/reordered apps, rejected schemas, bounded backoff, corrupted cache
  fallback and preservation of one valid slot.
- `tests/test_transport.py`: simulated fragmented responses, partial writes,
  oversized/truncated/redirected/chunked responses, explicit TLS-required arguments,
  cancellation and WLAN cleanup. These mocks do **not** prove native TLS behavior.
- `tests/test_http.py`: real HTTP requests to PHP with an isolated SQLite DB:
  authentication, CSRF, origin and method checks; key scopes and revocation;
  stale update conflicts; JSON size/type validation; login limits;
  a held database lock returns 503 after approximately 2 seconds;
  a held session lock returns 503 after approximately 1 second.
- `tests/browser.mjs`: real browser login, app registration, device registration,
  assignment, invalid JSON feedback, token rotation, reload, logout and a 390px
  mobile viewport. No observed console errors, no horizontal page overflow.
- Desktop/mobile screenshots were inspected. The preview is a layout approximation,
  not an exact rendering of Pimoroni's bitmap font.

Run from the repository root:

```bash
php tests/server.php
python3 -m unittest discover -s tests -v
node --check public/app.js
```

For the optional browser test, start a **fresh development installation** on
127.0.0.1:8080. Install Playwright in your development environment, supply
`BADGER_TEST_PASSWORD`, then `node tests/browser.mjs`. An alternate absolute module
path can be supplied via `PLAYWRIGHT_MODULE`, and a Chromium binary via
`CHROMIUM_EXECUTABLE`. The test creates `energie` and `flur`; never point it at a
production installation. Browser tooling is not a server dependency.

## Required on a real Badger before unattended use

Record the exact UF2 version/hash, CA certificate, available free heap and outcome.

1. Provision UTC RTC, correct CA and hostname. Verify a successful HTTPS fetch.
2. Wrong CA, wrong hostname, expired certificate and invalid RTC must reject the
   connection without an insecure fallback. Verify modern and/or legacy TLS path
   actually supported by the chosen firmware; do not assume docs equal compatibility.
3. Load ten apps with six pages and three maximum-length rows each. Measure free
   heap through repeated fetches. Verify no truncation, out-of-memory reset or leak.
4. Navigate all pages; C must return to the menu. Hold each key: no repeat storm.
5. Remove Wi-Fi or give a wrong password. Check bounded retries, offline display,
   five-failure pause and manual B recovery after restoring the network.
6. Simulate a server that accepts but never answers, slow partial responses, DNS
   blackholing and TLS handshake hangs. Verify timeout or watchdog recovery.
7. Force a watchdog reset. Ensure the next boot makes **no automatic request**,
   keeps local navigation and allows explicit retry using B.
8. During network operations press C. It must return to the menu at the next
   checkpoint (possibly after a native call's timeout), then pause automatic sync.
9. Delete/disable the selected app or all assignments. Refresh must return safely
   to a usable empty menu. Rotate/disable the device key: requests must be denied.
10. Corrupt the newest cache slot and interrupt power during a cache write. One
    previous slot must remain usable, or the built-in empty menu must appear.
11. Allow the data TTL to expire. `OLD DATA`/`OFFLINE` must be visible as applicable.
12. Hold C during reset; confirm the REPL is available without watchdog resets.
13. Run on USB for 24 hours, including Wi-Fi outages and server restarts. Observe
    resets, ghosting, TLS memory pressure and successful recovery.
14. Test backup/restore and edge timeout settings on the actual hosting platform.

Battery sleep, QR/image rendering, device-side actions and cloud deployment are
outside this version's acceptance scope. They require separate implementation
and tests, not just a configuration toggle.
