# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Wiener Abfahrtsmonitor** — a PHP web app showing real-time departure times for Vienna public transit (Wiener Linien). Users can search stations, save favorites, and use geolocation to find nearby stops.

Live at: `https://www.jardyx.com/wl-monitor/`

## Tech Stack

- **Backend:** PHP 8.2, MySQLi (no framework)
- **Frontend:** Bootstrap 4.3, jQuery, Font Awesome 5, custom SCSS
- **Third-party JS:** Geodesy (distance calculations), xpull (pull-to-refresh)
- **External API:** Wiener Linien ogd_realtime (`wienerlinien.at/ogd_realtime/monitor`)
- **Station data source:** hactar's station list (stored in `ogd_stations` MySQL table)

## Architecture

There is no build system. The app runs directly on a PHP web host. All files in `web/` are served directly.

### Request Flow

1. **`initialize.php`** — must be `include`d first by every PHP file. Sets DB constants, creates `$con` (MySQLi connection), starts/restores the PHP session, and defines shared utility functions (`addAlert`, `appendLog`, `sanitizeRblInput`, etc.)

2. **`index.php`** — main HTML page. Assembles navigation, monitor panel, and favorites panel. Depends on `html_header.php`, `html_body.php`, `html_footer.php`.

3. AJAX endpoints called from `js/wl-monitor.js` via jQuery:
   - **`monitor_json.php`** — fetches departure data from Wiener Linien API using `diva` (station ID) parameter, returns JSON. This is the core data path.
   - **`getStations.php`** — returns station list from DB. Accepts optional `lat`/`lon` params for proximity sorting.
   - **`getFavorites.php`** / **`saveFavorites.php`** / **`addFavorite.php`** / **`deleteFavorite.php`** / **`editFavorite.php`** — favorites CRUD
   - **`ajaxFav.php`** — AJAX dispatcher for favorite actions
   - **`getLog.php`** — returns user activity log

### Station Identifiers

The Wiener Linien API uses **DIVA numbers** (also called RBL numbers) to identify stops. A single physical station can have multiple DIVA numbers (one per line/direction). The `monitor_json.php` endpoint accepts comma-separated DIVA numbers (e.g. `?diva=60200103,60200104`). These map to the `rbls` column in the `ogd_stations` table.

### User/Auth System

Authentication is handled by the `erikr/auth` library (Composer). Login/logout/password reset code is shared across projects.

- `authentication.php` — login handler (POST)
- `logout.php`, `changePassword.php`, `admin/resetPassword.php` — auth library
- User state is tracked via PHP sessions + a `sId` cookie for session recovery across browser restarts
- `AUTH_DB_PREFIX = 'jardyx_auth.'` — tables prefixed with `jardyx_auth.` (e.g., `jardyx_auth.accounts`, `jardyx_auth.log`)

### Database Tables

- `jardyx_auth.accounts` — user accounts (id, username, email, password hash, img, rights, debug flag)
- `wl_preferences` — user-specific departures setting (name, divas)
- `ogd_stations` — all Vienna transit stops (Haltestelle, LAT, LON, rbls)
- `jardyx_auth.log` — activity log (user actions, logins, errors)

## Configuration

All runtime config lives in **`initialize.php`**: DB credentials (`DATABASE_HOST/USER/PASS/NAME`), the Wiener Linien API key (`APIKEY`), the server file path (`SCRIPT_PATH`), and `AUTH_DB_PREFIX = 'jardyx_auth.'` for auth table prefixes. These are hardcoded — update this file when deploying to a different environment. Shared auth code lives in `/Users/erikr/Git/auth` (via Composer as `erikr/auth`).

## Deployment

No build step. Deploy by copying `web/` to the server. The app expects a MySQL database with the schema already in place. `SCRIPT_PATH` in `initialize.php` must match the server's absolute path.
