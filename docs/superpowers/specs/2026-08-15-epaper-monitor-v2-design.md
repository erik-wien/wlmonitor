# E-Paper-Monitor v2 — neues Panel, serverseitiges Rendering, Wetter

**Stand:** 2026-08-15 · entworfen, nicht umgesetzt
**Umfang:** Hardware-Wechsel, neuer Bild-Vertrag für `web/board.php` (löst den
JSON-Vertrag aus der v1-Spec ab), serverseitige Rendering-Pipeline, Wetter-Integration,
ESP32-Firmware-Neubau
**Ersetzt/erweitert:** `docs/superpowers/specs/2026-08-01-epaper-abfahrtsmonitor-design.md`
(„v1"). v1 ist bereits umgesetzt (`web/board.php`, `inc/board.php` — Token-Auth,
serverseitige Favoritenfilterung, Entdopplung). **§5 „Serverseitige Filterung" und
§6 „monitor_json.php-Härtung" der v1-Spec bleiben unverändert gültig** — diese
Spec ändert nur, *was* `board.php` als Antwort liefert (Bild statt JSON) und *was*
zusätzlich hineinfließt (Wetter). Home Assistant (`monitor_json.php`) ist von
alldem nicht betroffen.
**Hardware:** Seeed Studio 10,3″ Monochrome eInk, 1404×1872 px, TTL, Treiberboard
**EE03** mit **XIAO ESP32-S3 Plus** (löst das Waveshare 7.5″-B-Panel der v1-Spec ab)

---

## 1. Ziel

Zusätzlich zu den Abfahrten (v1) zeigt das Display eine **Wetterkarte** (Icon,
Temperatur von–bis, kurzer Fließtext — heute bis 19:00, danach morgen) und eine
**Statuszeile** (Akku, Uhrzeit, WLAN-Signal). Das neue Panel unterstützt
**Partial Refresh**; das wird genutzt, um bei den meisten Polls nur die
geänderten Minutenzahlen neu zu zeichnen statt das ganze Bild.

Zweites Ziel, das die Umstellung erst ermöglicht: die Firmware verliert ihre
Layout-/Font-Logik (`lib/boardlogic/layout.cpp`, `display.cpp` der v1-Spec
entfallen). Sie wird zu „Bilddaten empfangen, aufs Panel schreiben" reduziert;
das gesamte Rendering wandert auf den Server.

---

## 2. Ausgangslage

v1 ist umgesetzt: `board.php` liefert JSON (Favoriten, Stationen, Linien,
Abfahrten, serverseitig gefiltert und entdoppelt), Token-Auth über
`erikr/auth`, LAN-Listener auf akadbrain (`docs/deploy-board-endpunkt.md`).
Die Firmware existiert als Entwurf für das alte Waveshare-Panel (800×480,
3-Farb, kein Partial Refresh) und rendert Text/Layout selbst über GxEPD2 +
Adafruit_GFX-Fonts.

Zwei unabhängige Entscheidungen lösen diese Spec aus:

1. **Panel-Wechsel** auf das Seeed 10,3″ Monochrome (siehe §4) — ein anderer
   Formfaktor (hochkant-nativ, quer montiert), Partial-Refresh-fähig, aber ohne
   Rot/Graustufen.
2. **Wetter als neue Datenquelle** (ORF, siehe §8) — kein API, HTML-Scraping.

Der Panel-Wechsel allein macht die JSON→Firmware-Rendering-Architektur aus v1
unpraktisch (jede Panel-Bibliothek bräuchte ihre eigene Layout-Portierung in
C++). Deshalb wird das Rendering komplett auf den Server verlagert — das war
für v1 keine Option (kein Partial Refresh, ein Vollbild-Redraw alle 2 Minuten
war ohnehin nötig), wird aber mit dem neuen Panel zum Normalfall.

---

## 3. Entscheidungen im Überblick

