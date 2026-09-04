# 🗺️ OwnMapsTimeline

A dead-simple OwnTracks companion server and Google Maps Timeline replacement — receive
GPS location data via webhook, visualize your tracks on an interactive map, and manage
multiple users and devices, all in a single container with zero external services.

## Supported Tags

- `latest` — stable release

## How to Use

```bash
docker run -d \
  --name owntracks \
  -p 8090:80 \
  -v owntracks_data:/app/data \
  -e AUTH_MODE=local \
  -e TZ=America/Mexico_City \
  mcardielo/ownmapstimeline:latest
```

Then open `http://localhost:8090/setup` and create your admin account.

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `AUTH_MODE` | `local` | `local` (PHP sessions) or `authelia` |
| `AUTH_HEADER` | `Remote-User` | Header set by Authelia |
| `AUTH_LOGOUT_URL` | — | Redirect URL after logout |
| `DB_TYPE` | `sqlite` | `sqlite` or `mysql` |
| `DB_HOST` | — | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_USER` | — | MySQL user |
| `DB_PASS` | — | MySQL password |
| `DB_NAME` | — | MySQL database name |
| `APP_DEBUG` | `false` | Enable PHP error display |
| `TZ` | `America/Mexico_City` | Timezone for logs |

## Volumes

| Path | Description |
|------|-------------|
| `/app/data` | SQLite database and persistent data |

## Stack

- **Backend:** PHP 8.3
- **Web Server:** Nginx (Alpine)
- **Database:** SQLite (default) or MySQL
- **Frontend:** Leaflet.js + TailwindCSS

## Features

- Interactive map (Leaflet + OpenStreetMap) with color-coded routes per device
- Route playback with adjustable speed, accuracy and speed overlays
- Places detection — automatic stay-point detection (DBSCAN) with visit history
- Manual place creation, per-device places, configurable detection settings
- Device sharing between users, each with custom name/color
- Config drift detection & auto-heal — daily check that the app still matches your settings, with automatic fix + ⚠️ alerts
- Multi-user and multi-device, GPX import, POI markers, tag filtering, timezone support
- HTTP Friends — share locations between OwnTracks apps via webhook response
- Local (PHP sessions) or Authelia authentication

## Remote Configuration Note

Recent versions of the OwnTracks app have disabled remote configuration via deep links
and QR codes by default for security reasons. If the QR code or "Open in OwnTracks" link
doesn't work, open the OwnTracks app → Settings → Remote Control → enable **Allow external configuration**, then
try again.

## Links

- [GitHub Repository](https://github.com/mcardielo/OwnMapsTimeline)
- [OwnTracks](https://owntracks.org/)
