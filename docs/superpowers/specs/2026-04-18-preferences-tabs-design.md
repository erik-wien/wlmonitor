# Design: Preferences page — tabbed layout

**Date:** 2026-04-18  
**Status:** Approved

## Goal

Reorganise the user preferences and security pages into a single tabbed page (`preferences.php`) with five tabs: Design, Abfahrten, E-Mail, Sicherheit, Profilbild. `security.php` is retired (redirect only).

## Tabs

| # | Hash | Label | Content |
|---|---|---|---|
| 1 | `#design` | Design | Light / Auto / Dark theme radio group + save |
| 2 | `#abfahrten` | Abfahrten | Departures-per-line slider (1–5) + save |
| 3 | `#email` | E-Mail | Change email form (requires password confirmation) |
| 4 | `#sicherheit` | Sicherheit | Change password form + TOTP enable / disable / setup |
| 5 | `#profilbild` | Profilbild | Avatar preview, file input, crop modal |

Default tab on a bare `preferences.php` load: `#design`.

## Architecture

### `preferences.php`

- Absorbs all PHP logic currently in `security.php`: `change_password`, `totp_start`, `totp_confirm`, `totp_disable` POST handlers; 2FA status read; QR code generation.
- All forms that currently POST to `security.php` are updated to POST to `preferences.php`.
- On POST validation error, PHP sets `$activeTab` (string, e.g. `'sicherheit'`) derived from `$_POST['action']`:
  - `change_theme` → `design`
  - `change_departures` → `abfahrten`
  - `change_email` → `email`
  - `change_password` / `totp_*` → `sicherheit`
  - `upload_avatar` → `profilbild` (AJAX — no re-render needed)
- `$activeTab` is written into a `window.wlPrefsTab` inline JS variable so the tab-switching script can activate the correct panel on load.
- On POST success, redirect carries the hash: `Location: preferences.php#sicherheit` etc.

### `security.php`

Reduced to a single redirect:

```php
<?php
require_once __DIR__ . '/../inc/initialize.php';
header('Location: preferences.php#sicherheit');
exit;
```

### User-menu (`inc/html_header.php`)

- "Einstellungen" link: unchanged (`preferences.php`).
- "Passwort & 2FA" link: updated from `security.php` to `preferences.php#sicherheit`.

## Tab switching

Uses the shared `.tab-bar` / `.tab-btn` / `.tab-panel` components from `components.css` (UI rule §15.4):

- Tab buttons carry `role="tab"`, `aria-controls`, `aria-selected`; panels carry `role="tabpanel"`, `aria-labelledby`, `hidden` on inactive panels.
- JS reads `window.wlPrefsTab` (PHP error-state override) first, then `location.hash`, then falls back to `design`.
- Hash is updated on tab switch so the browser back button and direct links work.

## Rule override

UI rule §12 states "User preferences and Password & 2FA are always two distinct menu items linking to two distinct pages." This design intentionally merges them into one page. The user-menu retains two distinct entries (pointing at different hash anchors), preserving the navigation split while consolidating the implementation. The rule is overridden by explicit user instruction.

## Out of scope

- Moving 2FA admin actions (Revoke 2FA) from `admin.php` — those stay where they are.
- Changing any of the form logic, validation, or API calls.
- Keyboard-accessible tab switching beyond what the shared component provides.
