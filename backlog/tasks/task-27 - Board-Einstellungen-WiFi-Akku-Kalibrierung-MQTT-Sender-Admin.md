---
id: TASK-27
title: 'Board-Einstellungen: WiFi, Akku-Kalibrierung, MQTT-Sender (Admin)'
status: Done
assignee: []
created_date: '2026-09-01 07:39'
updated_date: '2026-09-03 18:03'
labels: []
dependencies: []
priority: medium
type: feature
ordinal: 19000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Nutzerwunsch 2026-09-01: mehrere Werte, die aktuell hart im Code/in Dateien stehen, sollen über die Weboberfläche konfigurierbar werden -- Gäste-WLAN-Zugangsdaten (data/guest_wifi.json), Akku-Kalibrierung (zwei Schwellwerte in inc/board.php) und die MQTT-Sender-Zugangsdaten (web/mqtt/index.php, aktuell hartcodierte Konstanten). Nur für Admins, da ein einzelnes geteiltes physisches Gerät betroffen ist, nicht Pro-User-Vorlieben.

Bewusst NICHT Teil dieser Runde (User-Entscheidung, eigene spätere Tasks): Schriftgröße/-art (an Zeichenbudget-Berechnungen für Zeilenumbruch/Kürzung gekoppelt, volle Freitext-Konfiguration würde Textüberlauf riskieren) und Wetterabruf-Takt (aktuell ein echter Cron/launchd-Zeitplan, keine PHP-interne Taktsteuerung).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Neue Tabelle wl_board_settings (Single-Row) über eine nummerierte Migration (migrations/006_...), Defaults so gewählt, dass sich am Verhalten nichts ändert, bevor ein Admin etwas speichert
- [x] #2 Neue Admin-Seite (verlinkt aus Administration ▾, NICHT Usermenü, Suite-Policy §1.2) zum Bearbeiten aller drei Bereiche
- [x] #3 Zugriff nur für rights=Admin, serverseitig zusätzlich abgesichert (nicht nur UI-Ausblendung)
- [x] #4 WiFi-SSID/Passwort: inc/board_guest_wifi.php liest aus der DB statt aus data/guest_wifi.json; leere Werte -> QR-Block entfällt wie bisher
- [x] #5 Akku-Kalibrierung: board_battery_is_charging()/board_battery_display_percent() (inc/board.php) nehmen die zwei Schwellwerte als Parameter statt hartcodiert 95/92, durchgereicht bis board_render_chrome_svg(); Validierung full < charging beim Speichern
- [x] #6 MQTT-Sender-Credentials: web/mqtt/index.php liest User/Passwort aus der DB statt aus den MQTT_USER/MQTT_PASSWORD-Konstanten; beim Speichern eines neuen Passworts wird der echte Broker auf akadbrain synchron mitgeändert (mosquitto_passwd), sonst laufen DB und Broker auseinander und Senden schlägt fehl
- [x] #7 Jede Änderung landet im auth_log (appendLog), wie bei anderen Admin-Aktionen
- [x] #8 Alle bestehenden Tests bleiben grün, neue Tests für die geänderten Funktionssignaturen und den Lesepfad aus der DB
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## 1. Migration: migrations/006_wl_board_settings.sql

Single-Row-Tabelle (id=1 fix), analog zum bestehenden Muster (idempotent,
CREATE TABLE IF NOT EXISTS, kein USE). Passwortfelder starten LEER, nicht mit
dem aktuell hartcodierten Wert -- der wuerde sonst im Migrationsskript und
damit in Git landen (das MQTT-Sender-Passwort steht bereits einmal im Klartext
in web/mqtt/index.php, s. Implementation Notes TASK-26; keine zweite Stelle
dafuer schaffen).

```
CREATE TABLE IF NOT EXISTS wl_board_settings (
  id TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
  wifi_ssid VARCHAR(64) NULL,
  wifi_password VARCHAR(128) NULL,
  battery_charging_threshold TINYINT UNSIGNED NOT NULL DEFAULT 95,
  battery_full_threshold TINYINT UNSIGNED NOT NULL DEFAULT 92,
  mqtt_sender_user VARCHAR(64) NOT NULL DEFAULT 'sender',
  mqtt_sender_password VARCHAR(128) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NULL DEFAULT NULL,
  CHECK (battery_full_threshold < battery_charging_threshold)
);
INSERT IGNORE INTO wl_board_settings (id) VALUES (1);
```

