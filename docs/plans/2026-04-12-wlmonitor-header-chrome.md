# WL Monitor Header/Footer Chrome Alignment — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Bootstrap-style `.navbar` in WL Monitor with the shared jardyx `.app-header` / `.user-dropdown` / `.theme-row` pattern so the app visually matches Energie and Zeiterfassung.

**Architecture:** `inc/html_header.php` is rewritten to output the full page opener including `.app-header`. The station search (`#stationSearchWrap`, `#s`, etc.) moves from the manually-built `<nav>` in `web/index.php` into the shared header, controlled by a `$show_search` flag. A new inline `<script>` handles the `.theme-btn` clicks (saves via `preferences.php`, updates `document.documentElement.dataset.theme`). `inc/html_footer.php` already uses `.app-footer` — no changes needed there.

**Tech Stack:** PHP 8, shared CSS library (`web/css/shared/` symlinks), `web/css/app/wl-monitor.css`, vanilla JS (ES module at `web/js/wl-monitor.js`).

---

## File map

| File | Action |
|------|--------|
| `web/css/app/wl-monitor.css` | Replace `nav.navbar #s` selector; add `.header-search` and `.sort-btn-group` CSS |
| `inc/html_header.php` | Full rewrite — adds `<html data-theme>`, `.app-header`, user dropdown, theme-switcher JS |
| `web/index.php` | Add `$show_search = true`; remove `<nav class="navbar">` block; remove now-unused `$uname`/`$rights`/`$avatarUrl` |

---

## Background: what was broken

`setTheme()` in `wl-monitor.js` is a module-scoped function. The old Bootstrap navbar buttons used `onclick="setTheme('light')"` — but module functions are not in the global scope, so those handlers silently did nothing. The theme switcher was non-functional. The new inline script in `html_header.php` fixes this with proper `addEventListener` calls.

---

## Task 1: Update wl-monitor.css

**Files:**
- Modify: `web/css/app/wl-monitor.css` lines 106–119 (the `nav.navbar #s` block)

- [ ] **Step 1: Replace the `nav.navbar #s` search styling block**

In `web/css/app/wl-monitor.css`, replace the comment and three rules (lines 106–119):

```css
/* ── Search input inside navbar ─────────────────────────────────────────────── */

nav.navbar #s {
  background-color: color-mix(in srgb, var(--color-nav-bg) 70%, var(--color-surface));
  color: var(--color-nav-text);
  border-color: color-mix(in srgb, var(--color-nav-text) 25%, transparent);
}
nav.navbar #s::placeholder { color: var(--color-muted); opacity: 1; }
nav.navbar #s:focus {
  background-color: var(--color-bg);
  color: var(--color-text);
  border-color: var(--color-primary);
  box-shadow: 0 0 0 0.2rem color-mix(in srgb, var(--color-primary) 25%, transparent);
}
```

With this block:

```css
/* ── Station search inside .app-header ──────────────────────────────────────── */

.header-search {
  flex: 1;
  max-width: 280px;
  display: flex;
  align-items: center;
  position: relative;
}
.header-search .search-row {
  display: flex;
  gap: 4px;
  width: 100%;
}
.header-search #s {
  flex: 1;
  padding: 5px 10px;
  background: var(--color-surface-alt);
  border: 1px solid var(--color-border);
  border-radius: 7px;
  color: var(--color-text);
  font-size: 0.85rem;
  font-family: inherit;
}
.header-search #s::placeholder { color: var(--color-muted); }
.header-search #s:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-primary) 25%, transparent);
}
.header-search .btn-icon {
  background: none;
  border: 1px solid var(--color-border);
  border-radius: 7px;
  color: var(--color-muted);
  cursor: pointer;
  padding: 4px 7px;
  display: flex;
  align-items: center;
  line-height: 1;
  transition: background .15s, color .15s;
}
.header-search .btn-icon:hover { background: var(--color-surface-alt); color: var(--color-text); }

/* ── Sort buttons in station dropdown (replaces Bootstrap .btn-check) ─────────── */

.sort-btn-group {
  display: flex;
  width: 100%;
  border-radius: 6px;
  overflow: hidden;
  border: 1px solid var(--color-border);
}
.sort-btn-group input[type="radio"] { display: none; }
.sort-btn-group label {
  flex: 1;
  text-align: center;
  padding: 0.35rem 0.5rem;
  font-size: 0.8rem;
  color: var(--color-muted);
  cursor: pointer;
  background: var(--color-surface);
  transition: background .15s, color .15s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.25rem;
}
.sort-btn-group label:not(:last-child) { border-right: 1px solid var(--color-border); }
.sort-btn-group input[type="radio"]:checked + label {
  background: var(--color-surface-alt);
  color: var(--color-text);
  font-weight: 600;
}
```

- [ ] **Step 2: Verify change looks correct**

