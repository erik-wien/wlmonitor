---
id: TASK-28
title: Lösch-X der Board-Nachrichten am Gerät funktionsfähig machen
status: In Progress
assignee: []
created_date: '2026-09-04 05:49'
updated_date: '2026-09-04 19:46'
labels: []
dependencies: []
priority: high
type: bug
ordinal: 20000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Nutzerbefund 2026-09-04: "notizen lassen sich am display nicht wegklicken".

URSACHE (verifiziert; kein Regressionsfall -- die Funktion war nie vollstaendig):

1. web/board.php sendet den Header X-Board-Touch-Zones NUR im Simulator-Zweig
   (debug=png zusammen mit sim=1, Zeilen 316/326/346). Das echte
   Geraeteprotokoll (Binaerpfad ab Zeile 353) sendet ihn nie.

2. Die Firmware kennt dynamische Touch-Zonen ueberhaupt nicht: HEADER_NAMES in
   src/board_client.cpp listet X-Board-Touch-Zones nicht, und mapTouchToZone()
   (lib/boardlogic/touch_zone.cpp) rechnet ausschliesslich mit fester Geometrie
   (Favoritenleiste, Paginierungspille). Gesendet werden nur fav0-2,
   page_prev/page_next und page_N -- ein mqtt_del_ID hat die Firmware noch nie
   geschickt.

Serverseitig ist alles fertig: board_mqtt_touch_zones() berechnet die Zonen,
board.php Zeile 123 verarbeitet mqtt_del_ID korrekt. Es fehlt ausschliesslich
die Uebertragung ans Geraet und die Auswertung dort. Im Browser-Simulator
(debug=ui) funktioniert das Loeschen deshalb, am Geraet nicht.

Die Zonen sind inhaltsabhaengig (Masonry-Umbruch) und NICHT aus einer Formel
ableitbar -- sie muessen vom Server kommen. Gemessen: 6 Nachrichten ergeben
6 Zonen = 359 Byte JSON; bei 20 Nachrichten rund 1,2 kB.

Erfordert einen Reflash.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 web/board.php sendet X-Board-Touch-Zones auch auf dem echten Geraetepfad (nicht nur bei debug=png und sim=1) -- nur auf der MQTT-Seite, damit der Header sonst keine Bytes kostet
- [x] #2 Firmware sammelt den Header (HEADER_NAMES in board_client.cpp) und legt die Zonen im RTC-Speicher ab, wie rtcLastFavoriteCount/rtcLastTotalPages: der Tipp erfolgt nach dem Deep Sleep und muss gegen die Zonen des LETZTEN Abrufs geprueft werden
- [x] #3 Beim Tippen werden zuerst die dynamischen Zonen geprueft, dann die feste Geometrie (mapTouchToZone). Treffer sendet X-Device-Touch: mqtt_del_<id>
- [x] #4 Obergrenze fuer Zonenzahl und id-Laenge in der Firmware, damit ein langer Header keinen festen Puffer sprengt; Ueberzaehlige werden verworfen statt zu ueberlaufen
- [ ] #5 Am ECHTEN Geraet verifiziert: Nachricht antippen laesst sie beim naechsten Bildaufbau verschwinden; Gegenprobe: Tippen daneben loescht nichts
- [x] #6 Firmware neu geflasht; Tests fuer die Zonen-Auswertung ergaenzt (test_touch_zone), alle bestehenden Tests bleiben gruen
<!-- AC:END -->
