---
id: TASK-4
title: Persist last-viewed station / favorite across logout and app close
status: Done
assignee: []
created_date: '2026-04-18 11:54'
updated_date: '2026-04-19 04:27'
labels:
  - feature
  - ux
  - preferences
  - db-migration
dependencies: []
references:
  - 'web/js/wl-monitor.js:11'
  - 'web/js/wl-monitor.js:25'
  - 'web/js/wl-monitor.js:65'
  - 'web/index.php:11'
  - 'web/api.php:115'
  - 'web/editFavorite.php:60'
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Goal

When a logged-in user closes the app or logs out, their **last-viewed monitor** — either a favorite (with its filter) or an ad-hoc station — should be restored the next time they open wlmonitor.

## Current behavior

- `api.php:117` (`case 'monitor'`) stashes the current DIVA in `$_SESSION['diva']` on every monitor fetch, so within a single PHP session the last DIVA is remembered on page reload.
- On logout the session is destroyed, so `$_SESSION['diva']` is gone. Next login → falls back to hardcoded Karlsplatz `60200103`.
- `$_SESSION['loadFavId']` exists but is transient: set only by `editFavorite.php` after a save (`web/editFavorite.php:60`) and consumed once by `index.php:11-12` (`unset`). It is **not** updated when the user clicks a favorite or searches a station.
- So today: no cross-session persistence of the viewed monitor, and no persistence of *which favorite* was last active (only a raw DIVA via the session).

## What "last state" means

`currentMonitor` in `web/js/wl-monitor.js:11` already distinguishes two cases:

```js
{ diva: <string|null>, favId: <int|null>, fav: <obj|null> }
```

- **Favorite active:** `favId` is set — the favorite's filter is applied to the line/platform display.
- **Ad-hoc station active:** `favId` is null — just the DIVA, no filter.

Both cases must be restorable. Restoring a DIVA alone (without the `favId`) would lose the user's filter when they had a favorite selected; restoring a `favId` alone would fail gracefully if the favorite has since been deleted.

## Proposed design

### 1. Persist per-user state in `wl_preferences`

Extend the existing `wl_preferences` table (wlmonitor DB, 1:1 with `auth_accounts.id`) with two nullable columns:

```sql
ALTER TABLE wl_preferences
  ADD COLUMN last_fav_id INT NULL,
  ADD COLUMN last_diva   VARCHAR(16) NULL;
```

- `last_fav_id` — set when the user's last monitor was a favorite. References `wl_favorites.id`; on favorite deletion, set to NULL (either via FK `ON DELETE SET NULL` or via the registered delete-cleanup hook that already purges favorites — whichever is simpler to wire).
- `last_diva` — set for ad-hoc stations (favId null). VARCHAR sized to accommodate the 8-digit DIVA format.
- Mutually exclusive: when `last_fav_id` is set, `last_diva` may be NULL or store the favorite's DIVA as a cache; whoever implements this picks one convention and documents it.

### 2. Server-side save endpoint

Add `api.php?action=state_save` (POST + CSRF, login required) that upserts `last_fav_id` + `last_diva`:

- Body: `favId` (nullable int) and/or `diva` (nullable string).
- Validate: if `favId` given, confirm it exists in `wl_favorites` for the calling user; if `diva` given, pass through `sanitizeDivaInput()`.
- Upsert the row (existing `INSERT ... ON DUPLICATE KEY UPDATE` pattern used for `departures`).

### 3. Server-side load on `index.php`

`web/index.php` already seeds `wlConfig.loadFavId` from `$_SESSION['loadFavId']` (one-shot, post-save). Extend to:

- If `$_SESSION['loadFavId']` is set (existing edit-favorite flow) → use it (unchanged, highest priority).
- Else SELECT `last_fav_id, last_diva FROM wl_preferences WHERE user_id = ?`:
  - If `last_fav_id` resolves to an existing favorite → expose as `wlConfig.loadFavId` so the existing JS path in `wl-monitor.js:28-31` picks it up.
  - Else if `last_diva` is set → expose as `wlConfig.initialDiva` (new key) and make JS load that DIVA as an ad-hoc monitor on startup.
- Fall back to Karlsplatz default on empty state (unchanged).

### 4. Client-side save on monitor change

In `web/js/wl-monitor.js`, extend `loadMonitor(diva, fav)` (line 65) to fire a debounced `apiPost('state_save', …)` after a successful fetch:

