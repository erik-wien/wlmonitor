---
id: TASK-MEDIUM.1
title: >-
  header: remove self from apps list; fix Sicherheit page; add Anwendung
  (Abfahrten)
status: To Do
assignee: []
created_date: '2026-04-21 16:25'
updated_date: '2026-04-21 16:43'
labels: []
dependencies: []
parent_task_id: TASK-MEDIUM
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Audit 2026-04-21 — three issues in inc/layout.php Header::render() call:

1. SELF-REFERENCE: appMenu lists 'WL Monitor' linking to wlmonitor itself — remove it
2. SECURITY PAGE: securityHref points to 'preferences.php#sicherheit' (anchor link to a tab) — should be a standalone security.php page or at minimum just 'preferences.php' without anchor; check if zeiterfassung security.php pattern should be adopted
3. ANWENDUNG MISSING: 'Abfahrten' tab in preferences.php contains app-specific settings (max departures count) that belong in the Anwendung slot — wire appPrefsHref → preferences.php once Chrome TASK-HIGH.1 ships
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 WL Monitor not listed in its own apps navigation
- [x] #2 securityHref resolves to correct security page without anchor-link workaround
- [ ] #3 Anwendung → preferences.php (Abfahrten tab) wired once Chrome supports appPrefsHref
<!-- AC:END -->
