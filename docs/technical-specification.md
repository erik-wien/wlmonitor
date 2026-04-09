# WL Monitor — Technical Specification
_Last updated: 2026-04-09 · Version 3.0 (Build 18)_

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Architecture Overview](#2-architecture-overview)
3. [Directory Structure](#3-directory-structure)
4. [Entry Points (web/)](#4-entry-points-web)
5. [Business Logic (inc/)](#5-business-logic-inc)
6. [Frontend: JavaScript](#6-frontend-javascript)
7. [Frontend: CSS System](#7-frontend-css-system)
8. [SVG Icon Sprite](#8-svg-icon-sprite)
9. [Database Schema](#9-database-schema)
10. [Authentication & Session Management](#10-authentication--session-management)
11. [Wiener Linien API Integration](#11-wiener-linien-api-integration)
12. [Favorites System](#12-favorites-system)
13. [Security Architecture](#13-security-architecture)
14. [Theme System](#14-theme-system)
15. [Avatar Upload & Serving](#15-avatar-upload--serving)
16. [OGD Station Data Sync](#16-ogd-station-data-sync)
17. [Deployment](#17-deployment)
18. [Configuration Reference](#18-configuration-reference)
19. [Composer Dependencies](#19-composer-dependencies)

---

## 1. Project Overview

**Wiener Abfahrtsmonitor (WL Monitor)** is a PHP web application that displays real-time public transit departure times for Vienna (Wiener Linien). Users can search for stations by name or by GPS proximity, save up to any number of favorites, and configure display preferences. An admin panel allows management of user accounts and station data.

- **Live URL:** `https://www.jardyx.com/wl-monitor/`
- **Dev URL:** `http://localhost/wlmonitor.test`
- **Local production mirror:** `http://localhost/wlmonitor`

### Key capabilities

| Feature | Details |
|---|---|
| Realtime departures | Pulls from Wiener Linien OGD Realtime API every 20 seconds |
| Station search | By name (alphabetical) or GPS proximity (nearest 100 stations) |
| Favorites | Per-user saved stations with custom labels, colors, and sort order |
| Accounts | Registration, email verification, login, password reset, email change |
| Preferences | Departure count (1–5), theme (light/dark/auto), profile avatar |
| Admin | User list, user edit/reset/delete, OGD station data refresh |
| Themes | Light/dark/system-auto with zero FOUC (inline script before CSS paint) |
| Security | CSRF, CSP with nonces, rate limiting, bcrypt passwords, session hardening |

---

## 2. Architecture Overview

```
Browser
  │
  │  HTTP
  ▼
web/                    ← All files served directly by Apache
  ├── index.php         ← Main SPA shell
  ├── api.php           ← Unified JSON API (AJAX target)
  ├── login.php         ← Form page
  ├── authentication.php← POST handler → auth library
  ├── …                 ← Other form pages/handlers
  │
  ├── js/wl-monitor.js  ← Vanilla ES module, all interactivity
  └── css/              ← Custom CSS (no framework)

inc/                    ← PHP business logic (not web-accessible directly)
  ├── initialize.php    ← Bootstrap: config, DB, session, constants
  ├── html_header.php   ← <head> + CSS links + SVG sprite
  ├── html_footer.php   ← Footer + theme JS + closing tags
  ├── monitor.php       ← Wiener Linien API wrapper
  ├── stations.php      ← Station list queries
  ├── favorites.php     ← Favorites CRUD
  ├── admin.php         ← Admin user management
  └── ogd.php           ← OGD station data sync

vendor/erikr/auth/      ← Shared auth library (Composer, symlinked)
  ├── src/bootstrap.php ← Security headers, session, CSP
  ├── src/auth.php      ← Login, logout, session functions
  ├── src/csrf.php      ← CSRF token generation/verification
  ├── src/log.php       ← appendLog(), addAlert()
  └── src/mailer.php    ← send_mail() wrapper (PHPMailer)

config/db.json          ← DB + SMTP credentials (NOT deployed from repo)
data/ratelimit.json     ← Rate limit counters (runtime, NOT deployed)
```

### Request flow

Every PHP entry point starts with:

```php
require_once(__DIR__ . '/../inc/initialize.php');
```

`initialize.php` in turn:
1. Loads `vendor/autoload.php`
2. Reads `config/db.json` for the active environment
3. Defines all runtime constants (DB, SMTP, API key, version)
4. Opens `$con` (MySQLi connection)
5. Calls `auth_bootstrap([])` — sets security headers, starts hardened session, generates `$_cspNonce`
6. Populates session globals (`$loggedIn`, `$username`, `$img`, etc.)

After that, each file does its own work and includes the shared HTML partials.

---

## 3. Directory Structure

```
wlmonitor/
├── web/                    Web root (Apache document root)
│   ├── index.php
│   ├── api.php
│   ├── login.php
│   ├── register.php
│   ├── authentication.php
│   ├── registration.php
│   ├── activate.php
│   ├── logout.php
│   ├── forgotPassword.php
│   ├── executeReset.php
│   ├── changePassword.php
│   ├── preferences.php
│   ├── confirm_email.php
│   ├── editFavorite.php
│   ├── admin.php
│   ├── avatar.php
│   ├── monitor_json.php
│   ├── css/
│   │   ├── base/
│   │   │   ├── theme.css       CSS custom properties (light/dark/auto)
│   │   │   ├── reset.css       Box-sizing + body base
│   │   │   ├── layout.css      Grid, navbar, footer, spacing utilities
│   │   │   └── components.css  Buttons, forms, cards, alerts, modal, etc.
│   │   ├── app/
│   │   │   └── wl-monitor.css  App-specific: departure tables, line badges
│   │   └── icons.svg           SVG sprite (28 icons)
│   ├── js/
│   │   └── wl-monitor.js       All frontend logic
│   └── img/
│       └── user/               Legacy avatar files (blob storage now preferred)
├── inc/                    PHP includes (not web-accessible)
├── config/                 Credentials (git-ignored)
│   ├── db.json             Active credentials
│   └── db.json.example     Template
├── data/                   Runtime (git-ignored)
│   └── ratelimit.json
├── vendor/                 Composer dependencies
├── scripts/
│   └── deploy.sh           Deployment script
├── tests/                  PHPUnit tests
└── docs/                   Documentation
```

---

## 4. Entry Points (web/)

### 4.1 index.php — Main dashboard

| | |
|---|---|
| Method | GET |
| Auth | None required (login-aware) |
| Session | Reads `$loggedIn`, `$username`, `$userID`, `$theme`, `$rights` |

Assembles the full-page SPA shell:
- Navbar with station search input, profile dropdown (avatar + username when logged in), theme selector
- `#monitor` — departure cards (populated by JS)
- `#buttons` — favorites buttons (populated by JS)
- Passes PHP state to JS via an inline `wlConfig` object:

```javascript
var wlConfig = {
  loggedIn: <?= $loggedIn ?>,
  userId: <?= $userID ?>,
  csrfToken: "<?= csrf_token() ?>",
  maxDepartures: <?= MAX_DEPARTURES ?>
};
```

The `<script>` tag loading `wl-monitor.js` has `defer` — the page renders immediately, JS loads in background.

---

### 4.2 api.php — Unified JSON API

| | |
|---|---|
| Methods | GET (reads), POST (writes) |
| Input | `?action=<name>` + action-specific params |
| Output | Always `application/json; charset=utf-8` |

All AJAX calls from `wl-monitor.js` and the admin panel target this single file. Action is read from `$_GET['action']`.

**Access control layers:**

```
api_require_login()  → HTTP 401 if not logged in
api_require_admin()  → HTTP 403 if rights ≠ 'Admin'
api_require_csrf()   → HTTP 403 if CSRF token mismatch
```

**Action reference:**

| Action | Method | Auth | Description |
|---|---|---|---|
| `monitor` | GET | — | Departure data for `?diva=` |
| `stations` | GET | — | Station list (alpha or by distance if lat/lon given) |
| `theme_save` | POST | login + CSRF | Save theme to DB |
| `position_save` | POST | login + CSRF | Save GPS coords to session |
| `favorites` | GET | login | List user's favorites |
| `favorites_check` | GET | login | Check if DIVA is already favorited |
| `favorites_add` | POST | login + CSRF | Create favorite |
| `favorites_edit` | POST | login + CSRF | Update favorite |
| `favorites_delete` | POST | login + CSRF | Delete favorite |
| `favorites_sort` | POST | login + CSRF | Batch reorder favorites |
| `log` | GET | login | Paginated activity log |
| `admin_ogd_update` | POST | admin + CSRF | Download & reload station data |
| `admin_users` | GET | admin | Paginated user list with optional filter |
| `admin_user_edit` | POST | admin + CSRF | Edit user record |
| `admin_user_reset` | POST | admin + CSRF | Generate new password |
| `admin_user_delete` | POST | admin + CSRF | Delete user |

---

### 4.3 authentication.php — Login handler

POST target for the login form. Validates CSRF, calls `auth_login()`, on success loads `wl_preferences.departures` into session, optionally sets `wlmonitor_username` cookie (remember name), redirects to `index.php`.

---

### 4.4 login.php / register.php — Form pages

GET-only. Both redirect to `index.php` if already logged in. Both apply the saved theme via inline `<script nonce="…">` before CSS paints, eliminating flash of wrong theme.

---

### 4.5 registration.php — Registration handler

POST target for the registration form.

**Validation chain (short-circuits on first failure):**
1. CSRF token
2. Rate limit: 3 attempts / 15 min per IP (`key: 'reg:<IP>'`)
3. All fields present
4. Email format (`FILTER_VALIDATE_EMAIL`)
5. Password ≥ 8 chars
6. Email uniqueness (SELECT against `jardyx_auth.auth_accounts`)

**On success:**
- Hashes password (bcrypt, cost 13)
- Generates activation code: `bin2hex(random_bytes(64))` (128-char hex)
- `INSERT` into `jardyx_auth.auth_accounts` with `disabled=1`
- `INSERT` into `wl_preferences` (departures = 2)
- Sends activation email with link to `activate.php?email=X&code=Y`
- Redirects to `login.php` with success alert

Account is unusable until `activate.php` verifies the code.

---

### 4.6 activate.php — Email activation

Receives `?email=` and `?code=` from the activation link. Validates both against `jardyx_auth.auth_accounts`. On match: sets `activation_code='activated'`, `disabled=0`. On failure: shows error.

---

### 4.7 forgotPassword.php — Password reset request

Handles both GET (form) and POST (submission). Rate-limited (3 / 15 min per IP). Does **not** distinguish between "email found" and "email not found" in the response (user enumeration prevention). On a valid email: deletes old reset records, inserts new `password_resets` row with a 32-byte token and 1-hour expiry, sends reset email.

---

### 4.8 executeReset.php — Password reset confirmation

GET: validates `?token=` against `password_resets` (not expired, not used), renders form.
POST: validates CSRF, token, password length, confirmation match. On success: hashes new password (bcrypt cost 13), updates account, marks reset used, redirects to login.

---

### 4.9 preferences.php — User settings

Requires login. Handles five POST actions via a hidden `action` field:

| Action | What it does |
|---|---|
| `upload_avatar` | Validates MIME + size, stores binary blob in DB |
| `change_email` | Verifies password, checks uniqueness, sends confirmation link |
| `change_password` | Verifies old password, hashes new, updates DB |
| `change_theme` | Updates DB + session + cookie |
| `change_departures` | Updates `wl_preferences.departures` (1–5) |

---

### 4.10 confirm_email.php — Email change confirmation

Receives `?code=`. Validates against `email_change_code` in DB, checks `pending_email` is not null, re-verifies new email uniqueness (race condition guard), then atomically: sets `email = pending_email`, clears `pending_email` and `email_change_code`.

---

### 4.11 admin.php — Admin panel

Requires login + Admin rights. Renders paginated user table with search/filter. Uses inline JS (not wl-monitor.js) for AJAX user management calls to api.php. Provides OGD data refresh button. All data mutations go through api.php actions with CSRF.

---

### 4.12 avatar.php — Avatar serving

Serves binary image data from `auth_accounts.img_blob`. No authentication required (avatars are application-public, analogous to Gravatar URLs). MIME type validated against a whitelist (`image/jpeg`, `image/png`, `image/gif`, `image/webp`) before setting `Content-Type`. Returns HTTP 404 if no blob is stored. Sets `Cache-Control: public, max-age=3600`.

---

### 4.13 monitor_json.php — Standalone JSON feed

A minimal public endpoint (no session, no auth) that returns departure data as JSON. Intended for external integrations such as Home Assistant dashboards. Accepts `?diva=` (comma-separated). Returns HTTP 503 on API failure.

---

### 4.14 editFavorite.php — Favorite edit form

GET: renders edit form pre-populated from DB (ownership-checked). Station DIVAs are shown as split-button pills (name part opens the station editor; × removes the station). A station search box lets the user add new DIVAs; selecting one opens the editor before the pill is committed.

The station editor (`#stationEditor`) fetches live departure data for the selected DIVA via `api.php?action=monitor`, renders a checkbox list of all lines/platforms served, and stores the selection in `perStationFilter[diva]`. Existing selections are pre-checked on re-open. Pills show a `●` indicator when a filter is active. "Keine Auswahl = alle Linien" is shown as helper text.

On form submit the JS collects `perStationFilter` into the hidden `filter_json` field. The server validates it via `favorites_validate_filter()` before storing.

POST: validates CSRF, re-checks ownership (`WHERE id=? AND idUser=?`), sanitizes all inputs, updates row (including `filter_json`), sets `$_SESSION['loadFavId']` so index.php auto-loads the edited favourite on redirect, logs, redirects to `index.php`.

---

## 5. Business Logic (inc/)

### 5.1 initialize.php

Bootstrap file. Must be the first include in every entry point. Responsibilities:

1. Autoloads Composer packages
2. Reads `config/db.json`, selects environment block based on `APP_ENV` env var (defaults to `'local'`)
3. Defines constants: `SCRIPT_PATH`, `AVATAR_DIR`, `APIKEY`, `MAX_DEPARTURES`, `APP_VERSION`, `APP_BUILD`, `DATABASE_*`, `SMTP_*`, `AUTH_DB_PREFIX`
4. Opens `$con` (MySQLi with strict error reporting)
5. Calls `auth_bootstrap([])` → emits security headers, starts session, creates `$_cspNonce`
6. Populates `$loggedIn`, `$username`, `$img`, `$avatarDir` from session
7. Defines helpers:
   - `sanitizeDivaInput(string): string` — strips everything except `[0-9,]`
   - `icon(string $id, string $class = ''): string` — renders `<svg class="icon …"><use href="css/icons.svg#icon-{id}"></use></svg>`

---

### 5.2 monitor.php — `monitor_get()`

```php
function monitor_get(mysqli $con, string $divaRaw, int $maxDepartures): array
```

Sanitizes DIVA input, builds the API URL (each DIVA becomes a separate `&diva=` parameter), fetches JSON via `file_get_contents()`, parses the `data.monitors` array, and returns a structured result.

**Important:** The WL Realtime API returns **one monitor entry per line**, not per station. Entries for the same station are interleaved with entries for other stations (e.g. `60200470 59A/H`, `60200103 59A/H`, `60200103 59A/R`, `60200470 59A/R`, …). `monitor_get` initialises each station on first encounter and appends subsequent entries so all lines are accumulated correctly.

```php
[
  '60200103' => [
    'id'           => '60200103',
    'diva'         => '60200103',
    'station_name' => 'Aßmayergasse',
    'lines'        => [
      [
        'name'       => '59A',
        'towards'    => 'Bhf. Meidling S U',
        'type'       => 'ptBusCity',
        'direction'  => 'H',
        'platform'   => '1',
        'departures' => '3, 8',  // 0 → '*' (vehicle at platform)
      ],
      // … more lines
    ],
  ],
  'trains'    => 12,          // total line-direction rows
  'update_at' => '14:32:07',  // server timestamp from API
  'api_ping'  => -1,          // server time − local time in seconds
]
```

Throws `InvalidArgumentException` on empty DIVA input; `RuntimeException` on network failure, invalid JSON, or empty monitors array.

The `monitor` action in `api.php` post-processes this result: any DIVA present in the request but absent from the API response (the WL API silently omits stops with no upcoming departures) is injected as an empty placeholder entry (`lines: []`). Station names for missing DIVAs are resolved via `diva_info()`. This ensures filtered-favourite cards always render, showing "Keine aktuellen Abfahrten" rather than disappearing entirely.

---

### 5.3 stations.php

Three functions:

| Function | Returns | Notes |
|---|---|---|
| `stations_by_distance(mysqli, float $lat, float $lon)` | Up to 100 nearest stations | Uses `ST_Distance_Sphere`; distances rounded to nearest 30 m |
| `stations_alpha(mysqli)` | All ~4,000 stations alphabetically | Full list for search input |
| `stations_save_position(mysqli, float, float)` | void | Stores coords in session |

---

### 5.4 favorites.php

All write functions embed `idUser` in the SQL `WHERE` clause — direct object reference attacks are impossible even if an attacker crafts arbitrary `id` values.

| Function | Action |
|---|---|
| `favorites_get($con, $idUser)` | Returns all favorites ordered by `sort`, then `id`; decodes `filter_json` into a `filter` key |
| `favorites_check($con, $idUser, $diva)` | Returns bool — exact DIVA string already favorited? |
| `favorites_add($con, $idUser, $title, $diva, $bclass, $sort, ?$filterJson)` | Inserts, returns new row ID |
| `favorites_edit($con, $idUser, $favId, …, ?$filterJson)` | Updates, ownership-checked |
| `favorites_delete($con, $idUser, $favId)` | Deletes, ownership-checked |
| `favorites_save_sort($con, $idUser, array $items)` | Batch `UPDATE` sort from `[{id, sort}]` array |
| `favorites_validate_filter(?string $filterJson)` | Sanitises and normalises the per-station filter JSON |

Input sanitization applied in `favorites_add` and `favorites_edit`:
- `title`: `strip_tags()` + `mb_substr(0, 100)`
- `diva`: `sanitizeDivaInput()` → `[0-9,]` only
- `bclass`: `preg_replace('/[^a-z0-9-]/', '', …)`
- `filter_json`: validated by `favorites_validate_filter()` — expects an object keyed by DIVA strings, each value an array of `{line, platform}` objects. Keys stripped to `[0-9]`; `line` to `[A-Za-z0-9/\- ]` (max 10 chars); `platform` to `[A-Za-z0-9 ]` (max 10 chars). Empty or invalid input normalised to `null`.

---

### 5.5 admin.php

| Function | Notes |
|---|---|
| `admin_list_users($con, $page, $perPage, $filter)` | Paginates users; escapes LIKE special chars to prevent wildcard injection |
| `admin_edit_user($con, $targetId, $email, $rights, $disabled, $departures, $debug)` | Validates `$rights` ∈ `['Admin', 'User']`; updates both auth table and wl_preferences |
| `admin_reset_password($con, $targetId)` | Generates 8 random bytes → 16-char hex plaintext (shown once), stores bcrypt hash |
| `admin_delete_user($con, $targetId, $requestingUserId)` | Blocks self-deletion |

These functions do not verify admin role themselves — authorization is enforced by `api_require_admin()` in `api.php` before any of these are called.

---

### 5.6 ogd.php — `ogd_update()`

Downloads three CSV files from `data.wien.gv.at` (lines, stations, stops), truncates and reloads the three OGD source tables inside a transaction, then recreates two views (`ogd_stations`, `ogd_diva`). Uses a file lock (`data/ogd_update.lock`) to prevent concurrent runs. Returns `['ok' => bool, 'log' => string[], 'error' => string|null]`.

---

## 6. Frontend: JavaScript

**File:** `web/js/wl-monitor.js`
**Type:** Plain ES module, `defer`, no external dependencies, no build step.

### 6.1 State

| Variable | Type | Purpose |
|---|---|---|
| `stationCache` | `array` | Cached station list for current sort mode |
| `currentSort` | `'alpha'|'dist'` | Active sort mode |
| `stationOrigin` | `{lat, lon}|null` | User's GPS coords when in distance mode |
| `monitorTimer` | `number` | `setInterval` ID for 20-second refresh |
| `currentMonitor` | `{diva, favId, fav}` | Active monitor context (null fav = station search) |
| `currentMonitorLines` | `array` | `[{diva, line, platform, direction, towards}]` — rebuilt on every render; used to populate add-favourite line checkboxes |
| `addModalDiva` | `string|null` | DIVA override for the add-favourite modal when opened from a per-station `+` button |

### 6.2 Initialization (DOMContentLoaded)

The handler is `async` so it can `await loadFavorites()` before deciding which monitor to show.

```
applyTheme()
initDropdowns()
initModals()
initAlerts()
favs = await loadFavorites()
if (wlConfig.loadFavId)
  loadMonitor(targetFav.diva, targetFav)  ← auto-load after editFavorite save
else
  loadMonitor()
startMonitorTimer()     ← 20-second interval
wireScrollButton()
wireStationSort()
wireStationDropdown()
```

`wlConfig.loadFavId` is set when `editFavorite.php` stores the saved favourite's ID in `$_SESSION['loadFavId']` before redirecting back to `index.php`.

### 6.3 API Communication

```javascript
apiFetch(action, params = {})   // GET  → api.php?action=X&key=val
apiPost(action, body = {})      // POST → api.php (FormData, includes CSRF token)
```

`apiPost` reads the CSRF token from the hidden `input[name="csrf_token"]` rendered by `csrf_input()` in the HTML.

### 6.4 Monitor (Departures)

`loadMonitor(diva?, fav?)` calls `api.php?action=monitor&diva=…`. On success, `renderMonitor(data)` builds:

- One card per station
- Within each card: one row per line+direction combination
- Line badge (colored, type-dependent — see §7.4)
- Platform number
- Destination (`towards`)
- Departure times as comma-separated minutes (`*` = at platform)
- Footer shows server timestamp and ping

**Per-station line filtering:** When `currentMonitor.fav.filter` is set (a JSON object keyed by DIVA), `renderMonitor` applies it: only lines whose `{line, platform}` pair is listed under `filter[s.diva]` are shown. Unfiltered stations (no filter key for their DIVA) show all their lines. Filtered stations with no matching lines still render with a "Keine aktuellen Abfahrten" placeholder row — they are never silently hidden.

Refresh runs every 20 seconds via `startMonitorTimer()`.

### 6.5 Station Search

`loadStationsAlpha()` and `loadStationsByDistance(position)` both cache their results in `stationCache`. `renderStationList()` then filters by the search input value in real time. Clicking a station closes the dropdown and calls `loadMonitor(diva)`.

Distance mode triggers `navigator.geolocation.getCurrentPosition()`. On permission denial or error, falls back to alpha mode.

### 6.6 Favorites

`loadFavorites()` → `renderFavorites(favs)` creates one button per favorite. Button click loads that favorite's departure data. An edit icon in the button group redirects to `editFavorite.php?favID=X`.

If the favourite has a `filter` set, a subtitle line is appended below the title showing all filtered line+platform pairs (e.g. `59A 1 · U6 2`).

### 6.7 UI Components (pure JS replacements for Bootstrap JS)

| Function | Replaces |
|---|---|
| `initDropdowns()` | `data-bs-toggle="dropdown"` |
| `initModals()` | `data-bs-toggle="modal"` / `bootstrap.Modal` |
| `initAlerts()` | `data-bs-dismiss="alert"` + auto-remove after 6 s |
| `openModal(id)` / `closeModal(id)` | `bootstrap.Modal.getInstance().show/hide()` |

All three bootstrap functions are driven by `data-*` attributes on HTML elements, not hardcoded selectors:
- `data-dropdown-toggle` → toggles parent `.dropdown-menu`
- `data-modal-open="id"` → calls `openModal(id)`
- `data-modal-close` → closes closest `.modal`
- `data-dismiss-alert` → removes closest `.alert`

### 6.8 SVG Icon Creation (JS context)

```javascript
function makeSvgIcon(id, cls) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('class', 'icon' + (cls ? ' ' + cls : ''));
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  const use = document.createElementNS(ns, 'use');
  use.setAttribute('href', 'css/icons.svg#icon-' + id);
  svg.appendChild(use);
  return svg;
}
```

Uses `createElementNS` (safe DOM API) — no `innerHTML`, no XSS vector.

---

## 7. Frontend: CSS System

The CSS is split into a portable base layer and an app-specific layer. No preprocessor, no build step, plain CSS only.

### 7.1 Load order

```html
<link rel="stylesheet" href="css/base/theme.css">    <!-- 1. Variables -->
<link rel="stylesheet" href="css/base/reset.css">    <!-- 2. Reset -->
<link rel="stylesheet" href="css/base/layout.css">   <!-- 3. Layout -->
<link rel="stylesheet" href="css/base/components.css"><!-- 4. Components -->
<link rel="stylesheet" href="css/app/wl-monitor.css"><!-- 5. App-specific -->
```

### 7.2 theme.css — CSS Custom Properties

Defines all color and spacing tokens. No rules, no selectors beyond `:root`, `[data-theme="dark"]`, and a `@media (prefers-color-scheme: dark)` block.

Key variable groups:
- `--color-bg`, `--color-surface`, `--color-surface-alt` — backgrounds
- `--color-text`, `--color-muted`, `--color-border` — text and borders
- `--color-primary`, `--color-primary-hover` — interactive elements
- `--color-danger`, `--color-success`, `--color-warning`, `--color-info` — semantic
- `--color-nav-bg`, `--color-nav-text` — navbar-specific
- `--font-sans`, `--font-mono` — system font stacks
- `--radius`, `--radius-sm`, `--shadow-sm`, `--shadow` — structural

### 7.3 reset.css

Box-sizing reset, body base styles from CSS vars, `color-scheme: light dark`, smooth scroll, image display normalization, link and list baseline.

### 7.4 layout.css

- **Containers:** `.container` (1200px), `.container-fluid`, `.container-sm` (480px), `.container-md` (560px), `.container-lg` (640px), `.container-xl` (800px)
- **Grid:** `.row` (12-column CSS Grid), `.col-md-{4,6,8}` (grid spans), responsive stacking at 768px
- **Navbar:** `.navbar` — sticky top, flex, height 56px, border-bottom
- **Footer:** `.wl-footer` — fixed bottom, flex, height 48px, border-top
- **Spacing utilities:** `.mt-{1–5}`, `.mb-{1–5}`, `.ms-{1–3}`, `.me-{1–3}`, `.p-{1–4}`, `.py-{1–3}`, `.px-{1–4}`, `.gap-{2,3}`
- **Display:** `.d-flex`, `.d-grid`, `.d-block`, `.d-none`, `.d-inline`, `.d-sm-inline` (hidden < 576px)
- **Flex helpers:** `.flex-grow-1`, `.align-items-center`, `.justify-content-between`, `.ms-auto`, `.w-100`
- **Text:** `.text-muted`, `.text-center`, `.text-nowrap`, `.fw-semibold`, `.fw-bold`, `.small`

### 7.5 components.css

**Buttons:** `.btn` base, `.btn-primary`, `.btn-secondary`, `.btn-outline-{primary,secondary,danger}`, `.btn-sm`, `.btn-group`, `.btn-check` (radio pattern), `.btn-nav` (navbar-adaptive), `.btn-footer-toggle`, `.btn-close`

**Forms:** `.form-control` (input/textarea/select), `.form-label`, `.form-text`, `.form-check` (checkbox/radio), `.form-range`, `.input-group`, `.is-invalid` / `.invalid-feedback`

**Cards:** `.card`, `.card-header`, `.card-body`, `.card-footer`, `.shadow-sm`

**Alerts:** `.alert`, `.alert-{danger,success,info,warning}`, `.alert-dismissible`

**Tables:** `.table`, `.table-sm`, `.table-hover`, `.table-dark`, `.table-responsive`

**Modal:** `.modal` (hidden by default; `display: flex` + overlay when `.show`)

**Dropdown:** `.dropdown-menu` (hidden by default; `display: block` when `.show`)

**Other:** `.badge`, `.spinner-border`, `.spinner-border-sm`, `.list-unstyled`, `.rounded-circle`, `.visually-hidden`, `.icon` (SVG sizing)

### 7.6 wl-monitor.css (app-specific)

**Departure table:**

| Class | Purpose |
|---|---|
| `.departure-table` | `table-layout: fixed`, full width |
| `.badge-cell` | 2.8em wide (line badge) |
| `.platform-cell` | 2.2em (platform number) |
| `.towards-cell` | Flexible, text-overflow: ellipsis |
| `.times-cell` | Right-aligned, `font-family: var(--font-mono)` |

**Line badges:** `.line-badge` base (2.4em square, centered, bold, 0.7rem font)

| Variant | Visual |
|---|---|
| `.pt-metro` | Square; per-line color: U1 #e2001a, U2 #a762a3, U3 #ec6725, U4 #23a74e, U5 #0e8c5e, U6 #9a6b35 |
| `.pt-tram` | Black circle (inverted in dark mode) |
| `.pt-bus-city` | Navy square |
| `.pt-bus-night` | Navy square, orange text |
| `.pt-bus-region` | Black circle, yellow text |
| `.pt-train` | Red square |
| `.pt-train-s` | Blue square |
| `.pt-tram-wlb` | Blue square + SVG logo (brightness filter for dark mode) |
| `.pt-default` | Grey square |

**Other:** `.station-dropdown` (absolute positioned list, max-height 60vh, scrollable), `nav.navbar #s` (theme-adaptive search input), `#topBtn` (back-to-top, fixed bottom-right), `body { padding-bottom: 48px }` (footer clearance).

---

## 8. SVG Icon Sprite

**File:** `web/css/icons.svg`

An inline SVG sprite (hidden: `style="display:none"`) inlined at the top of `<body>` via `readfile()` in `html_header.php`. Each icon is a `<symbol id="icon-*">` element.

**PHP helper:**
```php
function icon(string $id, string $class = ''): string {
    $c = 'icon' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') : '');
    return '<svg class="' . $c . '" aria-hidden="true" focusable="false">'
         . '<use href="css/icons.svg#icon-' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"></use>'
         . '</svg>';
}
```

**JS helper:** `makeSvgIcon(id, cls)` — see §6.8.

**Complete icon list (28 icons):**

| ID | Usage |
|---|---|
| `adjust` | Theme indicator (auto mode) |
| `arrow-left` | Back navigation |
| `arrow-up` | Back-to-top button |
| `camera` | Avatar upload prompt |
| `chevron-down` | Dropdown toggle |
| `database` | OGD data management |
| `envelope` | Email fields |
| `expand` | Fullscreen toggle |
| `key` | Login, password fields |
| `list-ol` | Departures count setting |
| `map-marker` | Location / distance sort |
| `moon` | Dark theme |
| `palette` | Theme settings |
| `paper-plane` | Send / submit email |
| `save` | Form submit |
| `search` | Station search |
| `sign-in` | Login |
| `sign-out` | Logout |
| `sort-alpha` | Alphabetical sort |
| `subway` | App logo / transit icon |
| `sun` | Light theme |
| `sync` | OGD data refresh |
| `upload` | File upload |
| `user` | User reference |
| `user-circle` | Profile picture placeholder |
| `user-cog` | User settings |
| `users-cog` | Admin / user management |

SVG paths sourced from Heroicons v2 (MIT license).

---

## 9. Database Schema

The application spans two MySQL databases: `jardyx_auth` (shared auth library tables) and `wlmonitor` / `wlmonitor_dev` (app-specific tables).

### 9.1 jardyx_auth.auth_accounts

Primary user record table, owned by the auth library.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK AI | |
| `username` | VARCHAR UNIQUE | Display name, used for login |
| `password` | VARCHAR | bcrypt hash, cost 13 |
| `email` | VARCHAR UNIQUE | |
| `activation_code` | VARCHAR | Random 128-char hex; `'activated'` when verified |
| `disabled` | TINYINT | `0` = active, `1` = disabled |
| `invalidLogins` | INT | Failed login counter |
| `rights` | VARCHAR | `'Admin'` or `'User'` |
| `theme` | VARCHAR | `'light'`, `'dark'`, or `'auto'` |
| `img_blob` | LONGBLOB | Binary avatar image |
| `img_type` | VARCHAR | MIME type of blob |
| `img_size` | INT | Blob size in bytes |
| `img` | VARCHAR | Legacy avatar filename (superseded by blob) |
| `lastLogin` | TIMESTAMP | |
| `debug` | TINYINT | Debug flag for admin use |
| `pending_email` | VARCHAR | New email awaiting confirmation |
| `email_change_code` | VARCHAR | 64 hex chars confirmation token |

### 9.2 jardyx_auth.auth_log

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK AI | |
| `idUser` | INT | NULL for unauthenticated actions |
| `context` | VARCHAR | `'auth'`, `'reg'`, `'pwd_reset'`, `'prefs'`, etc. |
| `activity` | TEXT | Human-readable description |
| `origin` | VARCHAR | `'web'`, `'api'`, `'cli'` |
| `ipAdress` | INT | Stored as `INET_ATON()` |
| `logTime` | TIMESTAMP | |

### 9.3 jardyx_auth.password_resets

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK AI | |
| `user_id` | INT | FK → auth_accounts.id |
| `token` | VARCHAR | 64 hex chars (32 random bytes) |
| `expires_at` | TIMESTAMP | NOW + 1 hour |
| `used` | TINYINT | `0` = valid, `1` = already consumed |

### 9.4 wl_preferences

| Column | Type | Notes |
|---|---|---|
| `user_id` | INT PK | FK → auth_accounts.id |
| `departures` | INT | 1–5; max displayed per line per direction |

### 9.5 wl_favorites

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK AI | |
| `idUser` | INT | FK → auth_accounts.id |
| `title` | VARCHAR(100) | User-defined label; sanitized |
| `diva` | VARCHAR | Comma-separated DIVA station IDs |
| `bclass` | VARCHAR | Button color class (`btn-outline-success`, etc.) |
| `sort` | INT | Display order (lower = first) |
| `filter_json` | TEXT NULL | Per-station line filter: `{"diva": [{"line":"59A","platform":"1"}, …], …}` |
| `created` | TIMESTAMP | |
| `updated` | TIMESTAMP | |

Migration: `ALTER TABLE wl_favorites ADD COLUMN filter_json TEXT NULL DEFAULT NULL;`

### 9.6 OGD source tables

Data loaded by `ogd_update()` from Wiener Linien open data CSV feeds.

**ogd_haltestellen** (stations)

| Column | Notes |
|---|---|
| `HALTESTELLEN_ID` | PK |
| `DIVA` | 8-digit station ID |
| `NAME` | Station name |
| `WGS84_LAT`, `WGS84_LON` | Coordinates |
| `GEMEINDE` | Municipality |
| `STAND` | Data version string |

**ogd_steige** (individual stops / platforms)

| Column | Notes |
|---|---|
| `STEIG_ID` | PK |
| `FK_LINIEN_ID` | FK → ogd_linien |
| `FK_HALTESTELLEN_ID` | FK → ogd_haltestellen |
| `RBL` | 4-digit stop-level realtime ID |
| `DIVA` | Populated from ogd_haltestellen via FK join |
| `RICHTUNG` | Direction |
| `STEIG` | Platform identifier |
| `STEIG_WGS84_LAT`, `STEIG_WGS84_LON` | Stop-level coordinates |

**ogd_linien** (transit lines)

| Column | Notes |
|---|---|
| `LINIEN_ID` | PK |
| `BEZEICHNUNG` | Line name (U1, 1, 66, N60, …) |
| `VERKEHRSMITTEL` | Type: `ptMetro`, `ptTram`, `ptBusCity`, `ptBusNight`, `ptTrain`, … |
| `ECHTZEIT` | Realtime availability flag |

### 9.7 OGD views

**ogd_stations** — Groups `ogd_steige` by `DIVA` (station level), aggregates lines, joins coordinates from `ogd_haltestellen`. Used by `stations_alpha()` and `stations_by_distance()`.

**ogd_diva** — Groups by `RBL` (stop level). Used for exact-stop lookups.

### 9.8 Cross-database references

Auth tables live in `jardyx_auth`; WL Monitor tables live in `wlmonitor` (or `wlmonitor_dev`). Queries that span databases use the fully-qualified prefix defined in the constant:

```php
define('AUTH_DB_PREFIX', AUTH_DATABASE_NAME . '.');   // 'jardyx_auth.'
```

---

## 10. Authentication & Session Management

### 10.1 Registration → Activation → Login cycle

```
register.php (GET) ─── form submission ──→ registration.php (POST)
                                                  │
                                   validate → insert disabled account
                                                  │
                                         send activation email
                                                  ↓
                                          activate.php?email=X&code=Y
                                                  │
                                   enable account (disabled=0)
                                                  ↓
                                           login.php (GET)
                                                  │
                                   form submission → authentication.php (POST)
                                                  │
                                           auth_login()
                                                  │
                                     session regenerated, cookies set
                                                  ↓
                                             index.php
```

### 10.2 auth_login() internals (erikr/auth library)

1. Rate-limit check (5 failures / 15 min per IP)
2. Lookup by username in `auth_accounts` (generic error message — no username enumeration)
3. Verify `activation_code = 'activated'` and `disabled = 0`
4. `password_verify()` (bcrypt)
5. Transparent rehash if cost < 13
6. `session_regenerate_id(true)` (session fixation prevention)
7. Set session: `loggedin`, `id`, `username`, `email`, `img`, `rights`, `theme`
8. Set `sId` cookie (session recovery, 4 days, httponly, secure, samesite=Strict)
9. Set `theme` cookie (365 days)
10. Update `lastLogin`, clear `invalidLogins`
11. Log to `auth_log`

### 10.3 Session hardening

Session started via `auth_bootstrap()` with:

```php
session_start([
  'cookie_lifetime' => 4 * 24 * 3600,
  'cookie_httponly' => true,
  'cookie_secure'   => $isHttps,
  'cookie_samesite' => 'Strict',
  'use_strict_mode' => true,
]);
```

The `sId` cookie mirrors the session ID, allowing recovery across browser restarts without storing sensitive session data client-side.

### 10.4 Password reset flow

```
forgotPassword.php (POST)
  │ rate-limit (3/15 min/IP)
  │ lookup email (no response difference if not found)
  │ insert password_resets (token, expires=NOW+1h, used=0)
  └─→ email: executeReset.php?token=T

executeReset.php (GET)
  │ validate token (expires_at > NOW AND used = 0)
  └─→ show new password form

executeReset.php (POST)
  │ validate CSRF + password + confirmation
  │ update auth_accounts.password
  └─→ mark reset used=1 → redirect login.php
```

### 10.5 Email change flow

```
preferences.php (POST, action=change_email)
  │ verify current password
  │ check new email uniqueness
  │ store: pending_email, email_change_code
  └─→ email: confirm_email.php?code=C

confirm_email.php (GET)
  │ validate code matches email_change_code in DB
  │ re-check new email uniqueness (race guard)
  │ SET email=pending_email
  └─→ CLEAR pending_email, email_change_code → redirect
```

---

## 11. Wiener Linien API Integration

### 11.1 Endpoint

```
GET https://www.wienerlinien.at/ogd_realtime/monitor
```

### 11.2 Parameters

| Parameter | Value | Notes |
|---|---|---|
| `diva` | 8-digit station ID | Repeat for multiple stations |
| `sender` | `tVqqssNTeDyFb35` | API key (from `APIKEY` constant) |
| `activateTrafficInfo` | `stoerungkurz` | Short disruption alerts |
| `activateTrafficInfo` | `stoerunglang` | Long disruption alerts |

Comma-separated DIVAs from user input are expanded to repeated parameters:
```
diva=60200103,60200104 → ?diva=60200103&diva=60200104
```

### 11.3 Response structure (abridged)

```json
{
  "data": {
    "monitors": [
      {
        "locationStop": {
          "properties": { "name": "STK60200103", "title": "Karlsplatz" }
        },
        "lines": [
          {
            "name": "U1",
            "towards": "Leopoldau",
            "type": "ptMetro",
            "direction": "H",
            "platform": "1",
            "departures": {
              "departure": [
                { "departureTime": { "countdown": 3 } },
                { "departureTime": { "countdown": 8 } }
              ]
            }
          }
        ]
      }
    ]
  },
  "message": { "serverTime": "2026-04-08T14:32:07+02:00" }
}
```

`countdown = 0` means the vehicle is currently at the platform; rendered as `*`.

### 11.4 Transport type identifiers

| `type` value | Meaning |
|---|---|
| `ptMetro` | U-Bahn (metro) |
| `ptTram` | Straßenbahn (tram) |
| `ptBusCity` | Stadtbus (city bus) |
| `ptBusNight` | Nightline bus |
| `ptBusRegion` | Regional bus |
| `ptTrain` | Suburban train |
| `ptTramWLB` | Wiener Lokalbahnen (tram to Baden) |

---

## 12. Favorites System

### 12.1 Data model

A favorite represents a named, color-coded button that loads a set of departure monitors. A single favorite can reference multiple stations via comma-separated DIVA numbers in the `diva` column — useful for grouping nearby stops or both directions of a line.

### 12.2 CRUD operations via api.php

| Action | Method | Key inputs | Notes |
|---|---|---|---|
| `favorites` | GET | — | Returns ordered list |
| `favorites_check` | GET | `diva` | Prevents duplicate adds |
| `favorites_add` | POST | title, diva, bclass, sort | All inputs sanitized |
| `favorites_edit` | POST | favId, title, diva, bclass, sort | Ownership checked |
| `favorites_delete` | POST | id | Ownership checked |
| `favorites_sort` | POST | JSON array `[{id,sort}]` | Batch reorder |

### 12.3 IDOR prevention

Every SQL mutation embeds `idUser` in the `WHERE` clause:

```sql
UPDATE wl_favorites SET … WHERE id = ? AND idUser = ?
DELETE FROM wl_favorites WHERE id = ? AND idUser = ?
```

An attacker who guesses another user's `id` value cannot modify or delete their favorites.

### 12.4 Color classes

`bclass` maps to button outline variants. Validated pattern: `[a-z0-9-]`. Typical values: `btn-outline-primary`, `btn-outline-success`, `btn-outline-danger`, `btn-outline-warning`, `btn-outline-info`, `btn-outline-secondary`.

---

## 13. Security Architecture

### 13.1 HTTP Security Headers

Emitted by `auth_bootstrap()` on every request:

| Header | Value | Purpose |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | Prevents MIME-sniffing |
| `X-Frame-Options` | `DENY` | Prevents clickjacking |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Minimal referrer leakage |
| `Permissions-Policy` | `geolocation=(self), camera=(), microphone=()` | Feature restrictions |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | HTTPS enforcement (HTTPS only) |
| `Content-Security-Policy` | (see below) | Script/resource origin control |

### 13.2 Content Security Policy

```
default-src 'self'
script-src 'self' 'nonce-{NONCE}'
style-src 'self' 'unsafe-inline'
img-src 'self' data:
connect-src 'self'
font-src 'self'
frame-ancestors 'none'
base-uri 'self'
form-action 'self'
```

`{NONCE}` is `base64_encode(random_bytes(16))`, generated once per request and stored in `$_cspNonce`. Every `<script>` tag must carry a matching `nonce="…"` attribute. No `unsafe-eval`. No external script sources.

### 13.3 CSRF Protection

Token: `bin2hex(random_bytes(32))` (64 hex chars), generated once per session, stored in `$_SESSION['csrf_token']`.

- All HTML forms include `<?= csrf_input() ?>` (hidden input)
- JS `apiPost()` reads the token from the hidden input and includes it in `FormData`
- `csrf_verify()` uses `hash_equals()` for timing-safe comparison
- Alternative: token can be sent in `X-CSRF-TOKEN` header for non-form requests

### 13.4 Rate Limiting

Stored in `data/ratelimit.json` with `flock()` for concurrent access safety.

| Context | Key pattern | Limit | Window |
|---|---|---|---|
| Login (per IP) | `<IP>` | 5 | 15 min |
| Registration | `reg:<IP>` | 3 | 15 min |
| Password reset | `reset:<IP>` | 3 | 15 min |

### 13.5 Password Security

- Algorithm: bcrypt, cost factor 13
- Minimum length: 8 characters
- Transparent rehash: on login, if stored hash has cost < 13, rehash transparently
- Wrong password: increments `invalidLogins` counter (visible to admin)

### 13.6 Input Sanitization

| Context | Method |
|---|---|
| DIVA numbers | `preg_replace('/[^0-9,]/', '', …)` |
| Favorite titles | `strip_tags()` + `mb_substr(0, 100)` |
| Button classes | `preg_replace('/[^a-z0-9-]/', '', …)` |
| Email addresses | `FILTER_VALIDATE_EMAIL` + uniqueness check |
| Avatar MIME type | `getimagesize()` + whitelist: jpeg, png, gif, webp |
| HTML output | `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` throughout |
| SQL parameters | Prepared statements with `bind_param()` throughout |

### 13.7 IDOR Prevention

All favorites mutations include `idUser` in the SQL `WHERE` clause. The `admin_delete_user()` function blocks self-deletion. Avatar serving is public (no auth) but addresses are opaque integer IDs.

---

## 14. Theme System

### 14.1 Three-tier persistence

| Scope | Storage | Lifetime | Priority |
|---|---|---|---|
| Logged-in preference | `auth_accounts.theme` + `$_SESSION['theme']` | Permanent | Highest |
| Anonymous/explicit | `theme` cookie (non-httponly) | 365 days | Medium |
| System preference | CSS `@media (prefers-color-scheme: dark)` | — | Fallback |

### 14.2 FOUC elimination

Every form page (login, register, executeReset, etc.) includes an inline script **immediately after** the CSS links:

```html
<script nonce="<?= $_cspNonce ?>">
(function () {
  var t = <?= json_encode($theme) ?>;
  if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
})();
</script>
```

The script runs synchronously before the browser paints. The CSS variable system reacts to `document.documentElement.dataset.theme` immediately.

### 14.3 Theme switching

User selects a radio button (Auto / Light / Dark) in the footer. `setTheme(t)`:
1. Sets/clears `document.documentElement.dataset.theme`
2. Updates the `theme` cookie (365 days)
3. If logged in: POSTs to `api.php?action=theme_save` → updates `auth_accounts.theme` and `$_SESSION['theme']`

---

## 15. Avatar Upload & Serving

### 15.1 Upload

Form `enctype="multipart/form-data"` → `preferences.php` (POST, `action=upload_avatar`):

1. Checks `$_FILES['avatar']['error'] === UPLOAD_ERR_OK`
2. `getimagesize($file['tmp_name'])` → validates MIME whitelist (jpeg, png, gif, webp)
3. Checks `$file['size'] <= 2 * 1024 * 1024` (2 MB)
4. Reads binary via `file_get_contents($file['tmp_name'])`
5. `UPDATE auth_accounts SET img_blob=?, img_type=?, img_size=? WHERE id=?`
6. Logs, redirects

### 15.2 Serving

`avatar.php?id=<userId>`:
- Casts `id` to `int` (SQL injection prevention regardless of prepared statements)
- Queries `img_blob`, `img_type` where `img_blob IS NOT NULL`
- Validates `img_type` against whitelist before setting `Content-Type`
- Sets `Cache-Control: public, max-age=3600`
- Returns raw binary; HTTP 404 if no blob

### 15.3 UI usage

```html
<!-- Navbar -->
<img src="avatar.php?id=<?= $userID ?>"
     class="nav-avatar rounded-circle"
     onerror="this.style.display='none'">

<!-- Preferences page -->
<img src="avatar.php?id=<?= $userID ?>" class="rounded-circle">
```

---

## 16. OGD Station Data Sync

The station database is sourced from Vienna's official open data portal. `ogd_update()` in `inc/ogd.php`:

1. Acquires a file lock (`data/ogd_update.lock`, timeout 300 s)
2. Downloads three CSV files from `https://data.wien.gv.at/`:
   - `wienerlinien-ogd-haltestellen.csv` → `ogd_haltestellen`
   - `wienerlinien-ogd-steige.csv` → `ogd_steige`
   - `wienerlinien-ogd-linien.csv` → `ogd_linien`
3. Begins a transaction:
   - Truncates each table
   - Inserts rows from CSV
   - Backfills `ogd_steige.DIVA` from `ogd_haltestellen` via FK join
4. Recreates views `ogd_stations` and `ogd_diva`
5. Releases lock

Triggered by: admin panel → api.php `action=admin_ogd_update` (POST, admin + CSRF).

---

## 17. Deployment

### 17.1 Environments

| | Dev | Local production mirror | Remote production |
|---|---|---|---|
| URL | `localhost/wlmonitor.test` | `localhost/wlmonitor` | `jardyx.com/wl-monitor` |
| Root | `/Users/erikr/Git/wlmonitor/web` | `/Library/WebServer/Documents/wlmonitor/web` | `/home/.sites/765/site679/web/jardyx.com/wlmonitor/web` |
| Database | `wlmonitor_dev` | `wlmonitor` | `5279249db19` (world4you) |
| `APP_ENV` | `local` (default) | `local` (default) | `production` (via `.htaccess`) |

### 17.2 scripts/deploy.sh

Interactive script with two modes:

**Local mode** (`l`):
- `rsync` to `/Library/WebServer/Documents/wlmonitor/`
- Writes `config/db.json` with local-production credentials (wlmonitor DB)
- Removes stale `.htaccess` (APP_ENV stays `local`)
- Excluded: `.git`, `tests`, `deprecated`, `docs`, `config`, `data`, `composer.*`, `*.md`

**Production mode** (`p`):
- Requires `PROD_SSH_USER` + `PROD_SSH_HOST` set in script
- `rsync` over SSH
- Writes `web/.htaccess` on remote: `SetEnv APP_ENV production`
- Creates `data/` on remote if missing

### 17.3 Environment detection in PHP

```php
$_dbEnv = getenv('APP_ENV') ?: 'local';
$_db    = $_dbConfig[$_dbEnv] ?? $_dbConfig['local'];
```

`APP_ENV` is set via Apache `SetEnv` in `.htaccess` (production) or not set at all (defaults to `'local'`).

---

## 18. Configuration Reference

**`config/db.json`** (never committed, excluded from deploy rsync):

```json
{
  "local": {
    "host": "localhost",
    "user": "wlmonitor",
    "pass": "…",
    "name": "wlmonitor_dev",
    "auth_name": "jardyx_auth",
    "base_url": "http://localhost/wlmonitor.test"
  },
  "production": {
    "host": "mysqlsvr78.world4you.com",
    "user": "sql6675098",
    "pass": "…",
    "name": "5279249db19",
    "auth_name": "jardyx_auth",
    "base_url": "https://www.jardyx.com/wl-monitor"
  },
  "smtp_local": { … },
  "smtp_production": { … }
}
```

**`inc/initialize.php` constants:**

| Constant | Value | Notes |
|---|---|---|
| `APIKEY` | `tVqqssNTeDyFb35` | Wiener Linien API key |
| `MAX_DEPARTURES` | `2` | Default max departures per line |
| `APP_VERSION` | `3.0` | |
| `APP_BUILD` | `9` | Increment on every code-changing session |
| `AUTH_DB_PREFIX` | `jardyx_auth.` | Cross-DB table prefix |
| `AVATAR_DIR` | `img/user/` | Legacy avatar path |
| `RATE_LIMIT_FILE` | `data/ratelimit.json` | Rate limit state file |

---

## 19. Composer Dependencies

**`composer.json`:**
```json
{
  "repositories": [{ "type": "path", "url": "../auth" }],
  "require": { "erikr/auth": "*" },
  "require-dev": { "phpunit/phpunit": "^13.0" }
}
```

**Runtime dependency: `erikr/auth`**

A custom, project-shared PHP library loaded from the sibling Git repository (`../auth`), symlinked at `vendor/erikr/auth`. Deployed via `--copy-links` in rsync to copy actual files to the server.

Provides (via Composer `autoload.files`):
- `auth_bootstrap()`, `auth_login()`, `auth_logout()`, `auth_get_user()`
- `csrf_token()`, `csrf_input()`, `csrf_verify()`
- `rate_limit_check()`, `rate_limit_record()`, `rate_limit_clear()`
- `appendLog()`, `addAlert()`, `getUserIpAddr()`
- `send_mail()` (PHPMailer wrapper)

**Dev dependency: `phpunit/phpunit ^13.0`**

Test runner. Tests live in `tests/`. Not deployed (excluded by rsync).
