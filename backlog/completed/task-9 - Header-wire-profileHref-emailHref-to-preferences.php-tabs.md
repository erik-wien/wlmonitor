---
id: TASK-9
title: 'Header: wire profileHref + emailHref to preferences.php tabs'
status: Done
assignee: []
created_date: '2026-04-21 19:23'
updated_date: '2026-04-22 04:46'
labels: []
dependencies: []
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
preferences.php currently has 5 tabs: Design, Abfahrten, E-Mail, Sicherheit, Profilbild (TASK-7). Per §18 (updated): Benutzereinstellungen covers identity only (Profilbild + E-Mail). Sicherheit must be a separate top-level security.php page — it was there before TASK-7 absorbed it. Fix: (1) remove Sicherheit tab from preferences.php, move its content back to security.php; (2) wire profileHref => preferences.php#profilbild, emailHref => preferences.php#email, securityHref => security.php in Header::render().
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Sicherheit content (password, 2FA, active sessions) lives in security.php, not preferences.php
- [x] #2 preferences.php Sicherheit tab removed
- [x] #3 profileHref => 'preferences.php#profilbild' wired
- [x] #4 emailHref => 'preferences.php#email' wired
- [x] #5 securityHref => 'security.php' already correct — verify still works after split
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
### 1. Audit security.php (starting point)

Current `security.php` redirects to `preferences.php#sicherheit` (TASK-7 broke this). Read security.php to confirm it is a redirect stub.

### 2. Move Sicherheit content from preferences.php → security.php

In `web/preferences.php`:
- Find the PHP action handlers for `change_password`, `totp_start`, `totp_confirm`, `totp_disable`, `revoke_all_devices`, `revoke_one_device` (~lines 166-250 of preferences.php based on grep output)
- Move those handlers to `security.php` (after auth/CSRF setup boilerplate)
- Update all `header('Location: preferences.php#sicherheit')` redirects to `header('Location: security.php')`
- Remove the `data-tab="sicherheit"` tab-btn and its tab-panel from the HTML
- Remove the `in_array($action, ['change_password', 'totp_start', ...], true) => 'sicherheit'` match arm

### 3. Rebuild security.php

`security.php` needs:
- `auth_require()` at top
- CSRF-verified POST handler for password change, TOTP actions, session revocation
- HTML: `render_header('Sicherheit')` + the Sicherheit tab-panel content (password form, TOTP section, active sessions list)
- `render_footer()`
- Reference: use existing Sicherheit tab HTML in preferences.php as the source

### 4. Wire hrefs in inc/layout.php

`profileHref` and `emailHref` are already wired per the earlier session commit. Confirm they point to `preferences.php#profilbild` and `preferences.php#email`. Verify `securityHref => 'security.php'` is present (it was set in an earlier commit).

### 5. Smoke-test

Load wlmonitor locally: user dropdown → Profilbild links to preferences.php#profilbild, E-Mail to #email, Sicherheit to security.php. Confirm password change POST on security.php works end-to-end.
<!-- SECTION:PLAN:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Rebuilt security.php as a full standalone page (password change, TOTP 2FA, active sessions). Stripped all Sicherheit handlers and tab from preferences.php — tabs are now Design / Abfahrten / E-Mail / Profilbild. securityHref was already correctly wired to security.php in layout.php.
<!-- SECTION:FINAL_SUMMARY:END -->
