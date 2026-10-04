# HTTP API v1

All routes use `/api.php?r=ROUTE` at the configured HTTPS origin. JSON only, no
CORS, tokens in `Authorization: Bearer TOKEN`, never query strings. Device and
publisher endpoints do not use browser sessions. Browser/admin mutations use a
session and `X-CSRF-Token` issued by `GET session` (rotated after login).

## Producer workflow

1. Register an app through the admin interface. Keep its publisher key privately.
2. `GET /api.php?r=app-status` with that key returns:
   `{"id":"energy","version":1,"updated_at":1791100000}`.
3. `POST /api.php?r=publish` with the same key and this body:

```json
{
  "version": 1,
  "screens": [
    {
      "title": "Energie heute",
      "rows": [
        {"label": "PV", "value": "5.8 kW"},
        {"label": "Akku", "value": "84 %"},
        {"label": "Netz", "value": "-2.1 kW"}
      ]
    }
  ]
}
```

Response: `{"version":2}`. A publisher can update only its own pages, not its
name, TTL, enabled state, token or device assignments. Server receipt time is the
freshness timestamp. A producer must not publish old source data as if freshly
measured; include source measurement time in a row when relevant.

A concurrent edit returns **409** and `X-App-Version`. Fetch status again and decide
whether to replace the newer content; do not blindly replay an old update. A
network timeout may have occurred after commit. The same version cannot be applied
twice. Producers should schedule a later fresh update after re-reading status.

## Device manifest

`GET /api.php?r=manifest` with a device key returns:

```json
{
  "schema": 1,
  "generated_at": 1791100000,
  "refresh_seconds": 900,
  "apps": [
    {
      "id": "energy",
      "title": "Energy",
      "updated_at": 1791100000,
      "ttl": 1800,
      "screens": [
        {"title": "PV", "rows": [{"label": "Power", "value": "5.8 kW"}]}
      ]
    }
  ]
}
```

Only assigned active apps are returned, in assignment order. An empty `apps`
array is valid. All timestamps are Unix seconds in UTC. The device must reject
unknown schema versions and malformed data without replacing its existing state.
A key belonging to a disabled/deleted device returns 401. No admin metadata,
password hashes, API hashes or publisher secrets appear in the manifest.

## Admin routes

| Route | Method | Body / result |
|---|---|---|
| `session` | GET | Authentication flag and CSRF token |
| `login` | POST | `{password}`; returns rotated CSRF token |
| `logout` | POST | No data needed; destroys session |
| `state` | GET | Apps, devices, last 20 admin audit events; no token hashes |
| `app` | POST | `{id,title,enabled,ttl,screens,version}` |
| `device` | POST | `{id,name,enabled,refresh,apps,version}` |
| `rotate` | POST | `{kind:"app" or "device",id,version}` |
| `delete` | POST | Same shape as rotate |

`version:0` creates an entry; updates must supply its current version from state.
Mutations are short transactions. Creation/rotation returns `{id,token}` exactly
once; other saves return `{id,token:null}`. Lost tokens cannot be retrieved.
App deletion removes assignments. Save/rotate/delete rejects stale versions.
The browser preserves existing assignment order when editing a device, appending
newly selected apps in registry order.

## Bounds

| Item | Limit |
|---|---|
| Apps / devices registered | 100 each |
| Apps assigned to one device | 10 |
| Pages per app / rows per page | 6 / 3; minimum 1 each |
| App title / page title | 24 / 28 printable ASCII characters |
| Row label / value | 14 / 22 printable ASCII characters |
| Device name | 64 UTF-8 bytes |
| IDs | 1–32, lowercase letter first, then letters/digits/hyphens |
| TTL | 60–604800 seconds |
| Device refresh interval | 60–86400 seconds |
| Incoming JSON / device response | 16 KiB / 32 KiB |
| Device / publisher requests | 120 / 60 per minute per key's record |
| Admin session | 8 hours absolute lifetime |

Unknown fields, nested executable definitions, HTML rendering instructions and
remote actions are not part of v1. Strings are rendered as text, not HTML.

## Errors

JSON errors have `{"error":"message"}`. The admin UI currently uses German messages.

| Status | Meaning |
|---|---|
| 400 / 413 / 415 / 422 | Malformed JSON / oversized body / wrong type / invalid data |
| 401 / 403 | Invalid or inactive credentials / HTTPS, origin or CSRF rejection |
| 404 / 405 | Unknown route / wrong method |
| 409 | Stale version or conflicting create; refresh before retry |
| 429 | Rate limit; observe `Retry-After` |
| 503 | Installation, session or database unavailable/busy |
| 500 | Internal failure; check private logs |

No request has an automatic infinite retry. Display producers should bound their
own attempts and alert on persistent failures.
