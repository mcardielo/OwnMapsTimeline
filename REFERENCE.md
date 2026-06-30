# OwnMapsTimeline — Reference

> **Purpose:** Self-hosted OwnTracks frontend to visualize GPS tracks on a map.
> **Stack:** PHP 8.3 Vanilla MVC, SQLite/MySQL via PDO, Leaflet.js, Docker Alpine, nginx.

---

## Architecture

```
Request → nginx → public/index.php (Front Controller)
                      ├─ Auth guard (session or Authelia headers)
                      ├─ Route match (method + path)
                      ├─ require controller file
                      └─ Controller::method($query, $body)
                           └─ View::render(template, data, layout)
                                └─ extract($data) + include view
```

### File tree

```
public/
  index.php              # Front controller: routing + auth + dispatch
  js/
    app.js               # Shared: fmtLocalDatetime, escapeHtml, tstToLocalDatetime
    dashboard.js         # Map, filters, playback, auto-refresh, accuracy toggle
    devices.js           # Remote config save + color picker
  css/dashboard.css      # Map + sidebar styles
src/
  config/database.php    # PDO connection, auto-migrations, helpers (query/queryOne/execute/insert)
  lib/View.php           # Renderer: View::render(template, data, layout)
  controllers/
    ApiController.php    # JSON API: locations, device-config (public)
    AuthController.php   # Login, setup, logout, isAuthenticated()
    DeviceController.php # CRUD devices, remote config, color update
    ImportController.php # GPX import
    MapController.php    # Dashboard page
    UserController.php   # Admin user management
    WebhookController.php# OwnTracks HTTP ingest endpoint
  views/
    layout.php           # HTML shell (Tailwind CDN, nav-aware)
    dashboard.php        # Map page: sidebar filters + Leaflet map
    devices.php          # Device list: create, manage, QR config, color picker
    import.php           # GPX import form
    login.php / setup.php / users.php
Dockerfile               # Multi-stage: alpine + nginx + php-fpm
docker-compose.yml       # Volume mounts, env vars, port mapping
nginx.conf               # Server block: try_files → index.php
```

---

## Routing

All routes in `public/index.php`. Two tables: `GET` and `POST`.
Prefix `protected:` requires auth (session or Authelia).

| Method | Path | Handler | Auth |
|--------|------|---------|------|
| GET | `/` `/dashboard` | MapController::dashboard | ✓ |
| GET | `/devices` | DeviceController::list | ✓ |
| GET | `/users` | UserController::list | ✓ |
| GET | `/import` | ImportController::form | ✓ |
| GET | `/api/locations` | ApiController::locations | ✓ |
| GET | `/api/device-config` | ApiController::deviceConfig | ✗ (tid+token) |
| POST | `/login` `/setup` `/logout` | AuthController | — |
| POST | `/webhook` | WebhookController::ingest | ✗ (tid+token) |
| POST | `/devices/create` | DeviceController::create | ✓ |
| POST | `/devices/update` | DeviceController::update | ✓ |
| POST | `/devices/config` | DeviceController::updateConfig | ✓ |
| POST | `/devices/delete` | DeviceController::delete | ✓ |
| POST | `/devices/color` | DeviceController::updateColor | ✓ |
| POST | `/users/create` `/update` `/delete` | UserController | ✓ |
| POST | `/import/preview` `/execute` | ImportController | ✓ |

---

## Database

### Tables

**users** — id, username, password, role (admin/user), timestamps
**devices** — id, user_id FK, name, tid, webhook_token, config_json, color, timestamps
**locations** — id, device_id FK, lat, lon, tst, acc, alt, vac, vel, batt, bs, conn, t, tag, raw_data, created_at
**events_log** — id, device_id FK, event_type, tst, raw_data, created_at

### Indexes
- `idx_locations_device_tst` on locations (device_id, tst)
- `idx_events_log_device_tst` on events_log (device_id, tst)

### Migrations
Auto-run on every `Database::connect()`. Uses `addColumnIfMissing()` helper for non-breaking additions.
- Supports SQLite and MySQL via `DB_TYPE` env var.
- WAL mode enabled for SQLite.

### Color column
- Added via `addColumnIfMissing('devices', 'color', "TEXT NOT NULL DEFAULT ''")`
- Hex format `#rrggbb`
- Auto-assigned from `DeviceController::COLOR_PALETTE` on device creation
- Fallback in dashboard.js: `DEVICE_COLORS[i]` if `p.color` is empty

---

## API

### GET /api/locations
**Query params:** `device_id` (int or "all"), `from` (unix ts), `to` (unix ts), `range` (1h/6h/24h/7d/30d)