- If `fav` set → `{ favId: fav.id }`
- Else if `diva` set → `{ diva }`
- Debounce ~1s so the 20-second auto-refresh (`startMonitorTimer`, line 434) doesn't spam writes. Cheaper: only save on *user-initiated* loads (favorite click, station pick, add-favorite), not on auto-refresh. Recommend the user-initiated-only path.
- Silent failure: if the save POST fails, do not alert the user — log to console only.

### 5. Anonymous users (nice-to-have)

Anonymous users already get within-session persistence via `$_SESSION['diva']`. For across-session persistence without a user account, use `localStorage.setItem('wl_last_diva', ...)` on monitor change and read it in the `DOMContentLoaded` handler when `wlConfig.loggedIn === false`. No server-side work needed, no cross-device sync. Ask user whether this is in scope.

## Edge cases

- **Favorite deleted between sessions:** `last_fav_id` resolves to no row → fall back to `last_diva` → fall back to Karlsplatz.
- **Favorite renamed / filter changed:** restored correctly because we store the ID, and the favorites endpoint returns the current filter.
- **DIVA no longer in ogd_stations:** let `monitor_get()` return empty and surface the existing "keine Abfahrten" UI. Do not silently fall back — the user should see what changed.
- **Session already has `loadFavId` set by `editFavorite.php`:** that path wins (existing behavior, one-shot). The save-on-load logic also writes the edited favorite's ID to `last_fav_id`, keeping both mechanisms in sync.
- **Concurrent sessions on multiple devices:** last write wins. Acceptable — users expect this level of cross-device sync.

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 #1 wl_preferences gains last_fav_id (INT NULL) and last_diva (VARCHAR(16) NULL) via a new sequential migration
- [x] #2 #2 api.php has a state_save action (POST + CSRF, login-required) that upserts the two fields with fav-ownership check and DIVA sanitization
- [x] #3 #3 index.php exposes the user's persisted state as wlConfig.loadFavId (favorite) or wlConfig.initialDiva (ad-hoc)
- [x] #4 #4 wl-monitor.js restore order on init: wlConfig.loadFavId → wlConfig.initialDiva → hardcoded Karlsplatz default
- [x] #5 #5 loadMonitor() saves state only on user-initiated loads (favorite click, station pick, add/edit favorite); not on the 20s auto-refresh
- [x] #6 #6 Deleting a favorite nulls any last_fav_id references (FK SET NULL or cleanup hook)
- [x] #7 #7 User flow: pick favorite/station → logout → log back in → same favorite/station restored with its filter
- [x] #8 #8 If last_fav_id no longer exists, fall back to last_diva; if that is also absent, fall back to the Karlsplatz default
- [x] #9 #9 Anonymous-user behavior follows the decision on open question 2 (localStorage in-scope or deferred)
- [x] #10 #10 Docs updated (architecture.md, technical-specification.md, CLAUDE.md) with the new state flow and columns
- [x] #11 #11 Grants verified unchanged in ~/Git/mcp/scripts/grant-db-users.sql

## Open questions

1. **Anonymous persistence via localStorage** — in scope or defer?
2. **Cheap save cadence** — confirm save only on user-initiated loads (recommended), not on every auto-refresh. Alternative: debounce every save 2-5s and save on refresh too.
3. **`last_fav_id` ON DELETE SET NULL** — cross-DB FK is not allowed per `auth-rules.md` §5, but **this FK is same-DB** (both in wlmonitor). Confirm OK, or prefer a cleanup hook approach in `favorites_delete` to match the cleanup-hook pattern.

## Files likely touched

- `web/js/wl-monitor.js` — `loadMonitor()`, init handler; add `saveState()` helper.
- `web/api.php` — new `state_save` dispatch case; extend `monitor` case to also update `last_diva` (optional — client can handle it).
- `web/index.php` — seed `wlConfig.initialDiva` and `wlConfig.loadFavId` from `wl_preferences`.
- New DB migration for the two columns.
- Docs: `docs/technical-specification.md`, `docs/architecture.md`, `CLAUDE.md`.
- Tests: `tests/Integration/*` — seed fixtures and assertions.
<!-- SECTION:DESCRIPTION:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Implementation Plan — TASK-4: Persist last-viewed station/favorite

**Decisions locked in:**
- Q1: Anonymous localStorage in scope
- Q2: User-initiated loads only (no DB write on 20s auto-refresh)
- Q3: Same-DB FK → `ON DELETE SET NULL` (wlmonitor DB, auth-rules §5(a) applies)

---

### File map

