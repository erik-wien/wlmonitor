---
id: TASK-8
title: 'Fix user dropdown menu: order, duplicates, missing icons, badge classes'
status: Done
assignee: []
created_date: '2026-04-20 05:00'
labels:
  - bug
  - ui
dependencies: []
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Retroactive task for menu debugging session. Multiple compounding issues caused the user dropdown and header nav to be broken.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Dropdown order: Einstellungen → Passwort & 2FA → Administration → theme-row → Hilfe → Abmelden
- [ ] #2 No duplicate Konto drill-down sub-panel on mobile
- [ ] #3 Section label shows static 'Konto' instead of dynamic username
- [ ] #4 Icons shield, shield-off, history render correctly in preferences and admin pages
- [ ] #5 badge-success, badge-danger, badge-info, badge-warning classes available in shared components.css
- [ ] #6 Active session row highlighted via .is-current CSS rule
<!-- AC:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Fixed six distinct issues across four files:

1. **Header.php** (`~/Git/chrome/src/Header.php`): Removed `dd-sub-konto` mobile drill-down panel and its trigger button (duplicated account links on mobile). Made all account links flat `dropdown-link-btn` visible on all screen sizes. Moved Hilfe after the theme-row divider. Changed section label from dynamic `$un` to static `"Konto"`.

2. **icons.svg** (`web/css/icons.svg`): Added three missing `<symbol>` elements — `icon-shield`, `icon-shield-off`, `icon-history` — which were referenced in preferences.php and admin.php but absent from the sprite.

3. **components.css** (`~/Git/css_library/components.css`): Added `.badge-success`, `.badge-danger`, `.badge-info`, `.badge-warning` color variants using semantic design tokens.

4. **wl-monitor.css** (`web/css/app/wl-monitor.css`): Added `.table > tbody > tr.is-current > *` highlight rule for the active sessions table.

5. **preferences.php**: Fixed `badge bg-success` → `badge badge-success` (Bootstrap utility class was not in shared CSS).

6. **initialize.php**: Bumped `APP_BUILD` from 39 to 40.
<!-- SECTION:FINAL_SUMMARY:END -->
