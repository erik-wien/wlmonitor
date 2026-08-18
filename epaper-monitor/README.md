# epaper-monitor/

ESP32-S3-Firmware für den E-Paper-Abfahrtsmonitor (Seeed reTerminal E1003,
1872×1404, 16-Graustufen-fähig — diese Firmware rendert vorerst 1bpp, s.
Spec §6/§13). Spec:
`../docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md`.

## Bauen

```bash
brew install platformio   # einmalig
pio run -e esp32dev               # nur kompilieren
pio test -e native                # reine Logik testen (kein Geraet noetig)
```

## Erstes Flashen — IMMER zuerst den Bring-up-Test

Vor dem vollen Firmware-Flash: prüft `[env:bringup]`, ob das Panel
grundsätzlich anspricht, unabhängig von WLAN/Touch/HTTPS:

```bash
pio run -e bringup -t upload
pio device monitor
```

Zeigt „HELLO E1003" auf dem Panel? Dann weiter mit dem vollen Flash unten.
Zeigt sich nichts: erst Verkabelung/Pins/GxEPD2-Panelklasse prüfen (s.
`src/display.cpp`s Kommentare), bevor an der restlichen Firmware gesucht wird.

## Konfigurieren — kein config.h mehr

**Keine Geheimnisse im Quellcode.** WLAN-Zugangsdaten und das API-Token
werden beim ersten Boot über einen WiFiManager-Captive-Portal eingegeben:

1. Gerät flashen (unten), erstes Boot ohne gespeichertes WLAN → Gerät
   spannt einen eigenen Access Point `wlmonitor-setup` auf.
2. Mit Handy/Laptop verbinden, das sich automatisch öffnende Formular
   ausfüllen: WLAN-SSID/Passwort **und** ein frisches API-Token aus
   `profil.php` → Abschnitt „API-Token" (auf akadbrain — **nie** ein Token
   wiederverwenden, das schon einmal im Klartext aufgetaucht ist, z. B. in
   einem Chat).
3. Gerät verbindet sich, pollt `https://wlmonitor.eriks.cloud/board.php`
   direkt (kein `fav`-Parameter mehr — Favoriten kommen serverseitig aus
   dem Token).

**Neu-Provisionierung ohne Reflash:** grüne Refresh-/Wake-Taste (GPIO3)
3 Sekunden beim Boot gedrückt halten → Gerät geht zurück in den
Access-Point-Modus, WLAN und Token lassen sich neu eingeben.

`include/board_config.h` ist **committed** (keine Geheimnisse mehr darin —
nur `BOARD_HOST`/`BOARD_PORT`/`POLL_INTERVAL_SEC`, gleich für jedes Gerät).

## Flashen (volle Firmware)

```bash
pio run -e esp32dev -t upload
pio device monitor
```

## Kein Hardware-Zugriff beim Schreiben dieser Firmware

Diese Firmware wurde ohne physisches reTerminal E1003 geschrieben (Plan:
`../docs/superpowers/plans/2026-08-17-epaper-firmware-e1003.md`). Folgende
Annahmen sind **unverifiziert** und müssen beim ersten echten Hardwaretest
geprüft werden:

- Display-Panel-Name (Spec: „ED103TC2"; die reale, installierte GxEPD2-Klasse
  `GxEPD2_it103_1872x1404` nennt ihr Panel „ES103TC1" — Controller (IT8951)
  + Auflösung (1872×1404) + Größe (10,3″) passen exakt, der Namensunterschied
  ist wahrscheinlich eine Waveshare-/Good-Display-Namensvariante derselben
  Panel-Familie, aber ohne Hardware nicht zu 100% auszuschließen).
- **DC-Pin für das ePaper-SPI-Interface** (`src/display.cpp`/`hw_bringup.cpp`,
  `EPD_DC_PIN=-1`) — die Seeed-Wiki-Pinliste kennt keinen DC-Pin für die
  E1003-ePaper-Schnittstelle; Quellcode-Analyse der echten Bibliothek zeigt,
  dass der Treiber `_dc` nirgends referenziert (IT8951 nutzt SPI-Präambel-
  Worte statt einer DC-Leitung) — starkes Indiz für „-1 ist richtig", aber
  vor dem ersten Flash gegen Schaltplan/Seeeds eigene Firmware gegenchecken.
- GT911-Touch-Registerprotokoll (`src/touch.cpp`) — aus Wiki-Beispielcode
  transkribiert, nicht aus verifizierter Bibliotheks-API.
- Touch-Rotation (`rawX`/`rawY` → Panel-Koordinaten in `src/touch.cpp`) —
  höchstes Risiko in diesem Plan, braucht echten Touch-Test.
- KEY1/KEY2-Tastenrollen (`src/buttons.cpp`) — nur KEY0 (Wake) ist bestätigt.
- Batterie-mV-Skalierungsfaktor (`src/battery.cpp`) — `*2`-Divisor-Frage
  ungeklärt, siehe Kommentar dort.

**Bereits gegen echten Bibliotheks-Quellcode verifiziert** (nicht mehr auf
der obigen Liste, s. Task 5): `GxEPD2_it103_1872x1404`-Klassenname und
-API-Form, die `invert=false`-Bit-Konvention für den Pixel-Blit, und dass
`selectSPI()` von diesem Treiber ignoriert wird (SPI-Pins müssen direkt auf
das globale `SPI`-Objekt gebunden werden).
