# WL Monitor — Header / Footer / Chrome Alignment Design

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Bootstrap-style `.navbar` in WL Monitor with the shared jardyx `.app-header` / `.user-dropdown` / `.theme-row` pattern used by Energie and Zeiterfassung, so all four apps share the same chrome design language.

**Architecture:** `inc/html_header.php` becomes the full page opener including the `.app-header` nav bar. Pages that include it pass optional flags (`$show_search`, `$show_nav`) to control what appears. The station search (`#stationSearchWrap`) moves from `web/index.php`'s manually-built `<nav>` block into the shared header. `inc/html_footer.php` is already correct (uses `.app-footer`) — no changes needed there.

**Tech stack:** PHP 8, shared CSS library (`web/css/shared/` → `~/Git/css`), `wl-monitor.css`, vanilla JS. No build step.

---

## Scope

This spec covers **header, footer, background** only. Bootstrap-specific classes inside page bodies (`.btn-outline-*`, `.modal`, `.dropdown-item` on page content) are out of scope.

---

## File map

| File | Action |
|------|--------|
| `inc/html_header.php` | Rewrite: add `<html data-theme>`, full `.app-header` with brand + conditional search + conditional user dropdown |
| `web/index.php` | Remove the `<nav class="navbar">` block (lines ~25–121); it moves to the header |
| `web/css/app/wl-monitor.css` | Add `.header-search` styles for the search input inside `.app-header` |

`inc/html_footer.php` — already uses `.app-footer`, no changes needed.
All other pages (login, forgotPassword, executeReset, register, admin, preferences, editFavorite) continue to `include html_header.php` unchanged.

---

## Design decisions

### `data-theme` initialisation

`html_header.php` reads the theme from session (logged-in) or cookie (anonymous) and sets `data-theme` on `<html>` server-side — same approach as `zeiterfassung/inc/_header.php`:

```php
$_theme = $loggedIn
    ? ($_SESSION['theme'] ?? 'auto')
    : ($_COOKIE['theme']  ?? 'auto');
```

`<html lang="de" data-theme="<?= htmlspecialchars($_theme, ENT_QUOTES) ?>">` — use `"auto"` as the default (lets `@media prefers-color-scheme` decide). `theme.css` already handles `data-theme="dark"` and `data-theme="light"` as explicit overrides, and the `@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) }` selector handles the auto case.

### Header layout

```
[ 🔴 WL Monitor ]  [ station search ····▾ ]  ···spacer···  [ avatar  erikr ▾ ]
```

- Brand: `<a class="brand" href="index.php">` with jardyx.svg + "WL Monitor"
- Search (only on `index.php`): same `#stationSearchWrap` / `#s` / `#stationDropdown` IDs — JS references must be preserved
- User menu: `.user-menu` / `.user-btn` / `.user-dropdown` from `layout.css`, avatar from `avatar.php?id=…`
- Theme row: `.theme-row` with three `.theme-btn` buttons (☀ ⬤ 🌙)
- Logout: `<form method="post" action="logout.php">` with `csrf_input()` inside dropdown
- Not logged in: brand only, no user menu (login/register/reset pages)

Controlling search visibility: the including page sets `$show_search = true` before `include html_header.php`. Default is `false`. Only `web/index.php` sets this.

### Theme switcher JS

Same pattern as Zeiterfassung `_header.php` — inline `<script>` in the header that:
1. Attaches `.theme-btn` click handlers
2. Updates `document.documentElement.dataset.theme`
3. POSTs `action=theme_save` to `api.php` (existing endpoint, already handles this) with CSRF token
4. Sets `document.cookie` for anonymous persistence

### Search input styling in `.app-header`

The search needs to expand to fill available horizontal space while staying capped at ~280px. Add to `wl-monitor.css`:

```css
.header-search {
  flex: 1;
  max-width: 280px;
  display: flex;
  align-items: center;
  gap: 6px;
  position: relative;
}
.header-search .form-control {
  background: var(--color-surface-alt);
  border: 1px solid var(--color-border);
  border-radius: 7px;
  color: var(--color-text);
  padding: 5px 10px;
  font-size: 0.85rem;
  width: 100%;
}
.header-search .form-control:focus {
  outline: none;
  border-color: var(--color-primary);
}
```

The existing `#stationDropdown` is absolutely positioned and already works relative to `#stationSearchWrap`. No change needed there.

### Auth pages (login, forgotPassword, executeReset, register)

These include `html_header.php` but `$loggedIn` will be false, so no `.app-header` nav renders — just `<!DOCTYPE html><html data-theme="..."><head>...<body>`. The pages build their own auth-card layout as before.

---

## Out of scope

- Migrating Bootstrap `.btn-outline-*`, `.modal`, `.dropdown-item` in page body content — separate task
- SimpleChat `--sc-*` variable alignment — separate task
- WL Monitor preferences page redesign