**Response:**
```json
{
  "ok": true,
  "points": [{
    "device_id": 1, "lat": 19.4, "lon": -99.1, "tst": 1719000000,
    "acc": 12.5, "alt": 2240, "vel": 35, "batt": 85, "bs": "1",
    "conn": "w", "t": "p", "vac": null, "tag": null,
    "device_name": "Phone", "tid": "phone",
    "color": "#3b82f6"
  }],
  "count": 150, "original_count": 320, "range": {"min_tst": ..., "max_tst": ...}
}
```

Features:
- Spatial downsampling: keeps points ≥30m apart, adapts threshold if >5000 points
- Haversine distance calculation
- Points sorted by tst ASC
- Each point now includes `color` from the device

### POST /webhook — HTTP Friends

When a device publishes a location via HTTP, the webhook responds with the latest
location of **all other devices** owned by the same user. The OwnTracks app displays
these as "friends" on its map.

**Response format** (OwnTracks HTTP mode spec — JSON array of `_type` objects):
```json
[
  {"_type": "location", "tid": "Phone", "lat": 19.4, "lon": -99.1, "tst": ..., ...},
  {"_type": "card",    "tid": "Phone", "name": "Phone"},
  {"_type": "location", "tid": "Tablet", "lat": 19.5, "lon": -99.2, "tst": ..., ...},
  {"_type": "card",    "tid": "Tablet", "name": "Tablet"}
]
```

**How it works:**
1. Device authenticates via `?tid=TID&token=***` in URL
2. Location/event stored normally
3. `getFriendLocations()` queries latest `raw_data` for each sibling device (same `user_id`)
4. Each location gets `tid` overwritten with the device's `name` from DB (friendly label)
5. A `_type: "card"` is included per friend so the app shows the full name
6. Response is a JSON array (empty `[]` if no siblings)

**Limitation:** Friends only update when the receiving device publishes its own location.
No real-time push — this is inherent to OwnTracks HTTP mode (vs MQTT pub/sub).

**Related docs:** <https://owntracks.org/booklet/tech/http/> <https://owntracks.org/booklet/tech/json/>

### GET /api/poi-image

Serves a POI image on-demand from the `raw_data` JSON stored at ingest time.
No filesystem duplication — base64 decoded on-the-fly.

- `GET /api/poi-image?id=LOCATION_ID`
- Returns `image/jpeg` with long cache headers
- Protected normally (browser sends cookies on `<img>` load)

### POI rendering

- **Toggle** 📍 Show POIs in sidebar (persisted in `localStorage.ot_show_pois`, default off)
- POIs are **device-independent** — always queried from all user devices, regardless of filter
- Rendered as 📍 pin markers (`L.divIcon`, 36×36, 24px font) in a separate `poiMarkers` featureGroup
- **Not** shown on device routes (routes keep circleMarkers)
- Hidden during playback
- API includes a `pois` array in the response with all POI locations (filtered: `poi IS NOT NULL AND poi != ''`)
- **Popup (minimal)**: date, POI name + image (if `poi_imagename` set), lat, lon, accuracy, device
- Image capped at `max-height: 200px` in popup
- POI is sent once-only by OwnTracks (unlike tags which persist)

**Related docs:** <https://owntracks.org/booklet/features/poi/>

---

## Map (dashboard.js)

### Key objects
- `map` — Leaflet map instance, tile layer OSM
- `markers` — `L.featureGroup()` for circleMarkers (start/end/mid points)
- `accuracyCircles` — `L.featureGroup()` for accuracy radius circles (toggleable, interactive:false)
- `speedSegments` — `L.featureGroup()` for speed-colored mini-polylines (toggleable, interactive:false)
- `poiMarkers` — `L.featureGroup()` for POI pin markers (toggleable)
- `polylines[]` — array of `L.polyline` for each device's track

### Point rendering
| Position | Style |
|----------|-------|
| First point | radius:7, solid fill |
| Last point | radius:9, white fill, device color stroke |
| Intermediate | radius:4, 50% opacity |
| Accuracy circle | radius:acc meters, fillOpacity:0.12, opacity:0.25, interactive:false |
| Speed segment | mini-polyline between consecutive points, weight:6, opacity:0.7, speed-colored, interactive:false |

### Color resolution
```js
var color = (firstPoint && firstPoint.color) || DEVICE_COLORS[i % DEVICE_COLORS.length];
```
Priority: DB color → palette fallback by index.

