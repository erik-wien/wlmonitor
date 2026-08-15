---
id: TASK-14
title: UI-Redesign Anzeigetafel (Spec+Plan 2026-07-17)
status: Done
assignee: []
created_date: '2026-07-17 08:39'
updated_date: '2026-07-17 10:42'
labels: []
dependencies: []
priority: high
ordinal: 6000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Grosse Neugestaltung der UI in der validierten Anzeigetafel-Designsprache. Spec: docs/superpowers/specs/2026-07-17-anzeigetafel-redesign-design.md. Implementierungsplan (6 Tasks, verbindlich): docs/superpowers/plans/2026-07-17-anzeigetafel-redesign.md. Brainstorming + Mockups (v3 Board, Layout B) vom User freigegeben. Keine Logik-/API-Aenderungen.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Alle 6 Plan-Tasks umgesetzt und einzeln committet
- [ ] #2 Spec-Akzeptanzkriterien 1-6 erfuellt (Tafel dunkel, Papier hell, Layout B, Suche, Settings-Haut, A11y/Motion)
- [ ] #3 APP_BUILD auf 46
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
UMSETZUNG ABGESCHLOSSEN 2026-07-17 auf Branch feat/anzeigetafel (11 Commits b27a143..9eccfbb). Alle 6 Plan-Tasks via Subagent-Driven-Development (frischer Implementer + Task-Review je Task, 5 Review-Fixes: fav-active-Restore, O/D-Trams, search-toggle-Spezifitaet, BB=WLB, AA-Kontrast-Toene). Final-Whole-Branch-Review (Opus): MERGE-READY, AK1-6 erfuellt. Extra: JS-Cache-Busting (Important-Fund), APP_BUILD 46. Browser-verifiziert hell+dunkel (Board, Chips, Suche, Settings/Admin). Offen: Mobile-Query auf Realgeraet pruefen (Fenster-Minimum verhinderte Emulation); vorbestehendes Emoji -> TASK-15. Merge nach User-Entscheid.
<!-- SECTION:NOTES:END -->
