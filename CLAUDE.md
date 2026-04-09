# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Wiener Abfahrtsmonitor** — a PHP web app showing real-time departure times for Vienna public transit (Wiener Linien). Users can search stations, save favorites, and use geolocation to find nearby stops.

Live at: `https://www.jardyx.com/wl-monitor/`

## Tech Stack

- **Backend:** PHP 8.2, MySQLi (no framework)
- **Frontend:** Shared CSS library (`web/css/shared/` symlink → `~/Git/css`) + app CSS (`web/css/app/wl-monitor.css`), vanilla JS, SVG icon sprite
- **Third-party JS:** Geodesy (distance calculations), xpull (pull-to-refresh)
- **External API:** Wiener Linien ogd_realtime (`wienerlinien.at/ogd_realtime/monitor`)
- **Station data source:** hactar's station list (stored in `ogd_stations` MySQL table)

## Architecture

There is no build system. The app runs directly on a PHP web host. All files in `web/` are served directly.

### Request Flow

1. **`inc/initialize.php`** — must be `include`d first by every PHP file. Sets DB constants, creates `$con` (MySQLi connection), calls `auth_bootstrap()` (security headers, session, CSP nonce), and defines shared utility functions (`sanitizeDivaInput`, `icon`, etc.). Auth helpers (`addAlert`, `appendLog`, `csrf_input`, etc.) come from the `erikr/auth` library loaded via Composer autoload.

2. **`index.php`** — main HTML page. Assembles navigation, monitor panel, and favorites sidebar. Passes PHP state to JS via `window.wlConfig`. Depends on `inc/html_header.php` and `inc/html_footer.php`.

3. All AJAX calls from `js/wl-monitor.js` go through **`api.php`** (unified JSON dispatcher, no jQuery):
   - `?action=monitor&diva=…` — fetches departure data from Wiener Linien API. Returns structured station/line/departure data. Injects empty placeholder entries for any DIVAs the WL API omitted (stops with no current service).
   - `?action=stations` — returns station list from DB. Accepts optional `lat`/`lon` for proximity sorting.
   - `?action=favorites` / `favorites_add` / `favorites_edit` / `favorites_delete` / `favorites_sort` — favorites CRUD (all write actions require CSRF)
   - `?action=log` — paginated activity log
   - `?action=theme_save` / `position_save` — preference updates

### Station Identifiers

The Wiener Linien API uses **DIVA numbers** to identify stations. DIVA is a station-level 8-digit identifier from `ogd_haltestellen.DIVA` (e.g. `60200103`). The `api.php?action=monitor` endpoint accepts comma-separated DIVA numbers. The `wl_favorites.diva` column stores these values.

**Important API behaviour:** The WL Realtime API returns one monitor entry per line (not per station), and entries for different stations are interleaved in the response. It also silently omits stops with no upcoming departures. `monitor_get()` and `api.php` handle both cases correctly.

### User/Auth System

Authentication is handled by the `erikr/auth` Composer library (`/Users/erikr/Git/auth`). Auth logic is **not in scope for this project** — do not modify auth behaviour here. If you spot a security issue or improvement in how auth works, flag it as a recommendation for the auth library.

- `authentication.php` — login POST handler (calls auth library)
- `logout.php` — POST-only, CSRF-protected (calls auth library)
- `admin/resetPassword.php` — password reset (calls auth library)
- User state is tracked via PHP sessions + a `sId` cookie for session recovery across browser restarts
- `AUTH_DB_PREFIX = 'jardyx_auth.'` — tables prefixed with `jardyx_auth.` (e.g., `jardyx_auth.auth_accounts`, `jardyx_auth.auth_log`)

### Database Tables

- `jardyx_auth.auth_accounts` — user accounts (id, username, email, password hash, img_blob, img_type, rights, debug, theme)
- `jardyx_auth.auth_log` — activity log (user actions, logins, errors)
- `wl_preferences` — wlmonitor-specific user preferences (user_id, departures)
- `wl_favorites` — saved favourites (id, idUser, title, diva, bclass, sort, filter_json)
- `ogd_haltestellen` / `ogd_steige` / `ogd_linien` — Wiener Linien open data (stations, stops, lines)
- `ogd_stations` (VIEW) — one row per station: DIVA, name, coordinates, lines served

## Reference Docs

Read these on demand, not by default:
- `docs/wienerlinien-api.md` — Wiener Linien Realtime API V1.4 (endpoints, response schemas, error codes, terminology)
- `docs/wienerlinien-echtzeitdaten-dokumentation.pdf` — original German API specification from Wiener Linien

## Configuration

All runtime config lives in **`initialize.php`**: DB credentials (`DATABASE_HOST/USER/PASS/NAME`), the Wiener Linien API key (`APIKEY`), the server file path (`SCRIPT_PATH`), and `AUTH_DB_PREFIX = 'jardyx_auth.'` for auth table prefixes. These are hardcoded — update this file when deploying to a different environment. Shared auth code lives in `/Users/erikr/Git/auth` (via Composer as `erikr/auth`).

## Security Patterns

Auth is provided by `erikr/auth` — security decisions (session handling, CSRF tokens, rate limiting, bcrypt) live there. In this project:

- **Logout must be POST + CSRF.** A plain `<a href="logout.php">` allows logout CSRF. Use a `<form method="post">` with `<?= csrf_input() ?>` and a styled `<button type="submit">`.
- **MIME types from the DB must be whitelisted** before setting `Content-Type`. Never reflect a raw DB value into a response header.
- If you notice a security issue in auth behaviour (session fixation, token handling, etc.), flag it as a recommendation — do not patch it in this project directly.

## Deployment

Run `deploy.sh` from the repo root to sync to the local Apache web server. The script uses `rsync --copy-links --delete`, so `vendor/erikr/auth` (a Composer path symlink) is copied as real files, and stale files are removed from the destination. `config/` and `data/` are excluded and never overwritten.

Two local environments exist:

- **Dev** — `http://localhost/wlmonitor.test` → serves `/Users/erikr/Git/wlmonitor/web/` directly from the Git repo.
- **Production** — `http://localhost/wlmonitor` → serves `/Library/WebServer/Documents/wlmonitor/web/`.

All development work targets the Git repo. `/Library/WebServer/Documents/wlmonitor/` is only touched when explicitly deploying. These two environments must remain independent — no shared symlinks or cross-references.
