---
id: TASK-16
title: >-
  Stoerungsmeldungen: HTML-Tabellen aus descriptionHTML brechen aus der
  Alert-Box aus
status: Done
assignee: []
created_date: '2026-07-17 11:14'
updated_date: '2026-07-17 11:28'
labels: []
dependencies: []
priority: medium
ordinal: 8000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
User-Report 2026-07-17 (iPad, Pub->Heim): WL-API liefert bei Gleisbauarbeiten descriptionHTML mit <table>; parseTrustedHtml injiziert sie 1:1; weder .app-alert (shared) noch app-CSS begrenzen Tabellen -> min-content-Breite sprengt die Box, Inhalt laeuft ueber die Favoriten-Chips. Vorbestehend (kein Redesign-Bruch), im neuen Layout aber deutlich sichtbar. Fix app-lokal in wl-monitor.css: #monitorAlerts .app-alert { overflow-x:auto } + Basis-Styling fuer injizierte table/td (Padding, Border in --board-title-line, color erben, width:100% wenn moeglich; keine shared/-Aenderung).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Gleisbauarbeiten-Alert mit Tabelle bleibt innerhalb der Box (scrollt horizontal statt auszubrechen), Chips nicht mehr ueberlagert
- [ ] #2 Tabellen-Zellen lesbar gestylt (hell+dunkel); normale Text-Alerts unveraendert
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
ERLEDIGT + DEPLOYT 2026-07-17 (Commit 7f45f72). Befund: Die WL-Struktur ist kein woertliches <table>-Tag (auf jardyx live: table:false), sondern ein breites display:table-artiges Markup — daher Container-Fix auf Alert-Ebene (overflow-x:auto) elementunabhaengig korrekt; zusaetzlich Basis-Styling fuer echte table/td-Faelle. Lokal mit simulierter Tabelle + live auf jardyx.com mit der echten 1/D/71-Gleisbauarbeiten-Stoerung verifiziert: Inhalt bleibt in der Box, scrollt horizontal, Chips frei. Beide Hosts deployt (akadbrain + world4you).
<!-- SECTION:NOTES:END -->