| File | Action |
|---|---|
| `migrations/004_wl_preferences_last_state.sql` | Create — add columns + FK |
| `inc/state.php` | Create — `state_load()` + `state_upsert()` |
| `web/api.php` | Modify — add `state_save` case + `require_once inc/state.php` |
| `web/index.php` | Modify — seed `wlConfig.initialDiva` from DB |
| `web/js/wl-monitor.js` | Modify — `saveState()` helper + 3 call sites + anonymous restore |
| `tests/Integration/StatePersistenceTest.php` | Create — 5 tests |
| `docs/technical-specification.md` | Modify — §9.4 schema + action inventory |
| `docs/architecture.md` | Modify — mention cross-session persistence |
| `CLAUDE.md` | Modify — update wl_preferences description |
| `inc/initialize.php` | Modify — bump `APP_BUILD` |

---

### Task 1: DB migration

**Files:**
- Create: `migrations/004_wl_preferences_last_state.sql`

- [ ] Write migration file:

```sql
-- Add cross-session monitor state to wl_preferences.
-- wl_favorites and wl_preferences are in the same DB (wlmonitor), so a real FK is valid.
-- ON DELETE SET NULL ensures last_fav_id is cleared automatically when a favourite is deleted.
USE wlmonitor;

ALTER TABLE wl_preferences
  ADD COLUMN IF NOT EXISTS last_fav_id INT          NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS last_diva   VARCHAR(16)  NULL DEFAULT NULL;

ALTER TABLE wl_preferences
  ADD CONSTRAINT fk_wlprefs_last_fav
  FOREIGN KEY (last_fav_id) REFERENCES wl_favorites(id) ON DELETE SET NULL;
```

- [ ] Run migration on local dev DB:

```bash
mysql -u wlmonitor -p wlmonitor < migrations/004_wl_preferences_last_state.sql
```

Expected: no errors, `SHOW COLUMNS FROM wl_preferences;` shows `last_fav_id` and `last_diva`.

- [ ] Commit:

```bash
git add migrations/004_wl_preferences_last_state.sql
git commit -m "db: add last_fav_id + last_diva to wl_preferences (FK ON DELETE SET NULL)"
```

---

### Task 2: `inc/state.php` — DB functions

**Files:**
- Create: `inc/state.php`
- Create: `tests/Integration/StatePersistenceTest.php`

- [ ] Write failing tests first (`tests/Integration/StatePersistenceTest.php`):

```php
<?php
namespace WLMonitor\Tests\Integration;

class StatePersistenceTest extends IntegrationTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../inc/state.php';
        require_once __DIR__ . '/../../inc/favorites.php';
        $this->userId      = $this->createUser();
        $_SESSION['id']    = $this->userId;
    }

    public function test_load_returns_nulls_for_fresh_user(): void
    {
        $state = state_load($this->con, $this->userId);
        $this->assertNull($state['last_fav_id']);
        $this->assertNull($state['last_diva']);
    }

    public function test_upsert_saves_diva(): void
    {
        state_upsert($this->con, $this->userId, null, '60200103');
        $state = state_load($this->con, $this->userId);
        $this->assertSame('60200103', $state['last_diva']);
        $this->assertNull($state['last_fav_id']);
    }

    public function test_upsert_saves_fav_id(): void
    {
        $favId = favorites_add($this->con, $this->userId, 'Karlsplatz', '60200103', 'btn-outline-success', 1);
        state_upsert($this->con, $this->userId, $favId, '60200103');
        $state = state_load($this->con, $this->userId);
        $this->assertSame($favId, $state['last_fav_id']);
        $this->assertSame('60200103', $state['last_diva']);
    }

    public function test_fk_nulls_last_fav_id_on_favorite_delete(): void
    {
        $favId = favorites_add($this->con, $this->userId, 'Karlsplatz', '60200103', 'btn-outline-success', 1);
        state_upsert($this->con, $this->userId, $favId, '60200103');
        favorites_delete($this->con, $this->userId, $favId);
        $state = state_load($this->con, $this->userId);
        $this->assertNull($state['last_fav_id']);
        $this->assertSame('60200103', $state['last_diva']); // diva survives
    }

    public function test_upsert_overwrites_existing(): void
    {
        state_upsert($this->con, $this->userId, null, '60200103');
        state_upsert($this->con, $this->userId, null, '60200456');
        $state = state_load($this->con, $this->userId);
        $this->assertSame('60200456', $state['last_diva']);
    }
}
```

- [ ] Run tests to confirm they FAIL:

```bash
cd /Users/erikr/Git/wlmonitor && ./vendor/bin/phpunit tests/Integration/StatePersistenceTest.php --no-coverage 2>&1 | head -20
```

Expected: Fatal — `state_load` not found.

- [ ] Create `inc/state.php`:

