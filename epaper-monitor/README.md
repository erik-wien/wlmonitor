# epaper-monitor/

ESP32-Firmware für den E-Paper-Abfahrtsmonitor (Waveshare 7.5″ e-Paper HAT
(B), 800×480). Spec:
`../docs/superpowers/specs/2026-08-01-epaper-abfahrtsmonitor-design.md`.

## Bauen

```bash
brew install platformio librsvg   # einmalig
pio run -e esp32dev               # nur kompilieren
pio test -e native                # reine Logik testen (kein Geraet noetig)
```

## Konfigurieren

```bash
cp include/config.example.h include/config.h
```

Dann in `config.h` eintragen: WLAN-Zugangsdaten, ein frisches API-Token aus
`profil.php` → Abschnitt „API-Token" (auf akadbrain — **nie** ein Token
wiederverwenden, das schon einmal im Klartext aufgetaucht ist, z. B. in
einem Chat), die Favoriten-IDs (aus der URL von `editFavorite.php?id=…`,
Reihenfolge = Spaltenreihenfolge).

`config.h` ist gitignored und geht nie ins Repo.

## Flashen

```bash
pio run -e esp32dev -t upload
pio device monitor
```

## Logo neu erzeugen

Nur nötig, wenn sich das Wiener-Linien-Logo ändert:

```bash
curl -sL "https://upload.wikimedia.org/wikipedia/commons/5/59/Wiener_Linien_logo.svg" -o /tmp/wl-logo.svg
rsvg-convert -w 960 -h 224 /tmp/wl-logo.svg -o /tmp/wl-logo.png
python3 tools/convert_logo.py /tmp/wl-logo.png src/logo.h
```

## Panel-Treiber

`src/panel_select.h` legt die GxEPD2-Treiberklasse fest
(`epd3c/GxEPD2_750c_Z08.h`). Vor dem ersten Flashen gegen die
Waveshare-Wiki-Seite des eigenen Moduls bzw. die Rückseiten-Beschriftung
prüfen — es gibt mehrere GxEPD2-Klassen für ähnlich benannte 7,5″-B-Panel-
Revisionen (u. a. `epd3c/GxEPD2_750c_GDEW075Z08.h`,
`gdey3c/GxEPD2_750c_GDEY075Z08.h`).
