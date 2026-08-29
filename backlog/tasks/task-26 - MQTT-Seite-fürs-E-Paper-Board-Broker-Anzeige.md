---
id: TASK-26
title: MQTT-Seite fürs E-Paper-Board (Broker + Anzeige)
status: Done
assignee: []
created_date: '2026-08-29 06:24'
updated_date: '2026-08-29 07:23'
labels: []
dependencies: []
priority: medium
ordinal: 18000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Nutzerwunsch 2026-08-29: MQTT-Broker auf akadbrain installieren, Board zeigt empfangene MQTT-Nachrichten auf einer eigenen 5. Seite (wie Kalender). Quelle: manuell/aus Skripten publizierte Nachrichten, kein bestimmtes Smart-Home-System dahinter.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Mosquitto-Broker laeuft auf akadbrain, nur ueber localhost+Tailscale erreichbar (nicht oeffentlich), mit Authentifizierung
- [ ] #2 Persistenter Subscriber-Prozess schreibt empfangene Nachrichten atomar in einen Cache (Muster wie weather_fetch_cron.php/calsync.swift)
- [ ] #3 Board zeigt die letzten N Nachrichten (Topic, Text, Alter) auf einer eigenen Seite
- [ ] #4 Pagination-Pille bekommt eine 5. Kategorie 'mqtt' mit eigenem Icon
- [ ] #5 Leerer Cache zeigt einen freundlichen Hinweis statt einer leeren Flaeche
- [ ] #6 Tests fuer Rendering + Seitenreihenfolge, alle bestehenden Tests bleiben gruen
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Architekturentscheidung: server-seitiger Subscriber, KEIN MQTT im Firmware-Client

Das Geraet schlaeft fast permanent (Deep Sleep zwischen HTTP-Polls, Batteriebetrieb).
Eine dauerhafte MQTT-Verbindung direkt im ESP32 wuerde dieses Powerbudget komplett
aushebeln -- MQTT ist fuer staendig verbundene Clients gedacht, nicht fuer "kurz
aufwachen, pollen, schlafen". Stattdessen exakt das etablierte Muster von
weather_fetch_cron.php/calsync.swift: ein SERVERSEITIGER Prozess haengt dauerhaft
am Broker, schreibt jede Nachricht in einen Cache; board.php liest nur die
Cache-Datei, das Geraet bleibt bei reinem HTTP-Poll wie bisher. Kein Firmware-Client
noetig, kein Reflash fuer dieses Feature.

## 1. Broker: Mosquitto auf akadbrain

- `brew install mosquitto`.
- NICHT oeffentlich erreichbar: der Cloudflare-Tunnel proxied nur HTTP/HTTPS, MQTT
  bleibt aussen vor. Bind auf 127.0.0.1 + Tailscale-Interface (100.82.138.121) --
  Publizieren von aussen laeuft ueber Tailscale, denselben Weg, den diese Sitzung
  schon fuer SSH genutzt hat.
- Authentifizierung per `password_file` (ein Nutzer), NICHT anonymous -- ein offener
  Broker im Tailnet liesse jedes Geraet im selben Netz beliebige Nachrichten
  einspeisen.
- launchd-Service fuer Autostart (System-Daemon, braucht keine GUI-Session wie
  calsync -- reines Netzwerk-I/O, kein EventKit/TCC involviert).

## 2. Subscriber: persistenter Prozess, kein Cron

- Python + `paho-mqtt` statt PHP/Swift: PHP hat keine ausgereifte Async-MQTT-Lib,
  ein periodischer PHP-Cron-Job wuerde Nachrichten zwischen zwei Laeufen verpassen
  (MQTT ist push/pub-sub, kein Pull wie die WL-API).
- Abonniert ein festes Topic-Prefix (z.B. `wlmonitor/board/#`), schreibt jede
  Nachricht (Topic, Payload, empfangen_at) atomar (tmp+rename) in
  `data/mqtt_cache.json` -- gleiches Muster wie weather_fetch_cron.php/calsync.swift.
- Ringpuffer: nur die letzten N Nachrichten behalten (z.B. 20), sonst waechst die
  Datei unbegrenzt.
- launchd-LaunchAgent/Daemon mit `KeepAlive` statt `StartInterval` (calsync) --
  das hier ist ein Dauerlaufprozess, kein periodischer Task. Reconnect-Logik noetig
  (MQTT-Verbindung kann abreissen).

## 3. PHP-Rendering: inc/board_mqtt.php

- Reine Funktionen wie inc/board_calendar.php: board_mqtt_load()/-select_display()/
  -render_svg(). Kein $con, kein Netz, keine date()/time()-Aufrufe direkt.
- Zeigt die letzten N Nachrichten (Topic + Text + Alter "vor Xmin"), neueste oben,
  Ueberlauf abgeschnitten mit "…" (Muster wie board_sleep_fit_lines()).
- Leerer Cache -> freundlicher Hinweis statt leerer Flaeche (Kalender-Vorbild).
- Payload ist FREMDGESTEUERTER Text (jeder mit Broker-Zugang kann publizieren) --
  htmlspecialchars() Pflicht, wie bei Kalender-Titeln.

## 4. Verdrahtung als 5. Kategorie

