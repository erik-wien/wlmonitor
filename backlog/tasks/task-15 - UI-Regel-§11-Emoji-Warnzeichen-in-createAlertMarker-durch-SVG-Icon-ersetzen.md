---
id: TASK-15
title: 'UI-Regel §11: Emoji-Warnzeichen in createAlertMarker durch SVG-Icon ersetzen'
status: To Do
assignee: []
created_date: '2026-07-17 10:32'
labels: []
dependencies: []
priority: low
ordinal: 7000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Final-Review-Fund (vorbestehend, nicht Teil des Anzeigetafel-Branches): createAlertMarker() in web/js/wl-monitor.js (~Z. 538) nutzt das Warnzeichen-Emoji als Stoerungs-Marker — verletzt UI-Regel §11 (niemals Emojis, immer SVG aus der Icon-Library). Ersetzen durch Sprite-Icon (css/icons.svg, z. B. triangle-alert aus Lucide; falls fehlend, zentral ergaenzen).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Kein Emoji mehr in wl-monitor.js; Marker als SVG mit aria-label
<!-- AC:END -->
