---
id: TASK-18
title: 'Stoerungs-Feinschliff: WL-Leerabsaetze filtern + LIVE-Badge auf Dot reduzieren'
status: Done
assignee: []
created_date: '2026-07-17 14:24'
updated_date: '2026-07-17 16:36'
labels: []
dependencies: []
priority: medium
ordinal: 10000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
User-Feedback 2026-07-17 (iPad-Screenshots): (1) WL-descriptionHTML streut Leer-Absaetze <p><br></p> -> unnoetige Leerzeilen in Meldungen; im Alert-Builder herausfiltern (JS, textContent leer -> remove). (2) Das gruene 'LIVE hh:mm:ss' pro Karte (3x pro Screen) lenkt ab; der Dot allein reicht als Live-Kennzeichen, 'Aktualisiert: hh:mm:ss' unten traegt die Zeit — Badge auf Dot reduzieren (Zeit als title/aria-label am Dot), Dot mit dezentem Glow-Puls, Aktualisiert-Zeile leicht poliert (tabular-nums).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Meldungen ohne Leerzeilen (10A-Beispiel: Titel/Linie/Text/Grund direkt gestapelt)
- [ ] #2 Kartenkopf zeigt nur gruenen Dot (Zeit via title/aria-label); Aktualisiert-Zeile unten unveraendert vorhanden
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
NACHTRAG (Commit 098f215): U4/U6-Meldungen nutzen ein zweites WL-Format — Textzeilen mit <br>-Trennern; Leerzeilen als br+' '+br bzw. br+br. Filter erweitert: leere p/div raus, Whitespace-Textknoten raus, br-Folgen auf eines reduziert. Live auf jardyx verifiziert (U4 Bauarbeiten + U6 Verspaetungen kompakt).
<!-- SECTION:NOTES:END -->
