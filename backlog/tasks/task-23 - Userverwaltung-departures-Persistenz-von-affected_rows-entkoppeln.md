---
id: TASK-23
title: 'Userverwaltung: departures-Persistenz von affected_rows entkoppeln'
status: Done
assignee: []
created_date: '2026-07-24 08:07'
updated_date: '2026-07-24 08:25'
labels: []
dependencies: []
ordinal: 15000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Beim Suite-Umbau 2026-07-24 dokumentierter latenter Bug (inc/admin.php:58-81 wl_admin_edit_user): der departures-Schreibpfad haengt am Rueckgabewert von admin_edit_user(), der bei Same-Value-Edits affected_rows=0 -> false liefert (Verbindung ohne CLIENT_FOUND_ROWS). "Nur Abfahrten aendern" wird daher nicht persistiert und die UI meldet einen Fehler. Fix-Muster: zeiterfassung Commit 19efbf7 (Erfolg entkoppeln, DB-Test; dort auch Low-Nachtrag: Existenzcheck fuer targetId gegen Orphan-Zeilen beruecksichtigen).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Nur-departures-Edit persistiert und meldet ok:true
- [ ] #2 DB-Test fuer den Same-Value-Fall
<!-- AC:END -->
