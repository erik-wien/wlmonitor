---
id: TASK-19
title: >-
  fav-active weiss-auf-weiss am iPad: Safari-Sticky-Hover bricht
  currentColor-Fuellung
status: Done
assignee: []
created_date: '2026-07-17 14:25'
updated_date: '2026-07-17 14:32'
labels: []
dependencies: []
priority: high
ordinal: 11000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
User-Report 2026-07-17 (iPad-Screenshots): aktiver Favoriten-Chip rendert als weisser Block. Ursache: Library-:hover (.btn-outline-color-*:hover) setzt color:#fff; Safari laesst :hover nach Tap 'kleben' -> background:currentColor greift #fff ab, Kind-Text ebenfalls #fff -> weiss auf weiss. Fix: (1) renderFavorites friert die berechneten Ruhe-Farben der Library-Klasse in CSS-Variablen ein (--chip-fill=borderColor [= bright-Ton = Library-Hover-Fuellton], --chip-color=color) via el.style.setProperty (CSSOM, CSP-safe); .fav-active nutzt var(--chip-fill). (2) grey-light von der #fff- in die #000-Textliste (Fuellton ist jetzt hell). (3) @media (hover:none): Sticky-Hover auf Chips neutralisieren (background transparent, color var(--chip-color)).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Aktiver Chip am iPad in seiner Farbe gefuellt mit lesbarem Text (kein weiss-auf-weiss)
- [ ] #2 Tap auf inaktiven Chip hinterlaesst keinen haengenden Hover-Zustand (hover:none-Guard)
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
ERLEDIGT + DEPLOYT 2026-07-17 (Commit 0ea05eb). Fuellfarbe wird beim Rendern aus computed border-color eingefroren (--chip-fill/--chip-color via CSSOM), .fav-active nutzt die Variable statt currentColor; hover:none-Guard neutralisiert Sticky-Hover; grey-light-Textfarbe auf #000. Desktop verifiziert (Chip gefuellt+lesbar); iPad-Bestaetigung durch User ausstehend. Hinweis: getComputedStyle-Reads nach Poll-Rerender lieferten Artefakte (detachte Elemente) — visuelle Verifikation massgeblich.
<!-- SECTION:NOTES:END -->
