# WL Monitor — Architecture & Deployment Reference

## Table of Contents

1. [Overview](#1-overview)
2. [Directory Structure](#2-directory-structure)
3. [Request Flow](#3-request-flow)
4. [Module Reference](#4-module-reference)
5. [Database Schema](#5-database-schema)
6. [Frontend Architecture](#6-frontend-architecture)
7. [Security Architecture](#7-security-architecture)
8. [Configuration Reference](#8-configuration-reference)
9. [Deploying to a Clean Production Environment](#9-deploying-to-a-clean-production-environment)
10. [CLI Scripts Reference](#10-cli-scripts-reference)
11. [Testing](#11-testing)

---

## 1. Overview

**Wiener Abfahrtsmonitor** is a PHP web application that shows real-time departure times for Vienna public transit (Wiener Linien). It calls the Wiener Linien OGD Realtime API, stores station data locally in MariaDB, and provides a single-page-style interface with favorites, geolocation, and light/dark theming.

**Live instance:** `https://www.jardyx.com/wl-monitor/`

**Tech stack:**

| Layer       | Technology                              |
|-------------|-----------------------------------------|
| Backend     | PHP 8.2, MySQLi (no framework)          |
| Database    | MariaDB (MySQL-compatible)              |
| Frontend    | Bootstrap 5.3, vanilla JS (ES modules)  |
| Icons       | Font Awesome 5                          |
| Email       | PHPMailer via SMTP                      |
| External API| Wiener Linien OGD Realtime              |
| Station data| Wiener Linien OGD CSV (data.wien.gv.at) |

There is no build system. Files are served directly; deployment is a file copy via rsync.

---

## 2. Directory Structure

```
wlmonitor/
├── config/                    Runtime credentials (NOT in repo)
│   ├── db.json                DB + SMTP credentials per environment
│   └── db.json.example        Template for new deployments
│
├── data/                      Runtime state (NOT deployed, created on server)
│   ├── ratelimit.json         Login failure counters per IP
│   └── ogd_update.lock        Lock file preventing concurrent OGD updates
│
├── include/                   PHP bootstrap and shared helpers
│   ├── initialize.php         ★ Must be first include in every entry point
│   ├── csrf.php               CSRF token generation and verification
│   ├── html_header.php        <head> + <body> open tag
│   ├── html_body.php          (legacy, currently unused)
│   └── html_footer.php        Footer HTML + version display
│
├── inc/                       Business logic modules
│   ├── auth.php               Login, logout, rate limiting, hash upgrade
│   ├── monitor.php            Wiener Linien API fetch + parse
│   ├── favorites.php          Favorites CRUD
│   ├── stations.php           Station list queries
│   ├── admin.php              User management (admin only)
│   ├── ogd.php                OGD station data import
│   └── mailer.php             PHPMailer SMTP wrapper
│
├── web/                       Web root — served by the HTTP server
│   ├── index.php              ★ Main page
│   ├── api.php                ★ Unified JSON API dispatcher
│   ├── authentication.php     Login form handler
│   ├── logout.php             Session teardown
│   ├── registration.php       New account registration
│   ├── activate.php           Email activation link handler
│   ├── forgotPassword.php     Password reset request
│   ├── executeReset.php       Password reset completion
│   ├── changePassword.php     Authenticated password change
│   ├── editFavorite.php       Favorite edit form
│   ├── admin.php              Admin user management UI
│   ├── monitor_json.php       Public JSON endpoint (Home Assistant etc.)
│   ├── css/
│   │   └── theme.css          CSS custom properties, dark mode, line badges
│   ├── js/
│   │   └── wl-monitor.js      ES module: all frontend behaviour
│   └── img/                   Static assets (icons, SVGs, manifests)
│
├── scripts/                   CLI utilities
│   ├── deploy.sh              Deploy to local or production via rsync
│   ├── update_ogd.php         CLI OGD data refresh
│   ├── migrate_diva.php       One-time DB migration (RBL → DIVA)
│   └── test_mail.php          SMTP connectivity test
│
├── tests/                     PHPUnit test suite
│   ├── bootstrap.php          Test setup (DB connection, session init)
│   ├── Integration/           Integration tests (real DB, rolled-back transactions)
│   └── Unit/                  Unit tests (CSRF, sanitisation, monitor parser)
│
├── docs/                      Documentation
├── vendor/                    Composer dependencies (PHPMailer, PHPUnit)
├── composer.json
└── phpunit.xml
```

---

## 3. Request Flow

### Page load

```
Browser → web/index.php
             ├── include/initialize.php   (DB connect, session start, constants)
             ├── include/csrf.php         (CSRF token generation)
             ├── include/html_header.php  (Bootstrap CSS, app CSS)
             └── renders HTML with embedded wlConfig JSON
                 └── <script type="module" src="js/wl-monitor.js">
```

### AJAX calls

```
wl-monitor.js  →  fetch('api.php?action=…')
                      ├── include/initialize.php
                      ├── inc/<module>.php
                      └── api_json($data)  →  JSON response
```

### Monitor refresh (every 20 seconds)

```
setInterval → loadMonitor()
                 → apiFetch('monitor', {diva})
                 → api.php?action=monitor&diva=60200103
                 → monitor_get($con, $diva, $maxDep)
                 → https://www.wienerlinien.at/ogd_realtime/monitor?diva=…
                 → parse JSON → return structured array
                 → renderMonitor(data) → DOM update
```

---

## 4. Module Reference

### `include/initialize.php`

Bootstrap file included first by every PHP entry point. Defines all constants, opens `$con` (global MySQLi connection), starts the session, and exposes shared helpers.

| Constant        | Value / Purpose                                    |
|-----------------|----------------------------------------------------|
| `SCRIPT_PATH`   | Absolute server path to the wlmonitor root         |
| `APIKEY`        | Wiener Linien OGD Realtime sender key              |
| `MAX_DEPARTURES`| Default departures shown per line (2)              |
| `APP_VERSION`   | Semver string, e.g. `3.0`                          |
| `APP_BUILD`     | Integer, incremented each development session      |
| `DATABASE_*`    | Host, user, password, name from config/db.json     |
| `SMTP_*`        | SMTP credentials from config/db.json               |

**Key functions:**

- `createDBConnection()` — opens MySQLi with strict error reporting.
- `manageUserSession()` — starts session with httponly/secure/SameSite=Strict cookies. Recovers an existing session from the `sId` cookie if the session data was lost (e.g. server restart).
- `appendLog($con, $context, $activity, $origin)` — writes to `wl_log`.
- `sanitizeDivaInput($raw)` — strips everything except `[0-9,]`. Used before any DIVA value touches a URL or SQL query.
- `addAlert($type, $message)` — queues a Bootstrap alert into `$_SESSION['alerts']`; consumed by index.php on the next page load.

---

### `include/csrf.php`

Provides `csrf_token()`, `csrf_verify()`, and `csrf_input()`.

Token: 256 bits of CSPRNG entropy (`random_bytes(32)`), hex-encoded, stored in `$_SESSION['csrf_token']`.

Verification uses `hash_equals()` (constant-time) to prevent timing attacks. Accepts the token from `$_POST['csrf_token']` or the `X-CSRF-TOKEN` HTTP header.

---

### `inc/auth.php`

Handles login, logout, rate limiting, and password hash upgrades.

**Rate limiting:** Failed attempts are written to `data/ratelimit.json` keyed by client IP. After 5 failures within 15 minutes the IP is blocked. Each read/write uses `flock(LOCK_EX)` to avoid race conditions.

**Login sequence:**
1. Check rate limit.
2. Look up user by username.
3. Verify `activation_code = 'activated'` and `disabled = 0`.
4. `password_verify()` against bcrypt hash.
5. If hash uses a lower cost than 13, silently rehash and store.
6. `session_regenerate_id(true)` — prevents session fixation.
7. Populate session, update `lastLogin`, clear failure counters.

---

### `inc/monitor.php`

`monitor_get($con, $divaRaw, $maxDepartures)` is the single entry point.

DIVA numbers are sanitised, then each becomes a `&diva=` parameter in the API URL. The WL Realtime API returns **one monitor entry per line**, not per station — entries for the same station are interleaved with entries for other stations. `monitor_get` initialises each station on first encounter and appends subsequent entries, ensuring all lines are correctly accumulated regardless of order.

Countdown `0` is rendered as `*` (vehicle at platform).

Throws `InvalidArgumentException` for empty DIVA input, `RuntimeException` for API/JSON failures.

---

### `inc/favorites.php`

CRUD for `wl_favorites`. Every mutating function embeds `idUser` in its `WHERE` clause — users cannot touch each other's rows regardless of the IDs they submit.

The optional `filter_json` field stores per-station line filters as a JSON object: `{"<diva>": [{"line": "59A", "platform": "1"}, …], …}`. `favorites_validate_filter()` sanitises this value — keys are stripped to digits, line names to `[A-Za-z0-9/\- ]`, platform values to `[A-Za-z0-9 ]`. `favorites_get()` decodes the JSON and returns it under the `filter` key.

---

### `inc/stations.php`

- `stations_by_distance()` uses `ST_Distance_Sphere()` (MariaDB 10.1+), rounds to 30 m, returns top 100.
- `stations_alpha()` returns all stations sorted by name; filtering happens in JavaScript.

---

### `inc/ogd.php`

Downloads three CSV files from `data.wien.gv.at`, truncates and reloads the corresponding tables, then recreates the `ogd_stations` and `ogd_diva` views inside a database transaction.

A `flock(LOCK_EX|LOCK_NB)` on `data/ogd_update.lock` prevents two simultaneous updates. The lock file handle is held open in `$GLOBALS['_ogd_lock_fp']` for the duration of the run.

**Views recreated:**

- `ogd_stations` — one row per physical station: DIVA, name, coordinates, lines served.
- `ogd_diva`     — one row per stop platform: RBL, station name, lines.

---

### `inc/admin.php`

User management functions. **Callers are responsible for authorisation** — all call sites in `api.php` guard with `api_require_admin()` before calling into this module.

---

### `web/api.php`

Central JSON dispatcher. After calling `monitor_get()`, the `monitor` action also injects empty placeholder entries for any requested DIVAs absent from the WL API response (the API silently omits stops with no upcoming departures). Placeholder entries have an empty `lines` array; the JS filter logic then shows "Keine aktuellen Abfahrten" for filtered favourite cards whose stop has no current service.

Every response is produced by `api_json()` which:
- Sets `Content-Type: application/json; charset=utf-8`
- Sets `X-Content-Type-Options: nosniff`
- Encodes with `JSON_HEX_TAG | JSON_HEX_AMP` (safe for HTML embedding)
- Calls `exit` so no further output can corrupt the response

Uncaught `Throwable`s are caught at the top level, logged, and returned as a generic `500` error without a stack trace.

---

### `web/monitor_json.php`

Thin public endpoint intended for external consumers (e.g. Home Assistant). Requires no authentication. The DIVA parameter is sanitised and falls back to the session value, then to Karlsplatz (`60200103`).

---

## 5. Database Schema

### Application tables

**`wl_accounts`**

| Column           | Type         | Notes                                    |
|------------------|--------------|------------------------------------------|
| id               | INT PK AI    |                                          |
| username         | VARCHAR(50)  | Unique                                   |
| email            | VARCHAR(100) |                                          |
| password         | VARCHAR(255) | bcrypt-13 hash                           |
| img              | VARCHAR(100) | Avatar filename                          |
| rights           | ENUM         | 'Admin' \| 'User'                        |
| disabled         | TINYINT      | 1 = blocked                              |
| activation_code  | VARCHAR(255) | Token or 'activated'                     |
| departures       | INT          | Per-user override for departure count    |
| debug            | TINYINT      | 1 = verbose logging                      |
| theme            | VARCHAR(10)  | 'light' \| 'dark' \| 'auto'              |
| lastLogin        | DATETIME     |                                          |
| invalidLogins    | INT          |                                          |

**`wl_favorites`**

| Column      | Type         | Notes                                                      |
|-------------|--------------|------------------------------------------------------------|
| id          | INT PK AI    |                                                            |
| idUser      | INT FK       | → wl_accounts.id                                           |
| title       | VARCHAR(100) | Display label                                              |
| diva        | VARCHAR(100) | Comma-separated DIVA numbers                               |
| bclass      | VARCHAR(50)  | Bootstrap button class                                     |
| sort        | INT          | Display order                                              |
| filter_json | TEXT NULL    | Per-station line filter: `{"diva": [{line, platform}], …}` |
| created     | TIMESTAMP    |                                                            |
| updated     | DATETIME     |                                                            |

**`wl_log`**

| Column   | Type         | Notes                                     |
|----------|--------------|-------------------------------------------|
| id       | INT PK AI    |                                           |
| idUser   | INT          |                                           |
| context  | VARCHAR(50)  | Category: 'auth', 'favAdd', 'admin', …   |
| activity | TEXT         |                                           |
| origin   | VARCHAR(10)  | 'web' \| 'cli'                            |
| ipAdress | INT UNSIGNED | Stored as INET_ATON(); read as INET_NTOA()|
| logTime  | TIMESTAMP    |                                           |

**`password_resets`**

| Column     | Type         | Notes                     |
|------------|--------------|---------------------------|
| id         | INT PK AI    |                           |
| user_id    | INT FK       | → wl_accounts.id          |
| token      | VARCHAR(64)  | 256-bit CSPRNG hex        |
| expires_at | DATETIME     | 1 hour from creation      |
| used       | TINYINT      | 1 after token consumed    |

### OGD tables (populated by scripts/update_ogd.php)

**`ogd_haltestellen`** — one row per physical station (source: WL open data CSV)

| Column          | Type    | Notes                                |
|-----------------|---------|--------------------------------------|
| HALTESTELLEN_ID | INT PK  |                                      |
| DIVA            | VARCHAR | 8-digit station-level identifier     |
| NAME            | VARCHAR |                                      |
| WGS84_LAT       | DOUBLE  |                                      |
| WGS84_LON       | DOUBLE  |                                      |

**`ogd_steige`** — one row per stop platform / direction

| Column              | Type    | Notes                                |
|---------------------|---------|--------------------------------------|
| STEIG_ID            | INT PK  |                                      |
| FK_HALTESTELLEN_ID  | INT FK  | → ogd_haltestellen                   |
| FK_LINIEN_ID        | INT FK  | → ogd_linien                         |
| RBL                 | VARCHAR | 4-digit stop-level identifier        |
| DIVA                | VARCHAR | Denormalised from ogd_haltestellen   |
| RICHTUNG            | VARCHAR | 'H' \| 'R'                           |

**`ogd_linien`** — one row per transit line

**Views:**

- `ogd_stations` — joins all three OGD tables; one row per station with DIVA, name, coordinates, and comma-separated line list. Used by station search.
- `ogd_diva` — one row per RBL (stop platform) with lines served. Both views use `SQL SECURITY INVOKER`.

### DIVA vs RBL

The Wiener Linien Realtime API accepts **DIVA numbers** — 8-digit station-level identifiers from `ogd_haltestellen.DIVA` (e.g. `60200103`).

**RBL numbers** are 4-digit stop-level identifiers (one per line direction at a station). They appear in `ogd_steige.RBL` and in older data exports. The application's `wl_favorites.diva` column stores station-level DIVAs, not RBLs.

---

## 6. Frontend Architecture

All frontend behaviour lives in `web/js/wl-monitor.js` (ES module, no bundler).

### Bootstrap

PHP passes runtime state to JS via a global object rendered in index.php:

```javascript
window.wlConfig = {
  userID:    <int>,
  loggedIn:  <bool>,
  theme:     <string>,
  alerts:    [['type', 'message'], …],
  loadFavId: <int>   // non-zero after editFavorite save → auto-loads that favourite
};
```

### Module structure

| Section             | Purpose                                                   |
|---------------------|-----------------------------------------------------------|
| State variables     | `stationCache`, `currentSort`, `stationOrigin`, `monitorTimer`, `currentMonitor`, `currentMonitorLines` |
| `apiFetch()`        | GET requests to api.php                                   |
| `apiPost()`         | POST requests to api.php (includes CSRF token from DOM)   |
| Monitor             | `loadMonitor()`, `renderMonitor()`, `createLineBadge()`   |
| Favorites           | `loadFavorites()`, `renderFavorites()`                    |
| Station dropdown    | `loadStationsAlpha()`, `loadStationsByDistance()`, `renderStationList()`, `openStationDropdown()`, `closeStationDropdown()` |
| Sort + search       | `wireStationSort()`, `wireStationDropdown()`               |
| Theme               | `applyTheme()`, `wireThemeToggle()`                       |
| Cookies             | `getCookie()`, `setCookie()` (theme preference only)      |

`currentMonitor` tracks the active monitor context (`{diva, favId, fav}`). `currentMonitorLines` is rebuilt on every `renderMonitor` call and holds all `{diva, line, platform, direction, towards}` entries visible in the last render — used to populate the add-favourite line filter checkboxes.

`DOMContentLoaded` is `async`: it `await`s `loadFavorites()`, then checks `wlConfig.loadFavId` to decide whether to auto-load a specific favourite (set by `editFavorite.php` via session after a save) or fall back to the default DIVA.

### Monitor rendering

Departure data is grouped by line name, with direction `H` (outgoing) first and `R` (incoming) second. When both directions exist the line badge cell spans two rows. Transport type is mapped to a CSS class for badge styling:

When a favourite has a `filter_json` set, `renderMonitor` applies per-station filtering: only lines whose `{line, platform}` pair appears in `filter_json[diva]` are shown. Filtered stations with no currently-matching departures (e.g. the stop is in the favourite's filter but the WL API returned no entry for it, or all filtered lines are temporarily out of service) still render a card with a "Keine aktuellen Abfahrten" placeholder row rather than being silently hidden.

| API type     | Badge style                        |
|--------------|------------------------------------|
| `ptMetro`    | Coloured square (U1–U6 colours)    |
| `ptTram`     | Black/white circle                 |
| `ptTramWLB`  | Blue square with SVG logo          |
| `ptBusCity`  | Navy square                        |
| `ptBusNight` | Navy square, orange text           |
| `ptBusRegion`| Black circle, yellow text          |
| `ptTrain`    | Red square                         |
| `ptTrainS`   | Blue square                        |

### Theming

CSS custom properties are defined in `web/css/theme.css` on `:root` (light) and overridden on `[data-theme="dark"]` and `@media (prefers-color-scheme: dark) :root:not([data-theme="light"])`.

Both blocks also override Bootstrap 5 internal variables (`--bs-card-bg`, `--bs-body-bg`, etc.) so Bootstrap components inherit the correct colours without needing explicit overrides for every component.

The three-way toggle (Auto / Light / Dark) writes to a `theme` cookie (365-day expiry) and, for logged-in users, persists the choice to the database via the `theme_save` API action.

---

## 7. Security Architecture

### Authentication

- Passwords: bcrypt with cost factor 13. On login, `password_needs_rehash()` transparently upgrades lower-cost hashes.
- Session: `session_regenerate_id(true)` on login prevents session fixation. Session cookies are `httponly`, `secure`, `SameSite=Strict`.
- Session recovery: the `sId` cookie allows restoring a session across browser restarts without requiring re-login. The cookie value is validated against the regex `/^[a-zA-Z0-9\-]{22,128}$/` before use.

### CSRF protection

Every state-changing API action calls `api_require_csrf()`. The CSRF token is:
- Generated once per session as `bin2hex(random_bytes(32))` (256-bit).
- Embedded in all forms via `csrf_input()`.
- Also accessible from JavaScript as a hidden DOM input (rendered unconditionally in index.php so logged-in users have it available for `fetch()` calls).
- Verified using `hash_equals()` (constant-time comparison).

### Rate limiting

Login attempts are tracked in `data/ratelimit.json` per client IP. After 5 failures within 15 minutes, further attempts return a generic error. The file is protected by `flock(LOCK_EX)`.

Note: `X-Forwarded-For` is trusted for the client IP, which can be spoofed. This limits the effectiveness of rate limiting behind a proxy — acceptable for a low-risk internal deployment.

### Input validation

| Input         | Sanitisation                                         |
|---------------|------------------------------------------------------|
| DIVA/RBL      | `preg_replace('/[^0-9,]/', '', …)`                   |
| Title         | `strip_tags()` + `mb_substr(…, 0, 100)`              |
| bclass        | `preg_replace('/[^a-z0-9\-]/', '', …)`               |
| Email         | `filter_var(FILTER_VALIDATE_EMAIL)`                  |
| Coordinates   | `is_finite()` + range check (±90 lat, ±180 lon)      |
| Theme         | Whitelist `['light', 'dark', 'auto']`                |
| LIKE filter   | Manual escape of `\`, `%`, `_`                       |
| Rights        | Whitelist `['Admin', 'User']`                        |

### SQL injection prevention

All user-supplied values are bound via MySQLi prepared statements. MySQLi strict reporting mode (`MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`) ensures any query error throws an exception immediately.

### Authorization

- `api_require_login()` — 401 if not authenticated.
- `api_require_admin()` — 403 if role ≠ 'Admin'.
- Favorites operations always include `idUser` in `WHERE` (IDOR prevention).
- Admin functions in `inc/admin.php` do not check authorization themselves — the boundary is at the api.php call site.

### Output encoding

- All PHP-generated HTML uses `htmlspecialchars(ENT_QUOTES, 'UTF-8')`.
- JSON responses use `JSON_HEX_TAG | JSON_HEX_AMP`.
- `X-Content-Type-Options: nosniff` is sent on all API responses.

### Password reset

Token: `bin2hex(random_bytes(32))` (256-bit, 64-hex chars). Valid for 1 hour. One-time use (the `used` flag is set after redemption). Previous unused tokens are deleted before a new one is issued.

---

## 8. Configuration Reference

`config/db.json` (not committed to the repository):

```json
{
  "local": {
    "host": "localhost",
    "user": "wlmonitor",
    "pass": "…",
    "name": "wlmonitor"
  },
  "production": {
    "host": "localhost",
    "user": "wlmonitor_prod",
    "pass": "…",
    "name": "wlmonitor_prod"
  },
  "smtp_local": {
    "host": "smtp.example.com",
    "port": 587,
    "user": "user@example.com",
    "pass": "…",
    "from": "user@example.com",
    "from_name": "WL Monitor"
  },
  "smtp_production": {
    "host": "smtp.example.com",
    "port": 587,
    "user": "user@example.com",
    "pass": "…",
    "from": "noreply@example.com",
    "from_name": "WL Monitor"
  }
}
```

The active section is selected by the `APP_ENV` environment variable. Set it in `web/.htaccess`:

```apacheconf
SetEnv APP_ENV production
```

For local development `APP_ENV` defaults to `local` (see `initialize.php`).

---

## 9. Deploying to a Clean Production Environment

### Prerequisites

- PHP 8.2 with extensions: `mysqli`, `mbstring`, `openssl`, `json`, `fileinfo`
- MariaDB 10.5+ (or MySQL 8+) — requires `ST_Distance_Sphere()`
- Apache with `AllowOverride FileInfo` (for `SetEnv` in `.htaccess`)
- SSH access to the server
- Composer installed locally

### Step 1 — Install Composer dependencies

```bash
cd /path/to/wlmonitor
composer install --no-dev --optimize-autoloader
```

### Step 2 — Create the database

Connect to MariaDB and run:

```sql
CREATE DATABASE wlmonitor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wlmonitor'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER
      ON wlmonitor.* TO 'wlmonitor'@'localhost';
FLUSH PRIVILEGES;
```

### Step 3 — Create the schema

```sql
USE wlmonitor;

CREATE TABLE wl_accounts (
  id             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(50)  NOT NULL UNIQUE,
  email          VARCHAR(100) NOT NULL,
  password       VARCHAR(255) NOT NULL,
  img            VARCHAR(100) NOT NULL DEFAULT 'user-md-grey.svg',
  rights         VARCHAR(20)  NOT NULL DEFAULT 'User',
  disabled       TINYINT      NOT NULL DEFAULT 1,
  activation_code VARCHAR(255) NOT NULL DEFAULT '',
  departures     INT          NOT NULL DEFAULT 2,
  debug          TINYINT      NOT NULL DEFAULT 0,
  theme          VARCHAR(10)  NOT NULL DEFAULT 'auto',
  lastLogin      DATETIME     DEFAULT NULL,
  invalidLogins  INT          NOT NULL DEFAULT 0
);

CREATE TABLE wl_favorites (
  id       INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  idUser   INT          NOT NULL,
  title    VARCHAR(100) NOT NULL,
  diva     VARCHAR(100) NOT NULL,
  bclass   VARCHAR(50)  NOT NULL DEFAULT 'btn-outline-success',
  sort     INT          NOT NULL DEFAULT 0,
  created  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated  DATETIME     DEFAULT NULL,
  INDEX (idUser)
);

CREATE TABLE wl_log (
  id       INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
  idUser   INT           NOT NULL DEFAULT 1,
  context  VARCHAR(50)   NOT NULL DEFAULT '*',
  activity TEXT,
  origin   VARCHAR(10)   NOT NULL DEFAULT 'web',
  ipAdress INT UNSIGNED  DEFAULT NULL,
  logTime  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (idUser),
  INDEX (logTime)
);

CREATE TABLE password_resets (
  id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NOT NULL,
  token      VARCHAR(64)  NOT NULL UNIQUE,
  expires_at DATETIME     NOT NULL,
  used       TINYINT      NOT NULL DEFAULT 0,
  INDEX (token),
  INDEX (user_id)
);

-- OGD tables (populated by update_ogd.php)
CREATE TABLE ogd_haltestellen (
  HALTESTELLEN_ID INT          NOT NULL PRIMARY KEY,
  TYP             VARCHAR(10),
  DIVA            VARCHAR(20),
  NAME            VARCHAR(100),
  GEMEINDE        VARCHAR(100),
  GEMEINDE_ID     INT,
  WGS84_LAT       DOUBLE,
  WGS84_LON       DOUBLE,
  STAND           DATE         DEFAULT NULL,
  INDEX (DIVA)
);

CREATE TABLE ogd_steige (
  STEIG_ID           INT         NOT NULL PRIMARY KEY,
  FK_LINIEN_ID       INT,
  FK_HALTESTELLEN_ID INT,
  RICHTUNG           VARCHAR(5),
  REIHENFOLGE        INT,
  RBL                VARCHAR(10),
  BEREICH            VARCHAR(20),
  STEIG              VARCHAR(20),
  STEIG_WGS84_LAT    DOUBLE,
  STEIG_WGS84_LON    DOUBLE,
  STAND              DATE        DEFAULT NULL,
  DIVA               VARCHAR(20) DEFAULT NULL,
  INDEX (FK_HALTESTELLEN_ID),
  INDEX (FK_LINIEN_ID),
  INDEX (RBL)
);

CREATE TABLE ogd_linien (
  LINIEN_ID     INT         NOT NULL PRIMARY KEY,
  BEZEICHNUNG   VARCHAR(50),
  REIHENFOLGE   INT,
  ECHTZEIT      INT,
  VERKEHRSMITTEL VARCHAR(20),
  STAND         DATE        DEFAULT NULL
);
```

### Step 4 — Create the first admin account

```sql
USE wlmonitor;
INSERT INTO wl_accounts (username, email, password, rights, disabled, activation_code)
VALUES (
  'admin',
  'admin@example.com',
  '$2y$13$<hash>',   -- generate with: php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT, ['cost'=>13]);"
  'Admin',
  0,
  'activated'
);
```

Generate the hash locally:
```bash
php -r "echo password_hash('your-secure-password', PASSWORD_BCRYPT, ['cost' => 13]) . PHP_EOL;"
```

### Step 5 — Create the configuration file

On the server, create `config/db.json` based on `config/db.json.example`. Fill in the production DB credentials and SMTP settings.

### Step 6 — Create runtime directories

```bash
mkdir -p /path/to/wlmonitor/data
touch /path/to/wlmonitor/data/ratelimit.json
echo '{}' > /path/to/wlmonitor/data/ratelimit.json
chmod 700 /path/to/wlmonitor/data
chmod 600 /path/to/wlmonitor/data/ratelimit.json
```

Ensure the `data/` directory is **not** web-accessible (add a `.htaccess` with `Require all denied` or place it outside the web root).

### Step 7 — Update constants in initialize.php

Edit `include/initialize.php` and set `SCRIPT_PATH` to the absolute server path:

```php
define("SCRIPT_PATH", '/home/.sites/765/site679/web/jardyx.com/wlmonitor/');
```

### Step 8 — Deploy files

Fill in `PROD_SSH_USER` and `PROD_SSH_HOST` in `scripts/deploy.sh`, then run:

```bash
bash scripts/deploy.sh
# Choose 'p' for production
# Confirm with 'yes'
```

The script uses rsync and excludes `config/`, `data/`, `tests/`, `docs/`, `deprecated/`, markdown files, and top-level `img/`. It then writes `SetEnv APP_ENV production` to `web/.htaccess` via SSH.

### Step 9 — Load station data

SSH into the server and run the OGD import:

```bash
php /path/to/wlmonitor/scripts/update_ogd.php
```

This downloads the Wiener Linien CSV files (~4 000 stations, ~25 000 stops) and populates the OGD tables and views. Expect it to take 30–60 seconds. Afterwards, the station list and proximity search will work.

### Step 10 — Verify

Open `https://your-domain/wlmonitor/` in a browser. You should see:
- The departure monitor loading Karlsplatz data.
- The station dropdown populating with ~4 000 entries.
- Login working with the admin account created in Step 4.

### Ongoing maintenance

| Task                          | How                                         |
|-------------------------------|---------------------------------------------|
| Refresh station data          | Admin panel → "OGD-Daten aktualisieren"     |
| Refresh station data (CLI)    | `php scripts/update_ogd.php`                |
| Deploy code changes           | `bash scripts/deploy.sh` → choose `p`       |
| Test SMTP                     | `php scripts/test_mail.php user@example.com`|

---

## 10. CLI Scripts Reference

### `scripts/deploy.sh`

Deploys the application via rsync. Prompts for `l` (local test) or `p` (production). 

- **Local:** syncs to `~/Web/wlmonitor`, creates `data/` and `config/db.json` (from example) if absent, removes any stale production `.htaccess`.
- **Production:** rsync via SSH, writes `SetEnv APP_ENV production` to `web/.htaccess`, creates `data/` on remote.

Excluded from both: `.git/`, `.claude/`, `tests/`, `docs/`, `deprecated/`, `config/`, `data/`, `*.md`, `CLAUDE.md`, `phpunit.xml`, `composer.{json,lock}`, top-level `img/` (anchored with leading `/` to preserve `web/img/`).

### `scripts/update_ogd.php`

CLI-only. Downloads three CSVs from data.wien.gv.at, reloads `ogd_haltestellen`, `ogd_steige`, `ogd_linien`, and recreates the `ogd_stations` and `ogd_diva` views in a single database transaction. Safe to run repeatedly. Fails with exit code 1 on error.

### `scripts/migrate_diva.php`

One-time migration script. Idempotent (checks column existence before ALTER). Should be run after deploying to a server that still has the old schema where `wl_favorites.rbls` stored RBL numbers instead of DIVA numbers. Steps:
1. Renames `wl_favorites.rbls` → `diva` if the old column exists.
2. Renames `ogd_steige.DIVA` → `RBL` if needed.
3. Adds `ogd_steige.DIVA` and populates it from `ogd_haltestellen`.
4. Converts existing favorites from RBL to station-level DIVA numbers.
5. Recreates `ogd_stations` and `ogd_diva` views.

Not needed for fresh deployments — the schema in Step 3 above already has the correct column names.

### `scripts/test_mail.php`

CLI-only. Sends a test email via the configured SMTP server:

```bash
php scripts/test_mail.php recipient@example.com
```

---

## 11. Testing

### Setup

```bash
composer install
cp config/db.json.example config/db.json
# Fill in test DB credentials
```

The test DB must have the full schema (same as production). Test data is isolated per test via transaction rollback — no separate test database is required, though one is recommended for safety.

### Running tests

```bash
# All tests
vendor/bin/phpunit

# Single test class
vendor/bin/phpunit tests/Unit/MonitorParserTest.php

# Single test method
vendor/bin/phpunit --filter test_parses_single_station_single_line
```

### Test structure

**Unit tests** (`tests/Unit/`) — no network, no live DB for logic tests:

- `CsrfTest.php` — token generation, verification, HTML rendering.
- `SanitizeTest.php` — `sanitizeDivaInput()` edge cases.
- `MonitorParserTest.php` — `monitor_get()` parsing logic. Uses a custom `MockHttpWrapper` stream wrapper to intercept `file_get_contents()` calls and inject fixture JSON without making real API requests. A real DB connection is required for `sanitizeDivaInput()` (called via `monitor_get`).

**Integration tests** (`tests/Integration/`) — real DB, transaction-isolated:

- `IntegrationTestCase.php` — base class. Wraps each test in `begin_transaction()` / `rollback()`. Provides `createUser()` factory.

Live-API monitor tests are skipped automatically when the Wiener Linien endpoint is unreachable.
