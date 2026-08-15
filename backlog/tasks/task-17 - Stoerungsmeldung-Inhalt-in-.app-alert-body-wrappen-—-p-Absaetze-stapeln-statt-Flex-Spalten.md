---
id: TASK-17
title: >-
  Stoerungsmeldung: Inhalt in .app-alert-body wrappen — p-Absaetze stapeln statt
  Flex-Spalten
status: Done
assignee: []
created_date: '2026-07-17 11:35'
updated_date: '2026-07-17 14:11'
labels: []
dependencies: []
priority: medium
ordinal: 9000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Folge zu TASK-16, echte Wurzel gefunden: WL-descriptionHTML sind schlichte <p>-Absaetze; .app-alert ist display:flex (Library, fuer Icon+Text) -> alle p werden Flex-Items NEBENEINANDER (die 'Tabellen'-Optik). Die Library sieht dafuer .app-alert-body (flex:1) vor — renderMonitor baut strong/br/Fragment aber direkt in die Flexbox. Fix: Inhalt in div.app-alert-body wrappen (JS, renderMonitor) + App-CSS p-Margins kompaktieren; TASK-16-overflow-Fix bleibt als Netz fuer echte breite Inhalte.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Meldungszeilen stapeln untereinander (Linie D / Umleitung / ... je eigene Zeile), kein horizontales Scrollen bei p-basierten Meldungen
- [ ] #2 Live auf jardyx mit der echten 1/D/71-Stoerung verifiziert
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
ERLEDIGT + DEPLOYT 2026-07-17 (Commit 58fb545). Wurzel: .app-alert ist flex (Library) — die <p>-Absaetze aus WL-descriptionHTML wurden zu Flex-Spalten nebeneinander ('Tabellen-Optik'). Fix: renderMonitor wrappt Titel+Inhalt in die von der Library vorgesehene .app-alert-body (flex:1) + kompakte p-Margins im App-CSS. Verifiziert lokal (Simulation stacked:true/scrollable:false + echte U6-Meldung) und live auf jardyx.com mit der echten 1/D/71-Gleisbauarbeiten-Stoerung: alle Angaben untereinander, kein Scrollen, Chips frei. Beide Hosts deployt. TASK-16-overflow-Fix bleibt als Netz fuer echte breite Inhalte.
<!-- SECTION:NOTES:END -->
