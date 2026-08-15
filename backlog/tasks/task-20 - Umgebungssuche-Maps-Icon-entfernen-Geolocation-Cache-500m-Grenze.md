---
id: TASK-20
title: 'Umgebungssuche: Maps-Icon entfernen, Geolocation-Cache, 500m-Grenze'
status: Done
assignee: []
created_date: '2026-07-17 16:31'
updated_date: '2026-07-17 16:36'
labels: []
dependencies: []
priority: medium
ordinal: 12000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
User-Report 2026-07-17: (1) Orts-Icon (Google-Maps-Fusswegroute) in der Naehe-Liste ueberfluessig, erzeugt durch Zeilenumbruch mit den neuen Signal-Badges eigene Leerzeilen -> entfernen (inkl. mapsUrl-Totcode). (2) Listenaufbau merkbar langsam: getCurrentPosition ohne maximumAge erzwingt jedes Mal frischen GPS-Fix (Sekunden am iPad) -> {maximumAge:120000, enableHighAccuracy:false, timeout:8000}; zusaetzlich Render auf Stationen <=500m begrenzen (Fallback: mindestens die 8 naechsten, sonst leere Liste am Stadtrand).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Naehe-Liste ohne Maps-Icon, eine Zeile pro Station (Name + Distanz + Signale)
- [ ] #2 Wiederholtes Oeffnen der Naehe-Liste nutzt gecachte Position (sofortiger Aufbau); Liste auf <=500m begrenzt mit Mindest-8-Fallback
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
ERLEDIGT + DEPLOYT 2026-07-17 (Commit 098f215). Maps-Icon+Link entfernt (samt stationOrigin-Totcode); Geolocation mit maximumAge:120000/enableHighAccuracy:false (gecachte Position statt frischem GPS-Fix — der Hauptteil der gefuehlten Wartezeit); Liste auf <=500m begrenzt, Fallback 8 naechste. Naehe-Liste selbst (Icon weg, Tempo) bitte am iPad gegenpruefen (Desktop-Browser ohne Geo-Permission).
<!-- SECTION:NOTES:END -->