| Frage | Entscheidung | Begründung |
|---|---|---|
| Wo wird gerendert? | **Server**, SVG-Template → `rsvg-convert` → 1bpp | Layout als Template lesbarer als GD-Koordinaten-Code; kein Chromium-Prozess nötig; `librsvg` ist bereits im Toolchain (Logo-Pipeline, `epaper-monitor/README.md`) |
| Panel-Bibliothek | **Seeed_GFX** | First-class Partial-Update- und Dual-Buffer-Unterstützung, extra für Seeed-Hardware gebaut (Alternative: Seeed-Fork von GxEPD2) |
| Montage | **Querformat** (Panel physisch gedreht) | Bisherige Zweispalten-Denke bleibt sinnvoll übertragbar; volles Hochformat hätte das Layout stärker umgekrempelt |
| „Live"-Kennzeichnung | **Fett** statt Rot | Panel ist monochrom, kein Rot mehr verfügbar |
| Gestörte Abfahrt | **Invertiert** (weiß auf schwarzem Block) bleibt, wie in v1 — nur ohne Rot | Eigenständiges Signal neben „fett"; Tie-Break wie v1: ist die nächste Abfahrt live *und* gestört, gewinnt die Invertierung |
| Partial-Update-Mechanik | **Server diffed, ETag-Selbstheilung, Zwangs-Vollbild alle 30 Min** (§6) | Firmware bleibt dumm; ETag verhindert stille Drift zwischen Server- und Geräte-Zustand, falls ein Poll ausfällt |
| Abfahrten-Layout | **Eine durchgehende Liste** von Stationskarten (wie `renderMonitor()` im Web-UI), volle Breite, größere Schrift — **keine** festen Favoriten-Spalten mehr | Bei 1470 px Breite verschenkt eine enge Spalte Platz; die Karten-Logik existiert im Web-UI schon |
| Wetterkarte | **Dritte, schmale Spalte** neben der Abfahrtenliste, nicht als Streifen darüber | Nutzt die Breite, die die einspaltige Abfahrtenliste sonst übrig lässt |
| Wetterquelle | `wetter.orf.at/wien/prognose` (Desktop, nicht `/m/`), Station **Wien-Hohe Warte**, **positionale** Auswahl (1./2. Spalte bzw. Textblock) | Robuster als Textmatching auf wechselnde Überschriften („Heute Nachmittag" vs. „Heute, Mariä Himmelfahrt") |
| Wetter-Cutover | **19:00 Wien-Zeit**: davor heute, danach morgen — Icon/Temp *und* Text gemeinsam | Nutzervorgabe |
| Wetter-Abruf | Cron **alle 3 h ab 06:00** (06/09/12/15/18/21 Uhr), Datei-Cache | Wetter ändert sich langsam; schont ORF zusätzlich zum ohnehin unauffälligen `User-Agent: *`-Zugriff laut `robots.txt` |
| Wetter zu alt | **>6 h**: Fließtext wird durch Fehlermeldung ersetzt (Icon/Temp bleiben stehen) | Nutzervorgabe |
| Statuszeile | Server rendert sie mit; Firmware liefert Akkuspannung + RSSI als Request-Header | Konsistent mit „Firmware misst, Server zeichnet" |

---

## 4. Hardware

