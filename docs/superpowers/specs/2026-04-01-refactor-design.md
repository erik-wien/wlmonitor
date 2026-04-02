# WL Monitor — Full Refactor Design

**Date:** 2026-04-01  
**Approach:** B — Structured rebuild, same stack  
**Status:** Approved

---

## 1. PHP Module Structure

### Directory layout

```
wlmonitor/
├── inc/
│   ├── auth.php        — login, logout, register, password reset, rate limiting
│   ├── monitor.php     — Wiener Linien API fetch, returns JSON
│   ├── favorites.php   — CRUD for wl_favorites, sort-save
│   ├── stations.php    — station list by distance or alpha
│   ├── admin.php       — user management (replaces ajaxCRUD)
│   └── html.php        — page shell partials (header, nav, footer)
├── include/
│   ├── initialize.php  — DB connection, session bootstrap, utility functions
│   └── csrf.php        — token generation and validation helpers
└── web/
    ├── index.php       — single HTML entry point (~80 lines)
    ├── api.php         — single JSON API dispatcher
    ├── admin.php       — admin page (calls inc/admin.php)
    ├── deprecated/     — retired files kept for reference
    └── js/, css/, img/ — static assets
```

### `web/api.php` — single dispatcher

Replaces all of these current entry-point files (moved to `web/deprecated/`):

| Old file | New action |
|---|---|
| `monitor_json.php` | `?action=monitor` |
| `getFavorites.php` | `?action=favorites` |
| `saveFavorites.php` | `?action=favorites_sort` |
| `addFavorite.php` | `?action=favorites_add` |
| `deleteFavorite.php` | `?action=favorites_delete` |
| `checkFavorite.php` | `?action=favorites_check` |
| `editFavorite.php` | `?action=favorites_edit` |
| `getStations.php` | `?action=stations` |
| `savePosition.php` | `?action=position_save` |
| `getLog.php` | `?action=log` |
| `steigInfo.php` | `?action=steig_info` |

All responses are JSON. The dispatcher checks the `action` parameter, requires the relevant `inc/` module, calls the handler, and returns the result.

### Files moved to `web/deprecated/`

- `monitor.php` — HTML output path (replaced by `index.php` + fetch)
- `monitor_json - Kopieren.php` — backup file in production
- `debug.php` — publicly exposed session/DB debug output
- `watch.php` — if no active callers found
- `ajaxFav.php` — mislabelled user editor (replaced by `web/admin.php`)
- All collapsed entry-point files listed above

`include/ajaxCRUD/` directory moved to `web/deprecated/ajaxCRUD/`.

---

## 2. Security Layer

### CSRF protection

`include/csrf.php` provides two functions:

- `csrf_token()` — returns `$_SESSION['csrf_token']`, generating it with `bin2hex(random_bytes(32))` on first call
- `csrf_verify()` — calls `hash_equals($_SESSION['csrf_token'], $submitted)`, returns bool

Every HTML form includes `<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">`.  
Every POST handler in `api.php` calls `csrf_verify()` before processing.  
AJAX calls send the token as `X-CSRF-Token` request header; `api.php` checks `$_SERVER['HTTP_X_CSRF_TOKEN']` for non-form requests.

### Session cookie hardening

Set in `initialize.php` before `session_start()`:

```php
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
```

The custom `sId` recovery cookie is kept for cross-restart session persistence, sent with `HttpOnly; Secure; SameSite=Strict` flags.

### Login rate limiting

`inc/auth.php` uses an atomic file lock on `data/ratelimit.json` (stored outside web root, above `web/`). Policy: 5 failed attempts from an IP within 15 minutes triggers a 429 response and records the lockout timestamp. No external dependencies.

### Password hashing

`password_hash($pass, PASSWORD_BCRYPT, ['cost' => 13])`.  
Existing hashes continue to work via `password_verify()`.  
On successful login, `password_needs_rehash()` upgrades the stored hash transparently if its cost is lower than 13.

### Output escaping