Leeres mqtt_sender_password bedeutet "noch nicht migriert" -- web/mqtt/index.php
faellt in dem Fall auf die alte Konstante zurueck (Uebergangszustand), bis ein
Admin einmal ueber die neue Seite ein echtes Passwort setzt (das rotiert dann
gleichzeitig den Broker, s. Abschnitt 4). Nach dem ersten Setzen wird die
Konstante aus dem Code entfernt.

## 2. inc/board_settings.php (neu)

`board_settings_load(mysqli $con): array` -- liest die eine Zeile, liefert ein
Array mit sinnvollen PHP-Typen (int fuer die Schwellwerte, string|null fuer
wifi/mqtt). Reine Leseseite, analog zu weather_cache/board_guest_wifi_load()
bisher. Einmal pro Request in web/board.php geladen, wie $weather/$mqtt/$calendar
bereits gehandhabt werden.

`board_settings_save(mysqli $con, array $values): void` -- validiert
(full < charging, SSID/User nicht leer wenn Passwort gesetzt), schreibt per
UPDATE (Zeile existiert immer dank INSERT IGNORE in der Migration).

## 3. Admin-Seite web/board_settings.php

Analog zu admin.php (auth_require() + Admin-Check server-seitig, nicht nur
Header::adminItems-Sichtbarkeit). Drei Abschnitte (WiFi, Akku, MQTT-Sender) in
einem Formular oder drei kleinen, wie profil.php es fuer "Abfahrten" macht
(separates appSections-Formular pro Bereich). Passwortfelder: beim Laden LEER
anzeigen (nicht den Bestandswert ausspucken), Platzhaltertext "unveraendert
lassen = leer senden". Jede erfolgreiche Aenderung -> appendLog().

Verlinkung: Header::render()-Aufruf in inc/layout.php bekommt 'adminItems' =>
[['href' => 'board_settings.php', 'label' => 'Board-Einstellungen']] (Suite-
Policy §1.2 -- Administration ▾, nicht Usermenue). isAdmin steuert die
Sichtbarkeit im Chrome bereits automatisch; der Seiten-Zugriff selbst braucht
trotzdem eine eigene serverseitige Pruefung (Suite-Policy-Grundsatz: UI-
Ausblendung ist keine Zugriffskontrolle).

## 4. WiFi: inc/board_guest_wifi.php auf DB umstellen

board_guest_wifi_load() bekommt ein $settings-Array (oder $con) statt den
JSON-Pfad zu lesen. data/guest_wifi.json und guest_wifi.example.json koennen
danach weg -- kurz whitelisten, dass wirklich nichts anderes die Datei noch
liest (nur web/board.php:291 aktuell). Verhalten bei leerer SSID/Passwort
bleibt: kein QR-Block, wie bisher bei fehlender Datei.

## 5. Akku-Kalibrierung: inc/board.php

board_battery_is_charging(int $percent, int $chargingThreshold = 95) und
board_battery_display_percent(int $percent, int $fullThreshold = 92,
int $chargingThreshold = 95) -- Defaultwerte bewusst gleich den bisherigen
Konstanten, damit bestehende Tests, die die Funktionen ohne die neuen Parameter
aufrufen, unveraendert gruen bleiben (gleiches Muster wie board_mqtt_render_svg()s
optionaler $count-Parameter aus dem MQTT-Sender-Task). board_render_chrome_svg()
und ihr Aufrufer board_render_svg() bekommen die zwei Werte als neue Parameter
ans Ende der Signatur angehaengt (bestehende Konvention, s. Kalender/MQTT-
Slots). web/board.php laedt $settings einmal und reicht die zwei Werte durch.

## 6. MQTT-Sender-Credentials: web/mqtt/index.php + Broker-Sync

Die Konstanten MQTT_USER/MQTT_PASSWORD verschwinden aus web/mqtt/index.php,
stattdessen board_settings_load($con) (die Seite hat ohnehin schon initialize.php
+ $con seit der Auth-Aenderung). Fallback auf die alte hartcodierte Kombination
NUR wenn mqtt_sender_password in der DB leer ist (Uebergang, s. Abschnitt 1).