- board_pagination_categories() (inc/board_template.php) um 'mqtt' erweitern.
  Reihenfolge: Monitor -> Stoerung -> Kalender -> MQTT -> Wetter (Wetter/
  Schlafschirm bleibt strukturell IMMER die letzte Seite, s. bestehender
  Kommentar in board_total_pages()).
- board_pagination_icon_svg(): neues Icon fuer 'mqtt' (z.B. Sprechblase),
  selber Stil wie die bestehenden 4 (Tabler-inspiriert, handgezeichnet).
- board_total_pages()/board_render_svg(): dritten->vierten optionalen Slot
  ergaenzen, exakt nach dem Kalender-Vorbild (hasMqtt analog hasCalendar).
- web/board.php: alle DREI unabhaengigen Dispatch-Stellen mitziehen (Haupt-Pfad,
  ?part=monitor, hasCalendar/hasMqtt-Seitenmathematik) -- dieselbe Falle wie beim
  Kalender-Feature, dort bereits dreimal gefunden.

## 5. Tests

- Unit: board_pagination_categories() mit hasMqtt, board_mqtt_render_svg() leer/
  gefuellt/ueberlaufend, Escaping von Payload-Text.
- Integration: Seitenreihenfolge inkl. MQTT-Slot, X-Board-Total-Pages korrekt.

## Offene Punkte, die VOR der Umsetzung entschieden werden sollten

- Topic-Schema: reicht ein einzelnes Prefix (`wlmonitor/board/#`), oder soll es
  mehrere logische Kanäle geben?
- Nachrichten-Ringpuffergroesse (N) und ob alte Nachrichten nach einer gewissen
  Zeit verschwinden sollen (TTL) oder nur nach Anzahl rotieren.
- Soll das MQTT-Passwort im selben ~/.config/wlmonitor/-Schema liegen wie
  calsync.env, oder woanders?
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Nachtrag 2026-08-29 (Post-it-Feinschliff, alles live auf akadbrain):
- Drei Spalten randbuendig 16..1856 (3x586 + 2x41), Masonry: jede Karte in die gerade kuerzeste Spalte.
- Kartenhoehe waechst mit der Textmenge, Mindesthoehe 280 damit ein Einzeiler ein Zettel bleibt und kein Streifen.
- Post-it-Optik: umgeknickte Ecke unten rechts + Schatten. Die Knick-Ecke war zuerst spiegelverkehrt gezeichnet (rechter Winkel nach aussen) und sah aus wie der Zipfel einer Sprechblase -- korrigiert, Regressionstest board_mqtt_fold_path() vorhanden.

ENTSCHEIDUNG Graustufen (Nutzer 2026-08-29: 'bleib bei 1bpp'): Das E1003-Panel kann GRAY_LEVEL16 (Hardware-Referenz Paragraph 5.4), unsere Firmware rendert aber 1 bpp (EPD_COLOR_DEPTH 1). png_to_1bpp_packed() schwellt hart bei Luminanz 128 OHNE Dithering -- ein echter Grauwert kippt am Geraet komplett auf Weiss oder Schwarz. Der Schatten ist deshalb ein Schwarz-Weiss-Schachbrettraster (50%, 2px-Kachel): aus Zimmerentfernung ein Grau, fuer den 1bpp-Wandler bereits fertig geschwellte Pixel. Durch die ECHTE Pipeline geprueft (SVG->PNG->png_to_1bpp_packed->zurueck sichtbar gemacht), der Schatten ueberlebt als helles Grau.
Echtes 4bpp waere ein eigenes Vorhaben: 1,3 MB statt 328 KB pro Vollbild, Umbau von Packer/Diff/Protokoll UND Firmware-Anzeigepfad plus Reflash, dazu deutlich langsamerer Graustufen-Refresh. Bewusst NICHT gemacht.

432 Tests gruen.

Nachtrag 2026-08-29 (Sende-Seite): Kollege hatte per Perplexity ein
Client-seitiges MQTT-Sende-Tool (Browser -> WebSocket -> Broker) gebaut,
sollte unter wlmonitor.eriks.cloud/mqtt live gehen. Zwei Probleme beim
Review gefunden: (1) Payload-Feldnamen "Titel"/"Text" statt der von
board_mqtt_parse_payload() erwarteten "title"/"body" -- waere als
Rohtext ohne Titel auf dem Board gelandet. (2) Broker-Passwort hartcodiert
im Browser-JS, dazu ws:// von einer https-Seite aus (Mixed-Content-Block,
haette nirgendwo funktioniert).

Stattdessen web/mqtt/index.php: eigenstaendige PHP-Seite (kein Login, kein
Menue-Eintrag, .no-ui-rules), die serverseitig auf akadbrain per
mosquitto_pub an 127.0.0.1:1883 publiziert -- kein Browser-MQTT, kein
wss/TLS noetig. Eigener Broker-User "sender" mit write-only ACL nur auf
wlmonitor/board/message (/opt/homebrew/etc/mosquitto/aclfile auf
akadbrain, acl_file-Zeile in mosquitto.conf ergaenzt, einmaliger
Broker-Neustart). Verifiziert: sender kann senden, nicht lesen; der
bestehende "wlmonitor"-Lese-User (mqtt_subscriber.py) bleibt unveraendert
und empfaengt weiterhin normal. End-to-End live getestet (Formular ->
PHP -> Broker -> data/mqtt_cache.json).
<!-- SECTION:NOTES:END -->
