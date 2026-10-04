# HTTP API v1

All routes use `/api.php?r=ROUTE` at the configured HTTPS origin. JSON only, no
CORS, tokens in `Authorization: Bearer TOKEN`, never query strings. Device and
publisher endpoints do not use browser sessions. Browser/admin mutations use a
session and `X-CSRF-Token` issued by `GET session` (rotated after login).

## Hub-managed layout and value publishing (recommended)

New apps created in the UI default to `publish_mode: "values"`. Register a layout
in the admin UI, then publish only named values. No source-specific integration
is built into the hub. Existing apps remain in `pages` mode.

`GET app-status` with the app's publisher token returns:

```json
{"id":"room","publish_mode":"values","version":1,"data_version":0,"updated_at":0,"data_updated_at":0}
```

`version` is the admin/layout revision. `data_version` is the independent data
revision. `POST publish-values` uses the **data_version** in its `version` field:

```json
{"version":0,"values":{"temperature":21.5,"status":"OK"}}
```

Response: `{"data_version":1}`. This replaces the complete data snapshot, without
changing the layout, admin version, title, TTL, enabled state or assignments.
The current token, enabled state, mode and data revision are checked atomically.
Do not include `screens` in this request. Data updates and layout saves can occur
in either order without losing either change. Concurrent data writes conflict.

### Layout schema

Admin `screens` in `values` mode accepts a mix of these row shapes:

```json
[
  {"title":"Room","rows":[
    {"label":"Temp","field":"temperature","unit":"deg C","decimals":1},
    {"label":"Location","value":"Room 1"}
  ]}
]
```

Exact keys are required. Bound rows have `label`, `field`, `unit`, `decimals`;
static rows have `label`, `value`. Field keys match `[a-z][a-z0-9_]{0,31}`.
Unit: 0–8 printable ASCII characters. Decimals: integer 0–3. No expressions,
interpolation, remote URLs or executable code. One field may be used in several rows.

### Data and rendering rules

- `values` must be a JSON object with at most 32 fields; `{}` clears it.
- Each value is a finite number with absolute value <= 1e12, printable ASCII text
  of 1–22 characters, or null. Arrays, nested objects and booleans are rejected.
- Unknown-to-layout field names are accepted within these bounds for later use.
- Missing/null fields display `--`, with no unit. Every publish is a full snapshot:
  omitted fields are removed rather than retained with a misleading fresh timestamp.
- Numbers are formatted with the chosen decimals and a period; strings are not
  parsed as numbers. Nonempty units are appended with one space. No unit conversion.
- A formatted result longer than 22 characters displays `OVERFLOW`, never a
  silently truncated measurement. Display output retains the original v1 schema.
- `data_updated_at` is server receipt time, initially zero. Layout edits do not
  refresh it. A bound app's manifest `updated_at` uses this timestamp. An app with
  only static rows uses its last admin save timestamp instead. Source measurement
  freshness remains the producer's responsibility; TTL is per app, not per field.

### Conflicts and mode changes

Stale data version or wrong mode returns 409. Fetch `app-status` and decide whether
to send a fresh snapshot later. Do not blindly replay after a timeout: commit may
already have happened. There is no automatic retry loop. Mode changes clear data,
reset data receipt time and increment data_version, preventing reuse of an old
revision after a mode round-trip. Keys and assignments remain unchanged.

## Legacy whole-page publishing

`publish_mode: "pages"` preserves the original API. `GET app-status` returns the
same fields; use **version**, not data_version. `POST publish` accepts:

```json
{"version":1,"screens":[{"title":"Energy","rows":[{"label":"PV","value":"5.8 kW"}]}]}
```

Response: `{"version":2}`. The publisher replaces whole pages and advances the
shared admin/page revision. Editor changes can be overwritten by the next successful
publish in this mode. The endpoint rejects hub-managed apps (409), and conversely
`publish-values` rejects legacy apps. Legacy conflicts include `X-App-Version`;
`app-status` is authoritative for both mode and revisions.

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
| `app` | POST | `{id,title,enabled,ttl,screens,version,publish_mode?}` |
| `device` | POST | `{id,name,enabled,refresh,apps,version}` |
| `rotate` | POST | `{kind:"app" or "device",id,version}` |
| `delete` | POST | Same shape as rotate |

`version:0` creates an entry; updates must supply its current version from state.
`publish_mode` is `values` or `pages`. Omission preserves an existing app's mode;
legacy API creates without it default to `pages`. Admin state includes the stored
`screens` layout, rendered `rendered_screens`, latest `values`, `data_version`,
`data_updated_at` and `publish_mode`. These additional fields are not sent to devices.

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
| Data fields / key / unit / decimals | 32 / 32 / 8 / 0–3 |
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