### Playback
- Only for single device (not "all")
- Replaces markers/polylines/accuracyCircles/speedSegments with playback layer
- **Time-based with interpolation**: virtual clock advances by real wall time × speed multiplier
- Position interpolated linearly between consecutive points (no abrupt jumps)
- `requestAnimationFrame` for smooth 60fps animation
- States: idle, playing, paused
- Speed change during playback resyncs wall clock for immediate effect
- Progress bar shows: `45% 14:23:05 / 16:45:30`
- Forward linear scan from `pbLastIdx` for O(n) total efficiency

### Auto-refresh
- `setTimeout` recursive (not `setInterval`) — resets on Apply
- 30 second interval
- Disabled during playback

### Accuracy toggle
- Checkbox `#showAccuracy` in sidebar
- `accuracyCircles` FeatureGroup toggled via `map.addLayer/removeLayer`
- Circles always rendered into the layer (even if hidden) — toggle just shows/hides
- Circles have `interactive: false` so clicks pass through to markers
- Persisted in `localStorage.ot_show_accuracy`
- Hidden during playback

### Speed overlay toggle
- Checkbox `#showSpeed` in sidebar, with mini legend (gradient bar 🐢0→60→120+🐇)
- `speedSegments` FeatureGroup toggled via `map.addLayer/removeLayer`
- Renders mini-polylines (weight:6) between consecutive points, colored by `vel`
- Color scale: `speedToColor()` — green (0 km/h) → yellow (60) → red (120+)
- Segments have `interactive: false` so clicks pass through to markers
- Legend `#speedLegend` shown/hidden with toggle
- Persisted in `localStorage.ot_show_speed`
- Hidden during playback
- **Note:** Requires `vel` field in location data (OwnTracks sends it; GPX imports may not)

### Sidebar state
- `localStorage.ot_sidebar_collapsed` — persists collapse state
- `localStorage.ot_selected_device` — persists selected device
- `datetime-local` inputs pre-populated to today 00:00–23:59

---

## Devices page (devices.js)

### Functions
- `toggleConfig(deviceId)` — show/hide remote config panel
- `onConfigChange(deviceId, webhookUrl, deviceName)` — debounced (800ms), updates QR + saves
- `saveConfig(deviceId)` — POST to `/devices/config`, shows save status
- `updateConfigLink(deviceId, ...)` — rebuilds deep link + QR URL
- `buildConfigFromPanel(panel)` — reads `data-param` inputs
- `onDeviceColorChange(input)` — debounced (400ms), POST to `/devices/color`

### Config panel
- Hidden by default, toggled via `⚙️ Remote Config ▾` button
- Fields: positions, monitoring, adapt, interval, displacement, downgrade %, inaccurate threshold, days, ranging, locked, allowRemoteLocation
- Generates QR code + `owntracks:///config?inline=...` deep link
- Saves full config JSON to `devices.config_json`

---

## Auth modes

Two modes via `AUTH_MODE` env var:

### local (default)
- Session-based: `$_SESSION['user_id']`, `$_SESSION['username']`, `$_SESSION['role']`
- Passwords hashed with `password_hash()`
- Setup page creates first admin

### authelia
- Trusts `Remote-User` header (or `AUTH_HEADER` env var)
- Users auto-created in DB on first visit
- `$isAdmin` resolved via `authelia_admin_users` env var (comma-separated list)

---

## Patterns & conventions

- **Front Controller**: single entry point `public/index.php`, no .htaccess magic
- **Vanilla MVC**: no framework, no ORM, no DI container
- **Static controllers**: all methods are `public static function`, no instantiation
- **PDO helpers**: `Database::query()`, `queryOne()`, `execute()`, `insert()`
- **View rendering**: `View::render(template, data, layout)` — `extract($data)` into scope
- **JSON API**: `file_get_contents('php://input')` for raw body, `json_decode` with `[]` fallback
- **Exit early**: API methods call `exit;` after JSON output
- **Redirect pattern**: `header('Location: ...', true, 302); exit;`
- **JS modules**: IIFE scope for map engine, global functions for UI controls
- **localStorage keys**: `ot_selected_device`, `ot_sidebar_collapsed`, `ot_show_accuracy`, `ot_show_speed`, `ot_show_pois`

---

## Deployment

- **Docker**: Alpine 3.21, PHP 8.3-fpm, nginx, supervisor
- **Ports**: 80 (internal nginx), exposed via `$PORT` env (default 8080)
- **Volumes**: `/app/data` for SQLite DB
- **Config**: `.env` file or environment variables
- **Supervisor**: runs php-fpm and nginx in parallel