Open `http://localhost/wlmonitor.test` and confirm the page still renders (even if header isn't fixed yet — CSS change only).

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add web/css/app/wl-monitor.css
git commit -m "style: replace Bootstrap navbar search styles with .header-search"
```

---

## Task 2: Rewrite inc/html_header.php

**Files:**
- Modify: `inc/html_header.php` (full rewrite)

- [ ] **Step 1: Replace the entire file**

Write the following to `inc/html_header.php`:

```php
<?php
/**
 * html_header.php — full page opener: DOCTYPE, <html data-theme>, <head>,
 * <body>, and the shared .app-header navigation bar.
 *
 * Variables the including page may set before this include:
 *   $show_search (bool, default false) — show station search in header (index.php only)
 */

if (!isset($show_search)) { $show_search = false; }

$_csp      = $_cspNonce ?? '';
$_loggedIn = !empty($_SESSION['loggedin']);
$_theme    = $_loggedIn
    ? ($_SESSION['theme'] ?? 'auto')
    : ($_COOKIE['theme']  ?? 'auto');
$_theme    = in_array($_theme, ['light', 'dark', 'auto'], true) ? $_theme : 'auto';
$_username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$_isAdmin  = (($_SESSION['rights'] ?? '') === 'Admin');
$_uid      = (int)($_SESSION['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="de" data-theme="<?= htmlspecialchars($_theme, ENT_QUOTES) ?>">
<head>
  <title>Wiener Linien Abfahrtsmonitor</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <meta name="application-name" content="WL Monitor">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="WL Monitor">
  <meta name="theme-color" content="#000000">
  <link rel="icon" type="image/x-icon" href="img/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="img/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="img/apple-touch-icon.png">
  <link rel="manifest" href="img/manifest.json">
  <link rel="stylesheet" href="css/shared/theme.css">
  <link rel="stylesheet" href="css/shared/reset.css">
  <link rel="stylesheet" href="css/shared/layout.css">
  <link rel="stylesheet" href="css/shared/components.css">
  <link rel="stylesheet" href="css/app/wl-monitor.css">
</head>
<body>
<?php
// Inline SVG sprite (one HTTP request, browser-cached)
$_spritePath = __DIR__ . '/../web/css/icons.svg';
if (file_exists($_spritePath)) { readfile($_spritePath); }
unset($_spritePath);
?>
<header class="app-header">
    <a class="brand" href="index.php">
        <img src="img/wl-logo.svg" class="header-logo" width="28" height="28" alt="">
        <span class="header-appname">WL Monitor</span>
    </a>
    <?php if ($show_search): ?>
    <div class="header-search" id="stationSearchWrap">
        <div class="search-row">
            <input type="search" id="s" class="form-control"
                   placeholder="Station suchen …" autocomplete="off">
            <button class="btn-icon" id="stationListToggle" type="button"
                    tabindex="-1" title="Alle Stationen">
                <?= icon("chevron-down") ?>
            </button>
        </div>
        <div id="stationDropdown" class="station-dropdown" style="display:none;">
            <div class="station-dropdown-header">
                <div class="sort-btn-group">
                    <input type="radio" name="stationSort" id="sortAlpha"
                           value="alpha" autocomplete="off" checked>
                    <label for="sortAlpha"><?= icon("sort-alpha") ?> A–Z</label>
                    <input type="radio" name="stationSort" id="sortDist"
                           value="dist" autocomplete="off">
                    <label for="sortDist"><?= icon("map-marker") ?> Nähe</label>
                </div>
            </div>
            <ul id="stationList" style="list-style:none;padding:0;margin:0;"></ul>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($_loggedIn): ?>
    <div class="user-menu">
        <button class="user-btn" type="button">
            <img src="avatar.php?id=<?= $_uid ?>" class="avatar" width="26" height="26" alt="">
            <span><?= $_username ?></span>
            <svg class="chevron" viewBox="0 0 10 6" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round"><path d="M1 1l4 4 4-4"/></svg>
        </button>
        <div class="user-dropdown">
            <span class="dropdown-username"><?= $_username ?></span>
            <div class="dropdown-divider"></div>
            <a href="preferences.php" class="dropdown-link-btn">Einstellungen</a>
            <?php if ($_isAdmin): ?>
            <a href="admin.php" class="dropdown-link-btn">Admin</a>
            <?php endif; ?>
            <div class="dropdown-divider"></div>
            <div class="theme-row">
                <button class="theme-btn<?= $_theme === 'light' ? ' active' : '' ?>"
                        data-theme="light" title="Hell">☀</button>
                <button class="theme-btn<?= $_theme === 'auto'  ? ' active' : '' ?>"
                        data-theme="auto"  title="Auto">⬤</button>
                <button class="theme-btn<?= $_theme === 'dark'  ? ' active' : '' ?>"
                        data-theme="dark"  title="Dunkel">🌙</button>
            </div>
            <div class="dropdown-divider"></div>
            <form method="post" action="logout.php" style="margin:0">
                <?= csrf_input() ?>
                <button type="submit" class="dropdown-link-btn">Abmelden</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <a href="login.php" class="user-btn" style="text-decoration:none;margin-left:auto">Anmelden</a>
    <?php endif; ?>
</header>
<?php if ($_loggedIn): ?>
<script<?= $_csp ? ' nonce="' . htmlspecialchars($_csp, ENT_QUOTES) . '"' : '' ?>>
(function () {
    const menu = document.querySelector('.user-menu');
    if (!menu) return;

    // Toggle dropdown open/close
    menu.querySelector('.user-btn').addEventListener('click', e => {
        e.stopPropagation();
        menu.classList.toggle('open');
    });
    document.addEventListener('click', () => menu.classList.remove('open'));

    // Theme switcher
    menu.querySelectorAll('.theme-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const theme = btn.dataset.theme;
            // Apply immediately
            if (theme === 'dark' || theme === 'light') {
                document.documentElement.dataset.theme = theme;
            } else {
                delete document.documentElement.dataset.theme;
            }
            // Update active state
            menu.querySelectorAll('.theme-btn').forEach(b =>
                b.classList.toggle('active', b.dataset.theme === theme)
            );
            // Persist in cookie (anonymous + logged-in)
            document.cookie = 'theme=' + theme + ';path=/;max-age=' + (365 * 86400) + ';samesite=Lax';
            // Persist server-side for logged-in users
            const fd = new FormData();
            fd.append('action', 'change_theme');
            fd.append('theme', theme);
            const csrfInput = document.querySelector('input[name="csrf_token"]');
            if (csrfInput) fd.append('csrf_token', csrfInput.value);
            fetch('preferences.php', { method: 'POST', body: fd }).catch(() => {});
        });
    });
})();
</script>
<?php endif; ?>
```

- [ ] **Step 2: PHP-lint the file**

```bash
php -l /Users/erikr/Git/wlmonitor/inc/html_header.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add inc/html_header.php
git commit -m "feat: replace Bootstrap navbar with shared .app-header chrome"
```

---

## Task 3: Update web/index.php

**Files:**
- Modify: `web/index.php` lines 1–121

- [ ] **Step 1: Set `$show_search = true` and remove unused variables**

Replace lines 13–21 of `web/index.php` (from `$loggedIn = ...` through the `$theme = htmlspecialchars(...)` line):

```php
// Before:
$loggedIn = !empty($_SESSION['loggedin']);
$uname    = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$rights   = htmlspecialchars($_SESSION['rights']   ?? '', ENT_QUOTES, 'UTF-8');
// Logged-in users: DB preference (loaded into session at login) is authoritative
$theme = $loggedIn
    ? ($_SESSION['theme'] ?? 'auto')
    : ($_COOKIE['theme']  ?? 'auto');