board_settings_save() ruft beim tatsaechlichen AENDERN des MQTT-Passworts
zusaetzlich per SSH/exec auf akadbrain `mosquitto_passwd -b
/opt/homebrew/etc/mosquitto/passwd <user> <neues-passwort>` auf -- DB und
Broker muessen synchron bleiben, sonst sendet die Seite still fehlerhafte
Credentials (mosquitto_pub scheitert, aber die Fehlermeldung sagt nicht warum).
Das ist derselbe exec()-Mechanismus, den web/mqtt/index.php fuer mosquitto_pub
schon nutzt -- kein neuer Rechte-Sprung, nur eine zweite escaped exec()-Zeile.
ACHTUNG: das setzt voraus, dass die PHP-Seite auf DEMSELBEN Host laeuft wie der
Broker (akadbrain) -- lokal/world4you haben keinen Broker, dort muss dieser
Schritt sauber uebersprungen werden (z.B. Config-Flag oder Pruefung auf
Erreichbarkeit von MQTT_PUB_BIN).

## 7. Tests

- Unit: board_settings_load()/save() gegen eine Test-DB (Muster wie bestehende
  DB-gestuetzte Tests, falls vorhanden -- sonst Fixture-Array-basiert wo moeglich).
- Unit: board_battery_is_charging()/display_percent() mit expliziten
  Nicht-Default-Schwellwerten (bestehende Tests decken nur die Defaults ab).
- Integration: board_guest_wifi_load() liest jetzt aus $settings-Array statt
  Datei -- bestehende Tests (falls vorhanden) auf die neue Signatur umstellen.
- Admin-Seite: Zugriff fuer Nicht-Admins muss serverseitig scheitern, nicht nur
  UI-seitig versteckt sein (expliziter Test, kein impliziter).

## Offene Punkte vor Umsetzung

- Reicht ein Klartext-Passwortfeld in der DB fuer WiFi/MQTT, oder soll das
  verschluesselt liegen? DB-Zugriff ist ohnehin durch die App-Credentials
  geschuetzt, kein zusaetzlicher Schutzring aktuell in diesem Projekt ueblich
  (DB-Passwoerter selbst liegen auch nur in config.yaml) -- Vorschlag: Klartext,
  wie der Rest des Projekts es haelt, aber explizit nachfragen falls das nicht
  gewuenscht ist.
- mosquitto_passwd-Sync setzt SSH-Erreichbarkeit von akadbrain UND Ausfuehrung
  im selben Request voraus (kein Async/Queue) -- bei SSH-Ausfall waere die
  Seite kurzzeitig blockierend langsam. Fuer ein Admin-Einstellungsformular,
  das selten benutzt wird, akzeptabel; explizit nennen falls das stoert.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Umgesetzt 2026-09-03. Abweichungen vom Plan:

- wl_board_settings brauchte zusaetzlich wifi_encryption/wifi_hidden (der
  Plan hatte nur SSID+Passwort angenommen -- board_guest_wifi.php liefert
  tatsaechlich vier Felder).
- Board-Einstellungen sind serverspezifisch (feste akadbrain-Pfade fuer
  mosquitto_passwd/aclfile): board_settings_mqtt_broker_reachable() prueft
  is_executable()/is_writable() auf diesen Pfaden und ueberspringt den
  Broker-Sync sauber auf Hosts ohne lokalen Broker (lokal, world4you) --
  kein Config-Flag noetig, das Dateisystem beantwortet die Frage selbst.
- data/guest_wifi.json + guest_wifi.example.json entfernt (DB ist jetzt
  einzige Quelle), inkl. .gitignore-Eintrag und CLAUDE.md-Update.
- Per-Tabelle-Rechtemodell dieses Projekts bedeutete: CREATE TABLE allein
  reicht nicht, der App-DB-User braucht zusaetzlich ein explizites GRANT
  pro Zielumgebung (Migration dokumentiert das jetzt als Kommentar, lokal
  bereits ausgefuehrt).