- **Panel:** Seeed Studio 10,3″ Monochrome eInk/ePaper, 1404×1872 px, TTL-Interface.
  [Produktseite](https://www.seeedstudio.com/10-3inch-Monochrome-eInk-ePaper-Display-with-1404x1872-Pixels-p-6568.html)
- **Treiberboard:** EE03, mit **XIAO ESP32-S3 Plus**, eingebautem T-CON
  (Timing-Kontrolle) und SHT40-Temperatursensor für Waveform-Kompensation.
  [EE03-Übersicht](https://wiki.seeedstudio.com/xiao_epaper_display_board_overview/)
- **Batterie:** EE03 hat JST-2.0-Anschluss + Lade-IC; kein Fuel-Gauge-Chip.
  Spannungsmessung über `ADC_BAT` auf **GPIO10** des XIAO ESP32-S3 Plus
  (Spannungsteiler), ausgelesen per `analogReadMilliVolts()`.
  [Forum-Beleg](https://forum.seeedstudio.com/t/xiao-esp32s3-plus-adc-bat-on-gpio10/291965)
- **Montage:** quer (90° gedreht gegenüber der nativen Hochformat-Ausrichtung
  des Panels) → effektive Zeichenfläche **1872×1404**.
- **Bibliothek:** [Seeed_GFX](https://github.com/Seeed-Studio/Seeed_GFX)
  (`EPaper`-Klasse: Dual-Buffer-Rendering, regionsbasiertes Partial-Update).
- **Pin-Belegung:** kein offener Punkt mehr — der [Seeed_GFX-Online-
  Konfigurationsgenerator](https://seeed-studio.github.io/Seeed_GFX/) erzeugt
  für „XIAO ePaper Display Board EE03" + 10,3″-Panel eine fertige `driver.h`
  mit allen Pin-Definitionen. Das Panel hängt zudem über einen festen
  40-Pin-0,5mm-FPC-Steckverbinder am EE03, keine lose Verdrahtung. Schaltplan-
  PDF und PCBA-Dateien liegen bei Bedarf unter
  `files.seeedstudio.com/wiki/Epaper/EE03/` (verlinkt von der
  [Getting-Started-Seite](https://wiki.seeedstudio.com/getting_started_with_ee03/)).

---

## 5. Architektur — Ablauf je Zyklus

Wie v1: aufwachen → WLAN → HTTP-Request → zeichnen → Tiefschlaf (2 Min). Kein
Dauerbetrieb, RTC-Speicher übersteht Tiefschlaf.

1. Firmware liest Akkuspannung (GPIO10) und WLAN-RSSI (`WiFi.RSSI()`, nach
   Connect verfügbar).
2. `GET /board.php?fav=<ids>`, Header:
   `Authorization: Bearer <token>`,
   `X-Device-Battery-mV: <n>`,
   `X-Device-RSSI: <n>`,
   `If-None-Match: <letzter bekannter ETag>` (leer beim allerersten Poll).
3. Server holt Abfahrten wie in v1 (`inc/board.php`, Filterung/Entdopplung
   unverändert) + liest den Wetter-Cache (`data/weather_cache.json`, §8) +
   verarbeitet die Statuszeilen-Header aus Schritt 2.
4. Server rendert das komplette Board als SVG-Template → `rsvg-convert` → PNG
   → 1bpp-Reduktion (§7).
5. Server vergleicht den neuen Frame mit dem zwischengespeicherten letzten
   Frame für dieses Gerät (Cache-Key: SHA-256 des Tokens + `fav`-Liste, Datei
   in `data/board_state/`):
   - `If-None-Match` passt zum dort gespeicherten ETag **und** der letzte
     Vollbild-Refresh liegt < 30 Min zurück → **Patch**: Bounding-Box aller
     geänderten Pixel + deren Rohdaten.
   - `If-None-Match` fehlt, passt nicht, oder die 30-Min-Grenze ist erreicht,
     oder es gibt noch keinen gespeicherten Zustand → **Vollbild**.
   - Nach dem Senden wird der neue Frame + ETag + Zeitpunkt (bei Vollbild) in
     `data/board_state/<hash>` abgelegt.
6. Firmware (Seeed_GFX) schreibt die empfangenen Pixeldaten in den
   angegebenen Ausschnitt und ruft je nach `X-Board-Mode` ein Partial- oder
   Vollbild-Update auf.

**Kein `$_SESSION`.** Der neue Pro-Gerät-Zustand ist ein Dateicache in `data/`
(analog zu `RATE_LIMIT_FILE`/`data/ratelimit.json`), keine PHP-Session — das
Cookie-Problem aus der v1-Ausgangslage (§2 dort) wird dadurch nicht wieder
eingeführt.

---

## 6. Bild-Protokoll: `web/board.php`

### Anfrage

```
GET /board.php?fav=<id>[,<id>…]
Authorization: Bearer <token>
X-Device-Battery-mV: 4012
X-Device-RSSI: -62
If-None-Match: "a1b2c3…"
```

`fav`, Token-Prüfung, Fehlerkörper bei 401/503/500 bleiben wie in v1 (§4 dort)
— diese Fehler haben weiterhin **keinen** Bildkörper, nur Statuscode +
kleinen JSON-Fehlertext (`{"error":"unauthorized"}` etc.), damit die Firmware
sie ohne Bildparser erkennen kann.

### Antwort (Erfolg, HTTP 200)

Metadaten als Header, Body = rohe Pixeldaten:

```
X-Board-Mode: full | patch
X-Board-ETag: "<sha256 der Frame-Daten>"
X-Board-Generated: 2026-08-15T19:13:47+02:00
X-Board-X: 0            (nur bei patch relevant)
X-Board-Y: 0
X-Board-W: 1872
X-Board-H: 1404
Content-Type: application/octet-stream
Content-Length: <n>
```

Body: **1bpp, MSB-first, zeilenweise, Breite auf ein Vielfaches von 8
aufgerundet** — das native Pufferformat, das Seeed_GFX für Partial-Update-
Aufrufe erwartet. Bei `X-Board-Mode: full` deckt der Body die volle
1872×1404-Fläche ab, bei `patch` nur das per `X-Board-X/Y/W/H` angegebene
Rechteck.

**Wichtig — kein klassisches HTTP-Caching:** `If-None-Match`/`ETag` werden
hier zweckentfremdet. Es gibt bei jedem Poll neue Daten (die Minuten-
Countdowns laufen weiter), ein `304 Not Modified` würde also nie passen. Der
ETag dient ausschließlich dazu, dass der Server erkennt, ob sein
Vorzustand für dieses Gerät noch stimmt, bevor er einen Patch statt eines
Vollbilds schickt.

**Kein separater Fehlerbanner-Header.** Alle Fehlerfälle laufen entweder über
den HTTP-Status ohne Bildkörper (401/503/500) oder werden direkt in die Pixel
gerendert (invertierter Zeitstempel bei veralteten Abfahrtsdaten, Fehlertext
in der Wetterkarte bei veraltetem Wetter-Cache, §8/§11) — die Firmware muss
dafür keinen zusätzlichen Header auswerten.

---

## 7. Rendering-Pipeline

Neue Datei `inc/board_render.php`:

1. SVG-Template (Platzhalter für Stationskarten, Wetterkarte, Statuszeile)
   wird mit den Daten aus `inc/board.php` + `inc/weather.php` + den
   Statuszeilen-Werten befüllt.
2. `rsvg-convert` rendert das SVG zu PNG (1872×1404).
3. ImageMagick `convert … -monochrome` (Schwellwert-Dithering) reduziert auf
   1bpp.
4. Ein kleines PHP-Hilfsstück liest das 1bpp-PNG aus und packt es ins
   Rohformat aus §6 (MSB-first, zeilenweise).
5. Dieselbe Datei (`inc/board_render.php`) übernimmt danach auch den
   Frame-Vergleich aus §5 Schritt 5 (neuer Frame gegen `data/board_state/`,
   ETag-Abgleich, Bounding-Box-Bildung) — Rendern und Diffen sind ein
   zusammenhängender Schritt, kein separates Modul.

Icons (Wetter, Wiener-Linien-Logo) liegen als vorbereitete Bitmaps im
Template, analog zur bestehenden Logo-Konvertierung
(`epaper-monitor/tools/convert_logo.py`).

### Debug-Ausgabe im Browser

`GET /board.php?fav=<ids>&debug=svg` (gleiche Token-Auth wie sonst) liefert
das rohe SVG **vor** der `rsvg-convert`/1bpp-Reduktion, mit
`Content-Type: image/svg+xml` — direkt im Browser-Tab zu öffnen und dort
sogar per DevTools zu inspizieren. `&debug=png` liefert entsprechend das
Zwischenergebnis nach `rsvg-convert`, aber vor der 1bpp-Reduktion (zeigt
Antialiasing/Graustufen, wie es vor dem Schwellwert aussieht). Beide
Debug-Zweige durchlaufen **nicht** die Diff-/Patch- oder ETag-Logik aus §5 —
sie sind ein reiner Rendering-Test, kein Geräte-Zustand. Damit lässt sich das
Template ändern und das Ergebnis sofort per Browser-Reload prüfen, ganz ohne
Gerät oder Flash-Vorgang.

---

## 8. Wetter-Integration

### Scraping (`inc/weather.php`, neu)

- Quelle: `https://wetter.orf.at/wien/prognose` (Desktop-Version).
- Icon + Temperatur: `<tr class="forecastIconRow">` bzw.
  `<tr class="temperatureRow">`, identifiziert über `th.legendCol` mit Text
  „…Wien-Hohe Warte". Erste `<td>` = heute, zweite `<td>` = morgen.
- Text: `.fulltextWrapper` → Paare aus `<h2>` + `<p>`. Erstes Paar = heute,
  zweites = morgen — **positional**, nicht über den Überschriftentext
  geparst (der wechselt je nach Tageszeit/Feiertag: „Heute Nachmittag" vs.
  „Heute, Mariä Himmelfahrt").
- Cutover **19:00 Wien-Zeit**: davor heute-Werte (Icon, Temp, Text), ab 19:00
  morgen-Werte — alle drei Felder wechseln gemeinsam, nicht einzeln.
- **`robots.txt`-Hinweis:** `wetter.orf.at` sperrt benannte KI-Crawler
  (`anthropic-ai`, `ClaudeBot`, `GPTBot` u. a.) vollständig, erlaubt aber
  `User-Agent: *` bis auf `/full` und `/oon/media/`. Der Cron-Scraper tritt
  mit einem gewöhnlichen, nicht als Bot ausgewiesenen UA-String auf und
  ruft nur 6×/Tag ab — das fällt unter den generellen `*`-Eintrag.

### Icon-Mapping

ORF liefert einen 6-stelligen numerischen Code (z. B. `100000` = wolkenlos,
`110000` = leicht bewölkt, `112000` = leicht bewölkt mit Niederschlag). Eigenes
Icon-Set mit 9 Kategorien + Fallback, vorkonvertiert wie das WL-Logo:

klar · leicht bewölkt · bewölkt · bedeckt · Regen leicht · Regen stark ·
Schnee · Gewitter · Nebel · **unbekannt** (Fallback)

Mapping-Tabelle in `inc/weather.php`, startend mit den drei oben belegten
Codes. Ein nicht gemappter Code fällt auf „unbekannt" zurück **und** erzeugt
einen `appendLog()`-Eintrag (Fehler-Regeln-Konvention, wie in v1 für
`board.php`) — so wächst die Tabelle anhand echter Beobachtung, ohne dass
sich das Rendering deswegen sichtbar verhält wie ein Fehler.

### Cache & Abruf-Rhythmus

Neue Datei `scripts/weather_fetch_cron.php`, per Cron **alle 3 h ab 06:00**
(06/09/12/15/18/21 Uhr). Schreibt `data/weather_cache.json`:

```json
{
  "fetched_at": "2026-08-15T15:00:12+02:00",
  "today":    { "icon_code": "100000", "temp_min": 18, "temp_max": 35, "text": "Von früh bis spät …" },
  "tomorrow": { "icon_code": "100000", "temp_min": 22, "temp_max": 37, "text": "Die Hitze steigert sich …" }
}
```

`board.php` liest **ausschließlich** diese Datei — ein Geräte-Poll löst nie
einen ORF-Abruf aus.

### Fehlerfälle

| Lage | Verhalten |
|---|---|
| ORF beim Cron-Lauf nicht erreichbar / Seitenstruktur passt nicht mehr zu den Selektoren | alter Cache-Inhalt bleibt unverändert stehen, `appendLog()` |
| Cache ist **> 6 h** alt | Fließtext wird durch eine Fehlermeldung ersetzt („Wetterbericht veraltet seit HH:MM"); Icon und Temperatur bleiben unverändert stehen |
| Noch nie erfolgreich abgerufen (Erstinbetriebnahme) | Wetterkarte zeigt „Wetter nicht verfügbar" statt Icon/Temperatur/Text |

---

## 9. Layout (1872 × 1404, Querformat)

```
┌────────────────────────────────────────────────────────────────────────────┐
│ ▛▜ WIENER LINIEN                                            Stand 19:13    │  ~60 px
├───────────────────────────────────────────────────────────┬───────────────┤
│  Aßmayergasse                                               │      ☀        │
│  ⬤ 59A 1  Bhf. Meidling S U                     4 · 23      │   18°–35°C    │
│  ◯ 62  1  Lainz, Wolkersbergenstraße             1 · 10     │               │
│                                                               │  Von früh    │
│  Flurschützstraße                                            │  bis spät    │
│  ◯ 62  2  Quartier Belvedere                     2 · 14      │  scheint …   │
│  ⬤ 59A 2  Oper, Karlsplatz U                     ✱ · 3       │               │
│                                                               │               │
│  (eine durchgehende Liste aus Stationskarten, volle Breite,  │               │
│   Reihenfolge wie v1: Favoriten in `sort`-Reihenfolge)       │               │
├───────────────────────────────────────────────────────────┴───────────────┤
│ ▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔  │
│ 🔋 78 %                    19:14                          📶 −62 dBm       │  ~32 px
└────────────────────────────────────────────────────────────────────────────┘
```

**Abfahrtenliste** (ersetzt die v1-Zweispaltenlogik, §7 der v1-Spec): eine
durchgehende Liste von Stationskarten über die volle Breite (~1470 px, Rest
für die Wetterkarte), Karten-Struktur wie `renderMonitor()` im Web-UI
(`web/js/wl-monitor.js:307`) — Stationsname, darunter je Linie ein Eintrag mit
Richtungs-Badge, Ziel, Abfahrtszeiten. **Keine** Bindung an eine bestimmte
Spalte pro Favorit mehr.

**Typografie** (Richtwerte, Feinjustierung beim Rendern):

| Element | v1 (800×480) | v2 (Vorschlag) |
|---|---|---|
| Nächste Abfahrt, live (fett) | 24 pt, rot | 40 pt, **fett**, schwarz |
| Nächste Abfahrt, nur Fahrplan | 24 pt, kursiv | 40 pt, kursiv |
| Folgeabfahrten | 9 pt | 16 pt |
| Linie/Steig/Ziel | 9 pt bold | 16 pt bold |
| Stationsname | 9 pt bold | 18 pt bold |
| gestörte Abfahrt | weiß auf rotem Block | weiß auf **schwarzem** Block (invertiert) |
| `"in": 0` ("fährt jetzt") | `✱`, Zeilenfarbe | `✱`, schwarz |

Tie-Break unverändert aus v1: ist die nächste Abfahrt live *und* gestört,
gewinnt die Invertierung (weiß auf schwarz) vor Fett.

**Wetterkarte** (dritte Spalte, ~350–400 px breit, volle Höhe zwischen Kopf-
und Fußzeile): Icon oben, Temperatur „von–bis" darunter, danach der
Fließtext mit Zeilenumbruch über die volle verfügbare Höhe (kein
Hart-Abschneiden wie im ursprünglich diskutierten Streifen-Layout).

**Statuszeile** (Fußzeile, ~32 px, dünner Strich darüber): Akku-Icon + `%`
links, Uhrzeit mittig, WLAN-Icon + Signalstärke rechts. Die Uhrzeit hier ist
die **Serverzeit beim Rendern** — eine andere Angabe als „Stand HH:MM" in der
Kopfzeile, die weiterhin den Zeitpunkt der WL-Datenabfrage markiert
(Staleness-Anzeige für die Abfahrtsdaten, wie in v1 §7/§9). Beide bewusst
nebeneinander: oben „wie aktuell sind die Daten", unten „läuft das Gerät
gerade".

---

## 10. Firmware

- **Ort/Deploy-Ausschluss:** wie v1 — `firmware/` im wlmonitor-Repo, von
  `scripts/ssh_deploy.php` ausgeschlossen.
- **Bibliothek:** Seeed_GFX statt GxEPD2 (§3, §4).
- **Kein eigenes Layout/Font-Rendering mehr** für den Normalbetrieb — die
  Firmware schreibt nur noch empfangene Pixel-Rechtecke ins Panel. Ausnahme:
  ein **minimaler lokaler Text-Fallback** für den Fall, dass der Server gar
  nicht erreichbar ist (§11) — dafür bleibt eine einzelne kleine Bitmap-Font
  eingebettet, ausschließlich für die Fehlerbanner-Strings.
- **Battery/RSSI:** `analogReadMilliVolts()` auf GPIO10 (`ADC_BAT`),
  `WiFi.RSSI()` nach WLAN-Connect — beide als Request-Header mitgeschickt
  (§6).
- **Kein NTP.** Wie v1: der „Stand"-Zeitstempel kommt aus `X-Board-Generated`;
  die Uhrzeit in der Fußzeile rendert der Server, die Firmware muss dafür
  selbst keine Uhr führen.
- **Zugangsdaten** weiterhin in `firmware/include/config.h` (gitignored).
- **Verbindung:** LAN-Listener auf akadbrain wie v1
  (`docs/deploy-board-endpunkt.md`), Klartext-HTTP, feste IP.
- **Pin-Belegung:** kommt aus der generierten `driver.h` (§4), kein manuelles
  Übernehmen aus dem Schaltplan nötig.

---

## 11. Fehlerfälle

Wie v1 (§9 dort): bei jedem Fehler bleibt das letzte Bild stehen, das Gerät
lügt nie über den Zustand der Daten.

| Lage | Verhalten |
|---|---|
| WLAN nicht verfügbar | Bild bleibt stehen. Nach 3 Fehlversuchen: kleiner lokaler Banner „⚠ offline seit HH:MM" (einzige verbleibende lokale Textausgabe der Firmware) |
| HTTP 401 | lokaler Banner „⚠ Token ungültig" |
| HTTP 503 / Zeitüberschreitung | wie WLAN-Ausfall |
| Antwort unlesbar (Header fehlen, Content-Length passt nicht zum Body) | wie 503, zusätzlich Zähler |
| `X-Board-Generated` älter als 15 Min | wie v1: Zeitstempel-Darstellung invertiert (jetzt: weiß auf schwarz statt weiß auf rot) |
| Wetter-Cache > 6 h alt | Fließtext der Wetterkarte durch Fehlermeldung ersetzt (§8) — passiert serverseitig beim Rendern, keine Firmware-Logik nötig |
| Server erreichbar, aber `If-None-Match` passt nicht zum serverseitigen Zustand | kein Firmware-Fehlerfall — Server erkennt das selbst und schickt automatisch ein Vollbild (§5, §6) |

---

## 12. Tests

- `inc/weather.php` — Parser gegen gespeicherte HTML-Fixtures (Beispielseiten
  von `wetter.orf.at/wien/prognose`): Icon-Code, Temp-Min/Max, Text für
  heute/morgen korrekt extrahiert; unbekannter Icon-Code fällt auf
  „unbekannt" zurück und loggt.
- `inc/weather.php` — Cutover-Logik: vor/nach 19:00 liefert die richtigen
  Werte; Cache-Alter-Schwelle (>6 h → Fehlermeldung statt Text) einzeln
  testbar (reine Funktion, Cache-Inhalt + `now` als Eingabe).
- `inc/board_render.php` — Diff-/Patch-Logik: zwei Frames rein, korrekte
  Bounding-Box raus; ETag-Mismatch löst Vollbild aus; 30-Min-Grenze löst
  Vollbild aus, auch wenn sich nichts geändert hat.
- `web/board.php` — Endpunkttest: Header-Vertrag (`X-Board-*`) bei
  Vollbild/Patch, `Content-Length` stimmt mit Body überein, 401/503 weiterhin
  ohne Bildkörper.
- Firmware (`pio test -e native`): Fallback-Bannerlogik (WLAN-Fehler, 401,
  Zeitüberschreitung) — bleibt größtenteils wie v1, da die restliche
  Board-Logik (`layout.cpp`, `error_state.cpp`) entfällt bzw. stark schrumpft.

---

## 13. Nicht Teil dieses Entwurfs

- Akkubetrieb-Feinschliff (Lade-Charakteristik, Spannungs-zu-Prozent-Kurve
  kalibrieren) — die Statuszeile zeigt einen groben Prozentwert, keine
  präzise Fuel-Gauge-Kalibrierung.
- Umstellung des Web-UI auf die serverseitige Favoritenfilterung — bereits in
  v1 §11 zurückgestellt, hier unverändert.
- Manuelle Auslöser (Taster) — wie v1, weiterhin nicht vorgesehen.
- Eigene Wartung/Erweiterung der ORF-Icon-Code-Tabelle über die drei
  Startwerte hinaus — wächst anhand geloggter unbekannter Codes, kein
  vollständiges Reverse-Engineering des ORF-Codesystems im Rahmen dieser Spec.