All PHP output uses `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`.  
JSON responses from `api.php` use `json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR)`.

---

## 3. Bug Fixes

### Search function

Currently broken: the `#stationSortSearch` radio has no click handler, and the `#s` text input is permanently `d-none`.

Fix: add a `change` handler on the station sort radios. When search is selected, show the `#s` input and wire its `input` event to filter the already-loaded station array client-side (no extra API call). Filtering is case-insensitive substring match on station name.

### RBL/DIVA unification

`monitor.php` (accepts `rbl`, returns HTML) and `monitor_json.php` (accepts `diva`, returns JSON) are two implementations of the same logic.

Consolidated to: `GET /api.php?action=monitor&diva=60200103,60200104`.  
JS always uses `diva` values.  
`ogd_stations` table column renamed from `rbls` to `diva` for clarity.  
`sanitizeRblInput()` renamed to `sanitizeDivaInput()` to match.

### Favorites sort-save

Current approach hand-builds a JSON string in JS and POSTs it as raw text — fragile and bypasses proper encoding.

Fix: JS sends `JSON.stringify([{id: 12, sort: 1}, ...])` as a proper JSON body. PHP decodes with `json_decode($body, true)` and validates that each entry has integer `id` and `sort` fields before executing any UPDATE.

---

## 4. Frontend Modernisation

### Bootstrap 5

Replaces Bootstrap 4.3. Key migration changes:
- `data-*` attributes → `data-bs-*`
- `btn-block` → `d-grid` wrapper
- Separate Popper.js include removed (bundled in BS5)
- Grid system is largely compatible

### Dark mode via CSS custom properties

Replaces the current approach of swapping entire CSS files.

```css
:root {
  --bg: #fff;
  --text: #212529;
  --surface: #f8f9fa;
  /* ... */
}
[data-theme="dark"] {
  --bg: #1a1a2e;
  --text: #e0e0e0;
  --surface: #16213e;
  /* ... */
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    /* same dark values */
  }
}
```

Theme toggle: `document.documentElement.dataset.theme = 'dark'|'light'|''` + cookie for persistence. No CSS file swap required.

### Vanilla JS replacing jQuery

jQuery is removed. All AJAX calls become `fetch()` with `async/await`. DOM construction uses `document.createElement` + `element.textContent` (XSS-safe by default — no innerHTML string concatenation).

Affected areas: station list rendering, favorites buttons, monitor panel updates, log panel, all form submissions.

`wl-monitor.js` is rewritten as a single ES module with named functions. The pull-to-refresh library (`xpull`) is kept if it has no jQuery dependency; otherwise replaced with a small native touch handler.

Font Awesome stays.

### `index.php` shell

Reduced to ~80 lines: HTML head, Bootstrap 5 links, nav skeleton, three `<div>` placeholders (`#monitor`, `#buttons`, `#station-list`), and a `<script type="module">` that boots the app on DOMContentLoaded. All dynamic content fetched and rendered by JS.

---

## 5. Admin Panel

`ajaxCRUD` removed. The `include/ajaxCRUD/` directory moved to `web/deprecated/ajaxCRUD/` for reference.

Replacement: `inc/admin.php` (~80 lines) handles four operations on `wl_accounts`:

| Operation | Endpoint |
|---|---|
| List users (paginated, filterable) | `GET /api.php?action=admin_users` |
| Edit user fields | `POST /api.php?action=admin_user_edit` |
| Reset user password | `POST /api.php?action=admin_user_reset` |
| Delete user | `POST /api.php?action=admin_user_delete` |

`web/admin.php` renders a plain Bootstrap 5 table. Edit actions use BS5 inline modals. All writes require CSRF token and `$_SESSION['rights'] === 'Admin'` check at handler entry.

Editable fields: `email`, `rights`, `disabled`, `departures`, `debug`.

---

## Out of Scope

- No build pipeline (no npm, no webpack, no Sass compilation)
- No framework adoption (no Symfony, no Laravel)
- No API versioning
- No automated tests (not in existing codebase)
- No deployment automation