$theme = htmlspecialchars($theme, ENT_QUOTES, 'UTF-8');
```

```php
// After:
$loggedIn = !empty($_SESSION['loggedin']);
// html_header.php reads theme from session/cookie — pass it to wlConfig for JS use
$theme = $loggedIn
    ? ($_SESSION['theme'] ?? 'auto')
    : ($_COOKIE['theme']  ?? 'auto');
$theme = htmlspecialchars($theme, ENT_QUOTES, 'UTF-8');
$show_search = true;  // show station search in the shared .app-header
```

- [ ] **Step 2: Remove the `<nav class="navbar">` block**

Remove everything from line 24 (`<?php $avatarUrl = ...`) through line 121 (closing `</nav>`), inclusive. That is:

```php
<?php $avatarUrl = 'avatar.php?id=' . (int) ($_SESSION['id'] ?? 0); ?>
<nav class="navbar" id="mainNav">
  ...  (entire block)
</nav>
```

After removal, line 22 (`<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>`) should be immediately followed by line 123 (`<div id="alerts" class="container-fluid mt-2"></div>`).

- [ ] **Step 3: PHP-lint**

```bash
php -l /Users/erikr/Git/wlmonitor/web/index.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Smoke-test in browser**

Open `http://localhost/wlmonitor.test`:
- Header shows jardyx logo + "WL Monitor" brand, station search input, and user dropdown (if logged in)
- Dropdown opens on click, closes on outside click
- ☀ ⬤ 🌙 buttons switch theme immediately; page reloads in correct theme
- Station search still works (typing a station name shows results)
- Departure data still loads

- [ ] **Step 5: Check other pages**

Open `http://localhost/wlmonitor.test/login.php`:
- Shows `.app-header` with brand + "Anmelden" link on the right
- Login form renders correctly below the header

Open `http://localhost/wlmonitor.test/preferences.php` (logged in):
- Shows `.app-header` with brand + user dropdown (no search box)
- Preferences content renders correctly below

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add web/index.php
git commit -m "refactor: move station search and user nav into shared app-header"
```