```php
<?php
/**
 * inc/state.php — per-user cross-session monitor state.
 */

/**
 * Load persisted monitor state for a user from wl_preferences.
 *
 * @return array{last_fav_id: int|null, last_diva: string|null}
 */
function state_load(mysqli $con, int $userId): array
{
    $stmt = $con->prepare('SELECT last_fav_id, last_diva FROM wl_preferences WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        'last_fav_id' => ($row && $row['last_fav_id'] !== null) ? (int) $row['last_fav_id'] : null,
        'last_diva'   => $row['last_diva'] ?? null,
    ];
}

/**
 * Upsert persisted monitor state.
 * Pass null for $favId on ad-hoc station loads; pass null for $diva to clear it.
 */
function state_upsert(mysqli $con, int $userId, ?int $favId, ?string $diva): void
{
    $stmt = $con->prepare(
        'INSERT INTO wl_preferences (user_id, last_fav_id, last_diva)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE last_fav_id = VALUES(last_fav_id),
                                 last_diva   = VALUES(last_diva)'
    );
    $stmt->bind_param('iis', $userId, $favId, $diva);
    $stmt->execute();
    $stmt->close();
}
```

- [ ] Run tests — confirm all 5 pass:

```bash
./vendor/bin/phpunit tests/Integration/StatePersistenceTest.php --no-coverage
```

Expected: 5 tests, 0 failures.

- [ ] Commit:

```bash
git add inc/state.php tests/Integration/StatePersistenceTest.php
git commit -m "feat(state): add state_load/state_upsert + 5 integration tests"
```

---

### Task 3: `api.php` — `state_save` action

**Files:**
- Modify: `web/api.php` (add require + new case)

- [ ] Add `require_once` after the existing requires at top of `api.php` (after line with `inc/ogd.php`):

```php
require_once(__DIR__ . '/../inc/state.php');
```

- [ ] Add the `state_save` case after `position_save` (after its `api_json` call, before the `// ── Favorites` comment):

```php
        // ── State ─────────────────────────────────────────────────────────────

        case 'state_save':
            api_require_login();
            api_require_csrf();
            // favId arrives as a FormData string: "5", "0", "" or absent
            $favId = (isset($_POST['favId']) && $_POST['favId'] !== '' && ctype_digit($_POST['favId']))
                ? (int) $_POST['favId'] : null;
            $diva  = sanitizeDivaInput($_POST['diva'] ?? '') ?: null;
            if ($favId !== null) {
                // Ownership check — silently ignore if the fav belongs to another user
                $chk = $con->prepare('SELECT id FROM wl_favorites WHERE id = ? AND idUser = ?');
                $chk->bind_param('ii', $favId, $_SESSION['id']);
                $chk->execute();
                if (!$chk->get_result()->fetch_row()) $favId = null;
                $chk->close();
            }
            state_upsert($con, (int) $_SESSION['id'], $favId, $diva);
            api_json(['ok' => true]);
```

- [ ] Also update the action inventory comment at the top of api.php — add to the Authenticated section:

```
 *   state_save       POST  favId= diva=     Persist last-viewed monitor state (CSRF)
```

- [ ] Run the full test suite to confirm nothing regressed:

```bash
./vendor/bin/phpunit tests/ --no-coverage 2>&1 | tail -5
```

Expected: all existing tests still pass.

- [ ] Commit:

```bash
git add web/api.php
git commit -m "feat(api): add state_save action — persists last_fav_id / last_diva"
```

---

### Task 4: `index.php` — seed `wlConfig` from DB

**Files:**
- Modify: `web/index.php`

- [ ] Add `require_once` after the existing `initialize.php` require at line 2:

```php
require_once(__DIR__ . '/../inc/state.php');
```

- [ ] After the existing `$loadFavId` / `unset` block (lines 11–12), insert:

```php
$initialDiva = null;
if ($loggedIn && !$loadFavId) {
    $state = state_load($con, $userID);
    if ($state['last_fav_id'] !== null) {
        $loadFavId = $state['last_fav_id']; // FK guarantees favourite still exists
    } elseif ($state['last_diva'] !== null) {
        $initialDiva = $state['last_diva'];
    }
}
```

- [ ] Extend the `wlConfig` block (currently lines 95–101) to expose `initialDiva`:

```php
window.wlConfig = {
  userID:      <?= $userID ?>,
  loggedIn:    <?= $loggedIn ? 'true' : 'false' ?>,
  theme:       <?= json_encode($theme) ?>,
  alerts:      <?= $alertsJson ?>,
  loadFavId:   <?= $loadFavId ?>,
  initialDiva: <?= $initialDiva ? json_encode($initialDiva, JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>
};
```

- [ ] Commit:

```bash
git add web/index.php
git commit -m "feat(index): seed wlConfig.initialDiva from wl_preferences"
```

---

### Task 5: `wl-monitor.js` — `saveState` + restore order + anonymous localStorage

**Files:**
- Modify: `web/js/wl-monitor.js`

- [ ] Add `saveState` helper after `loadMonitor` (insert after its closing `}`, around line 90):

```js
function saveState(diva, favId = null) {
  if (window.wlConfig?.loggedIn) {
    const body = {};
    if (diva)  body.diva  = diva;
    if (favId) body.favId = favId;
    apiPost('state_save', body).catch(e => console.error('state_save failed', e));
  } else if (diva) {
    localStorage.setItem('wl_last_diva', diva);
  }
}
```

- [ ] Update the DOMContentLoaded init block (around lines 28–33) to add `initialDiva` fallback and anonymous localStorage:

Replace:
```js
  const loadFavId = window.wlConfig?.loadFavId;
  const targetFav = loadFavId ? favs.find(f => f.id === loadFavId) : null;
  if (targetFav) {
    loadMonitor(targetFav.diva, targetFav);
  } else {
    loadMonitor();
  }
```

With:
```js
  const loadFavId   = window.wlConfig?.loadFavId;
  const targetFav   = loadFavId ? favs.find(f => f.id === loadFavId) : null;
  const initialDiva = window.wlConfig?.initialDiva
    ?? (!window.wlConfig?.loggedIn ? (localStorage.getItem('wl_last_diva') || null) : null);
  if (targetFav) {
    loadMonitor(targetFav.diva, targetFav);
  } else if (initialDiva) {
    loadMonitor(initialDiva);
  } else {
    loadMonitor();
  }
```

- [ ] Hook favorite button click (around line 476) — add `saveState` call:

```js
    btn.addEventListener('click', () => {
      loadMonitor(fav.diva, fav);
      startMonitorTimer();
      saveState(fav.diva, fav.id);
    });
```

- [ ] Hook station search picks (around lines 546 and 551) — add `saveState` to both click handlers:

```js
span.addEventListener('click', () => { loadMonitor(s.diva); startMonitorTimer(); closeStationDropdown(); saveState(s.diva); });
```
```js
p.addEventListener('click',    () => { loadMonitor(s.diva); startMonitorTimer(); closeStationDropdown(); saveState(s.diva); });
```

- [ ] Hook add-favorite success — after `updateMonitorToolbar()` around line 151, add `saveState`:

```js
      if (diva === currentMonitor.diva) {
        currentMonitor.favId = res.id;
        currentMonitor.fav   = { id: res.id, title, diva, bclass, sort: 0, filter: filterJson ? JSON.parse(filterJson) : null };
        updateMonitorToolbar();
        saveState(diva, res.id);
      }
```

- [ ] Commit:

```bash
git add web/js/wl-monitor.js
git commit -m "feat(js): saveState on user-initiated loads; restore initialDiva + anonymous localStorage"
```

---

### Task 6: Docs + CLAUDE.md + APP_BUILD

**Files:**
- Modify: `inc/initialize.php` (APP_BUILD bump)
- Modify: `CLAUDE.md`
- Modify: `docs/technical-specification.md`
- Modify: `docs/architecture.md`

- [ ] Bump `APP_BUILD` in `inc/initialize.php` (current value + 1):

```php
define('APP_BUILD', 35);
```

- [ ] In `CLAUDE.md`, update the wl_preferences description under "Database Tables":

```
- `wl_preferences` — per-user app preferences: `departures` (int), `last_fav_id` (INT NULL FK→wl_favorites ON DELETE SET NULL), `last_diva` (VARCHAR 16 NULL). Used by `index.php` to restore last-viewed monitor on login.
```

- [ ] In `docs/technical-specification.md` §9.4 wl_preferences table, add the two new columns to the schema listing.

- [ ] In `docs/technical-specification.md` action inventory, add `state_save` entry (login-required, POST+CSRF).

- [ ] In `docs/architecture.md`, find the session/state section and add a sentence: "Cross-session monitor state (last favorite or DIVA) is persisted in `wl_preferences.last_fav_id` / `last_diva` for logged-in users and in `localStorage` for anonymous users."

- [ ] Commit:

```bash
git add inc/initialize.php CLAUDE.md docs/technical-specification.md docs/architecture.md
git commit -m "docs: document state persistence; bump APP_BUILD to 35"
```
<!-- SECTION:PLAN:END -->

<!-- AC:END -->

- [ ] #12 Integration test covers the save→reload→restore path
<!-- AC:END -->