Nebenbei (nicht Teil von TASK-27, aber im selben Arbeitsgang gefunden und
behoben): Firmware-Boot-Loop durch Stack-Overflow in loopTask waehrend
WiFiManagers AP-Portal-Pfad (Default-Stack 8192 Byte zu knapp) -- Fix per
getArduinoLoopTaskStackSize()-Override auf 16384 Byte, s. epaper-monitor/src/main.cpp.

462 Tests gruen. Noch nicht committet/deployed (Nutzerentscheidung, wartet
auf explizites Signal wie beim Rest der Sitzung).

Audit-Nachlauf 2026-09-03 (Befunde behoben, 466 Tests gruen):

- KRITISCH: board_settings_load() faengt jetzt mysqli_sql_exception ab. Grund: deploy.py fuehrt fuer akadbrain GAR KEINE Migration aus (do_deploy() ruft run_migrations() nur ohne scripts/ssh_deploy.php; deploy_rsync_ssh() delegiert nur bei ftp_base_dir = world4you). Mit MYSQLI_REPORT_STRICT haette die fehlende Tabelle web/board.php auf HTTP 503 geworfen -- das E-Paper-Board waere tot gewesen, mit Log-Eintrag 'Upstream-Fehler', der auf die Wiener Linien zeigt. Migration muss auf akadbrain VON HAND laufen (in der .sql-Datei jetzt dokumentiert).
- KRITISCH: BoardSettingsTest ueberschrieb auf akadbrain echte Broker-Passwoerter (board_settings_mqtt_broker_reachable() ist eine reine Dateirechte-Pruefung; DB rollt zurueck, passwd-Datei nicht). Jetzt skipIfBrokerPresent() mit markTestSkipped().
- HOCH: board_settings_save_wifi() loeschte das WLAN-Passwort bei JEDER Aenderung, obwohl der Platzhalter 'unveraendert lassen = leer senden' verspricht. Jetzt bedingt wie beim MQTT-Sender; NOPASS leert bewusst weiter. Zwei Tests ergaenzt.
- HOCH: appMenu ('eInk Display') und adminItems ('Board-Einstellungen') waren fuer AUSGELOGGTE sichtbar (Chrome\Header filtert nicht nach Rolle -- die Apps muessen das selbst tun, wie biblio/Energie es machen). Jetzt ueber $loggedIn/$isAdmin gegatet, per Render-Test fuer alle drei Rollen geprueft.
- HOCH: Umbenennen des MQTT-Users ohne Passwort liess DB und Broker auseinanderlaufen -> wird jetzt abgelehnt. Beim Umbenennen MIT Passwort wird das alte Broker-Konto per mosquitto_passwd -D entwertet (blieb vorher unbegrenzt gueltig).
- HOCH: Erfolgsmeldungen liefen ueber addAlert(), das diese Seite nie rendert -- sie poppten spaeter kontextlos auf index.php auf. Jetzt PRG-Redirect mit ?saved=… und Rendering auf der Seite selbst.
- MITTEL: Benutzername gegen Option-Parsing validiert (escapeshellarg() schuetzt die Shell, nicht mosquitto_passwds Argument-Parser -- '-c' hiesse dort 'Datei neu anlegen'); Laengenvalidierung fuer SSID/Passwoerter (sonst 'Data too long' -> Fatal); board_settings_ensure_row() gegen 'gespeichert' ohne Speicherung; ?part=monitor reicht jetzt $count durch (Debug-Schnitt zeigte die Seite ohne 'Nachrichten (N)').
- NIEDRIG: declare(strict_types=1) ergaenzt, CSRF-Fehlschlag meldet sich jetzt sichtbar, Eingaben ueberleben Validierungsfehler, autocomplete=off an beiden Passwortfeldern.
- Die Passwortfeld-Assertions in BoardSettingsPageTest waren Scheinsicherheit (Regex konnte bei leerer Testspalte nie matchen) -- jetzt strukturell: das Feld darf gar kein value=-Attribut tragen.

OFFEN (bewusst nicht geaendert): eriks.cloud (ohne www) und suche.eriks.cloud liefern die App aus, stehen aber nicht in AUTH_SSO_ALLOWED_HOSTS -- Erweiterung einer Open-Redirect-Allowlist ist eine Sicherheitsentscheidung fuer den Nutzer. MQTT_PASSWORD_FALLBACK steht weiterhin im Repo und in der Git-Historie.
<!-- SECTION:NOTES:END -->
