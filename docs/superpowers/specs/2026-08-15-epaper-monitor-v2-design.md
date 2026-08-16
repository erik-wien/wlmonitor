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
Aufrufe erwartet. Bit-Konvention: **1 = Weiß, 0 = Schwarz**. Bei
`X-Board-Mode: full` deckt der Body die volle 1872×1404-Fläche ab, bei
`patch` nur das per `X-Board-X/Y/W/H` angegebene Rechteck.

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
2. `rsvg-convert` rendert das SVG zu PNG (1872×1404) — auf dem Zielsystem
   vorhanden (`brew install librsvg`, siehe `epaper-monitor/README.md`).
3. PHP/GD (kein ImageMagick — auf dem Zielsystem nicht installiert, `ext-gd`
   dagegen bereits vorhanden) liest das PNG, wendet einen **harten
   Schwellwert** pro Pixel an (Luminanz < 128 → Schwarz, sonst Weiß) und
   packt direkt ins Rohformat aus §6 (MSB-first, zeilenweise, Breite auf ein
   Vielfaches von 8 aufgerundet). Bewusst **kein** Error-Diffusion-Dithering:
   das würde Text-/Icon-Kanten körnig statt scharf machen — auf einem
   200-DPI-Monochrom-Panel schlechter lesbar als ein harter Schwellwert.
   Kein Zwischenschritt über eine „1bpp-PNG"-Datei nötig, GD liest das
   Antialiasing-PNG direkt und packt in einem Durchgang.
4. Dieselbe Datei (`inc/board_render.php`) übernimmt danach auch den
   Frame-Vergleich aus §5 Schritt 5 (neuer Frame gegen `data/board_state/`,
   ETag-Abgleich, Bounding-Box-Bildung) — Rendern und Diffen sind ein
   zusammenhängender Schritt, kein separates Modul.

Icons liegen **nicht** als Bitmaps vor, sondern als Vektor-Primitive direkt
im SVG-Template — Wettericons aus einfachen Grundformen (Sonne = Kreis +
Strahlen, Wolke = Outline-Pfad, s. §8), das WL-Logo als eingebettetes
5-Pfad-SVG (s. §9). Das ersetzt die bitmapbasierte Logo-Konvertierung aus
v1 (`epaper-monitor/tools/convert_logo.py`) — auf einem 1-Bit-Panel ohne
Farbverläufe bringt eine Bitmap keinen Vorteil, Vektorform bleibt bei jeder
Neuberechnung scharf und ist im SVG-Template direkt editierbar.

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

- Quelle: `https://wetter.orf.at/wien/prognose` (Desktop-Version). **Achtung:**
  die Desktop-Seite kodiert das Icon als `<span class="weatherIcon c123456">`,
  die mobile `/m/`-Variante dagegen als `<img …/123456.svg>` — der Parser muss
  die Desktop-Form (Span-Klasse `c` + 6 Ziffern) lesen. ORF liefert dieses
  Desktop-Markup auch dem Cron-User-Agent (kein UA-Switch, kein `/m/`-Redirect,
  verifiziert 2026-08-15).
- Icon + Temperatur: `<tr class="forecastIconRow">` bzw.
  `<tr class="temperatureRow">`, identifiziert über `th.legendCol` mit Text
  „…Wien-Hohe Warte". Erste `<td>` = heute, zweite `<td>` = morgen. Icon =
  `span.weatherIcon`-Klasse `c` + 6 Ziffern; Temperatur =
  `span.morning` (Tagestief) / `span.highest` (Tageshoch). **`morning` kann
  beim laufenden Tag fehlen**, sobald das Tief vorbei ist — dann `temp_min =
  temp_max`, kein Fehler.
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

ORF liefert einen 6-stelligen numerischen Code (am 15.8.2026 real beobachtet:
`100000` = wolkenlos, `110000` = leicht bewölkt, `112000` = leicht bewölkt mit
Niederschlag, `122000` = stark bewölkt mit starkem Niederschlag, `122001` =
stark bewölkt mit starkem Niederschlag und Gewitter). Eigenes Icon-Set mit 9
Kategorien + Fallback, vorkonvertiert wie das WL-Logo:

klar · leicht bewölkt · bewölkt · bedeckt · Regen leicht · Regen stark ·
Schnee · Gewitter · Nebel · **unbekannt** (Fallback)

Mapping-Tabelle in `inc/weather.php`, startend mit den fünf oben belegten
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

Pixelgenau durch iteratives Rendern über die echte Pipeline (`svg_to_png()` +
`png_to_1bpp_packed()`, SVG → rsvg-convert → GD-Schwellenwert → visuelle
Kontrolle) erarbeitet, mit den beiden echten Favoriten „Westbahnhof"
(1 Station) und „Nach Hause" (3 Stationsgruppen) verifiziert. Alle Werte
unten sind verbindlich, nicht Richtwerte — jede Zahl wurde am gerenderten
Bild nachgemessen (Pixelscan über `imagecolorat()`), nicht geschätzt.

```
┌────────────────────────────────────────────────────────────────────────────┐
│ ▛▜ WIENER LINIEN                                            Stand 19:13    │  90 px
├───────────────────────────────────────────────────────────┬───────────────┤
│  WESTBAHNHOF S U                                             │      ☀        │
│  ⬤18 1  Schlachthausgasse U                        7 · 22   │   18°–35°C    │
│  ⬤ 6 1  Geiereckstraße                             1 · 14   │  Heute        │
│  ⬤ 9 2  Gersthof S                                 9 · 16   │  Von früh bis │
│  ▪U3 1  Simmering                                  ✱ · 8    │  spät scheint │
│  ▪U6 1  Floridsdorf                                ✱ · 6    │  die Sonne…   │
│  ▪U6 2  Siebenhirten                               5 · 12   │               │
├───────────────────────────────────────────────────────────┴───────────────┤
│ ▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔  │
│ 🔋 78 %                    19:14                          📶 (nur Balken)  │  94 px
└────────────────────────────────────────────────────────────────────────────┘
```

### Kopfzeile (0–90 px)

- Trennlinie bei y=90 (2px), darunter/-über nichts weiter.
- Echtes WL-Logo (`assets/img/wl-logo.svg`, 5 Pfade — Hintergrund, weißes
  Innenfeld, Möwe-Zeichen in zwei Teilen, Wortmarke „WIENER LINIEN" als
  eigener Pfad). Alle 5 Pfade sind Pflicht — fehlt die Wortmarke, bleibt nur
  ein schwarzes Rechteck (beobachteter Fehler beim manuellen Kopieren).
  Eingebettet via `<g transform="translate(24,12) scale(0.5025)">`
  (Quell-viewBox 561,3×131,6).
- „Stand HH:MM" rechtsbündig: `x=1857 y=60`, 39px fett (Zeitpunkt der
  WL-Datenabfrage, s. Staleness-Hinweis unten).

### Trennlinie Abfahrten|Wetter

Vertikal bei `x=1113` (2px), volle Höhe zwischen Kopf- und Fußzeile.
Beide Spalten halten **≥30px Abstand** zu dieser Linie (nicht nur zum
Canvas-Rand!) — Abfahrten-Inhalt endet bei `x=1083` (33px Abstand),
Wetter-Inhalt beginnt bei `x=1150` (37px Abstand).

### Abfahrtenliste (links, x=16–1083)

Ersetzt die v1-Zweispaltenlogik (§7 der v1-Spec): eine durchgehende Liste
aus Stationsblöcken. Jeder Block hat eine ALL-CAPS-Kopfzeile (Stationsname)
und darunter eine Zeile pro Linie/Richtung. Bei mehrstationigen Favoriten
(z. B. „Nach Hause": Bhf. Meidling S U, Siebenhirten, Vösendorf-SCS) folgt
Block auf Block ohne zusätzliche Trennung außer dem normalen Abstand —
**keine** übergreifende Favoriten-Überschrift, wie im Web-UI.

**Vertikales Raster** — alles über eine Cursor-Regel aus zwei Konstanten
hergeleitet (60 %/30 % von 96px Zeilenraster, s. u.):

1. Kopfzeile-Trennlinie (y=90) bzw. das untere Ende des vorigen Blocks ist
   der Cursor.
2. **Vor** jedem Stationskopf: 58px (60 % von 96) Abstand vom Cursor bis zum
   **Cap-Top** des Kopftexts (nicht bis zur Baseline!). Cap-Top wird mit dem
   ungünstigsten Fall (Umlaut-Trema, 48px ab Baseline) berechnet, damit die
   Zeile bei jedem Stationsnamen gleich viel Luft hat, unabhängig davon, ob
   er ein Ü/Ä/Ö enthält — Ausschuss "schwankt" sonst mit dem Textinhalt.
   Kopftext: 55px fett, ALL-CAPS.
3. **Nach** dem Stationskopf: 29px (30 % von 96) Abstand von der
   Kopf-Baseline bis zum **höchsten sichtbaren Element der ersten Zeile**.
   Das ist bei Standard-Badges (68px hoch) IMMER das Badge, nie der
   Fahrtrichtung-Text (37px Cap-Höhe) — ein früherer Entwurfsfehler maß
   diesen Abstand am Text und ließ das Badge kollisionsnah an den
   Stationsnamen heranrücken.
4. **Zeilenraster:** 96px von Badge-Mitte zu Badge-Mitte, solange Zeilen
   derselben Station folgen.
5. Cursor nach dem letzten Element eines Blocks = Badge-Unterkante der
   letzten Zeile (`R+34`) — Ausgangspunkt für den nächsten Stationskopf.

**Zeilen-interne Ausrichtung** — alle Elemente einer Zeile werden auf die
**optische Mitte des Badges** zentriert (Baseline = `R + capHeight/2`,
gemessene Cap-Höhen pro Schriftgröße: 55px→37, 46px→31,5, 32px→22, 26px→18,
22px→15). Das ist bewusst **nicht** dieselbe Baseline für alle Elemente —
unterschiedliche Schriftgrößen haben unterschiedliche Cap-Höhen, eine
gemeinsame Baseline sieht bei stark unterschiedlichen Größen "hochgezogen"
statt zentriert aus. Bei R = Badge-Mitte (`translate(54,R)`):

| Element | x | Baseline-y | Schrift |
|---|---|---|---|
| Badge-Label (Liniennummer, weiß im Badge) | 54 (mittig) | `R+9` | 26px fett |
| Steig-Nummer | 110 | `R+8` | 22px fett |
| Fahrtrichtung | 145 | `R+19` | 55px |
| Live-Abfahrt (Zahl oder `✱`) | 1000 (rechtsbündig) | `R+16` | 46px fett |
| Trennpunkt „·" | 1015 | `R+7` | 20px |
| Folgeabfahrt | 1083 (rechtsbündig) | `R+11` | 32px |

Unter jeder Zeile ein 1px-Strich (`x=16` bis `x=1083`) bei `R+48`.

**Badges** (4 Typen, monochrom, keine Farbe — Unterscheidung ausschließlich
über Form):

| Typ | Form |
|---|---|
| `metro` | gefülltes Quadrat 68×68 |
| `tram` | gefüllter Kreis, r=34 |
| `bus` | gefülltes Rechteck 68×68, rx=14 |
| `train` | **ungefülltes** Rechteck 68×68 rx=14, 5px schwarzer Rand (einzige Outline-Form) |

WLB (Wiener Lokalbahnen) normalisiert über `board_type()` auf `tram` →
Kreis-Badge mit Label „WLB" (24px statt 26px, da 3-stellig).

**Typografie-Sonderfälle:**
- `"in": 0` ("fährt jetzt") → `✱` an Stelle der Live-Abfahrtszahl, gleiche
  46px/fett-Formatierung.
- Gestörte Abfahrt (`delayed`): weiß auf schwarzem Block (invertiert,
  Tie-Break wie v1 — Invertierung schlägt Fett, falls beides zuträfe).
- Alles einfarbig Schwarz auf Weiß — keine Graustufen, keine Farbe.

### Wetterkarte (rechts, x=1150–1856)

Icon oben, `scale(1.8)`, zentriert um `x=1492`, darunter Temperatur „von–bis"
zentriert (40px fett). Danach Überschrift „Heute" (30px fett) und
Fließtext (39px, Zeilenumbruch von Hand an der verfügbaren Spaltenbreite
ausgerichtet — kein automatischer Textumbruch in SVG).

**Icon-Set** — alle 9 Kategorien aus §8 + Fallback, aus denselben
Grundformen (Kreis, Wolken-Outline-Pfad, Linien) gebaut, am gerenderten
Bild abgenommen. Jedes Icon ist in einem lokalen Koordinatenraum von ca.
-34..34 zentriert (gleiche Bounding-Box wie die Zeilen-Badges), damit alle
bei `scale(1.8)` gleich groß wirken. Verbindlicher `<defs>`-Block:

```svg
<g id="sun">
  <circle cx="0" cy="0" r="16" fill="black"/>
  <g stroke="black" stroke-width="4">
    <line x1="0" y1="-26" x2="0" y2="-34"/><line x1="0" y1="26" x2="0" y2="34"/>
    <line x1="-26" y1="0" x2="-34" y2="0"/><line x1="26" y1="0" x2="34" y2="0"/>
    <line x1="-18" y1="-18" x2="-24" y2="-24"/><line x1="18" y1="-18" x2="24" y2="-24"/>
    <line x1="-18" y1="18" x2="-24" y2="24"/><line x1="18" y1="18" x2="24" y2="24"/>
  </g>
</g>
<path id="cloudOutline" d="M -32,14 A 14,14 0 0 1 -20,-6 A 18,18 0 0 1 14,-14 A 16,16 0 0 1 32,4
         A 11,11 0 0 1 30,26 L -26,26 A 11,11 0 0 1 -32,14 Z"
      fill="white" stroke="black" stroke-width="5" stroke-linejoin="round"/>
<path id="cloudFilled" d="M -32,14 A 14,14 0 0 1 -20,-6 A 18,18 0 0 1 14,-14 A 16,16 0 0 1 32,4
         A 11,11 0 0 1 30,26 L -26,26 A 11,11 0 0 1 -32,14 Z"
      fill="black"/>

<g id="icon_klar">
  <use href="#sun"/>
</g>
<g id="icon_leicht_bewoelkt">
  <use href="#sun" transform="translate(-7,-17) scale(0.55)"/>
  <use href="#cloudOutline" transform="translate(5,7)"/>
</g>
<g id="icon_bewoelkt">
  <use href="#cloudOutline" transform="translate(-7,-5) scale(0.8)"/>
  <use href="#cloudOutline" transform="translate(6,9)"/>
</g>
<g id="icon_bedeckt">
  <use href="#cloudOutline" transform="scale(1.12)"/>
</g>
<g id="icon_regen_leicht">
  <use href="#cloudOutline" transform="translate(0,-8)"/>
  <g stroke="black" stroke-width="4" stroke-linecap="round">
    <line x1="-14" y1="22" x2="-19" y2="34"/>
    <line x1="0"   y1="22" x2="-5"  y2="34"/>
    <line x1="14"  y1="22" x2="9"   y2="34"/>
  </g>
</g>
<g id="icon_regen_stark">
  <use href="#cloudFilled" transform="translate(0,-10) scale(1.05)"/>
  <g stroke="black" stroke-width="4" stroke-linecap="round">
    <line x1="-20" y1="20" x2="-26" y2="35"/>
    <line x1="-9"  y1="20" x2="-15" y2="35"/>
    <line x1="2"   y1="20" x2="-4"  y2="35"/>
    <line x1="13"  y1="20" x2="7"   y2="35"/>
    <line x1="24"  y1="20" x2="18"  y2="35"/>
  </g>
</g>
<g id="icon_schnee">
  <use href="#cloudOutline" transform="translate(0,-8)"/>
  <g stroke="black" stroke-width="3" stroke-linecap="round">
    <g transform="translate(-16,27)">
      <line x1="-6" y1="0" x2="6" y2="0"/><line x1="0" y1="-6" x2="0" y2="6"/>
      <line x1="-4.2" y1="-4.2" x2="4.2" y2="4.2"/><line x1="-4.2" y1="4.2" x2="4.2" y2="-4.2"/>
    </g>
    <g transform="translate(0,33)">
      <line x1="-6" y1="0" x2="6" y2="0"/><line x1="0" y1="-6" x2="0" y2="6"/>
      <line x1="-4.2" y1="-4.2" x2="4.2" y2="4.2"/><line x1="-4.2" y1="4.2" x2="4.2" y2="-4.2"/>
    </g>
    <g transform="translate(16,27)">
      <line x1="-6" y1="0" x2="6" y2="0"/><line x1="0" y1="-6" x2="0" y2="6"/>
      <line x1="-4.2" y1="-4.2" x2="4.2" y2="4.2"/><line x1="-4.2" y1="4.2" x2="4.2" y2="-4.2"/>
    </g>
  </g>
</g>
<g id="icon_gewitter">
  <use href="#cloudFilled" transform="translate(0,-12) scale(1.05)"/>
  <polygon points="2,10 -10,26 -1,26 -6,42 10,22 0,22 6,10" fill="black"/>
</g>
<g id="icon_nebel">
  <g stroke="black" stroke-width="5" stroke-linecap="round">
    <line x1="-30" y1="-18" x2="30" y2="-18"/>
    <line x1="-22" y1="-6"  x2="30" y2="-6"/>
    <line x1="-30" y1="6"   x2="22" y2="6"/>
    <line x1="-18" y1="18"  x2="30" y2="18"/>
  </g>
</g>
<g id="icon_unbekannt">
  <circle r="26" fill="white" stroke="black" stroke-width="5"/>
  <text x="0" y="11" font-family="Atkinson Hyperlegible" font-weight="bold" font-size="34"
        fill="black" text-anchor="middle">?</text>
</g>
```

**Kategorie → Icon-Id** (Eingabe ist `icon_category` aus
`weather_map_icon_code()`, s. §8):

| `icon_category` | Icon-Id |
|---|---|
| `klar` | `icon_klar` |
| `leicht_bewoelkt` | `icon_leicht_bewoelkt` |
| `bewoelkt` | `icon_bewoelkt` |
| `bedeckt` | `icon_bedeckt` |
| `regen_leicht` | `icon_regen_leicht` |
| `regen_stark` | `icon_regen_stark` |
| `schnee` | `icon_schnee` |
| `gewitter` | `icon_gewitter` |
| `nebel` | `icon_nebel` |
| `unbekannt` (auch: jeder nicht in dieser Tabelle gelistete Wert) | `icon_unbekannt` |

`WEATHER_ICON_CATEGORIES` (§8) kennt heute erst 5 ORF-Codes (die restlichen
4 Kategorien plus deren Codes wachsen bei Beobachtung, wie in §8
beschrieben) — die Icon-Zuordnungstabelle hier deckt trotzdem **alle 9**
bereits ab, damit ein künftig neu beobachteter Code sofort ein passendes
Icon hat, sobald er in `WEATHER_ICON_CATEGORIES` ergänzt wird.

### Statuszeile (Fußzeile, 1310–1404, 94px)

Trennlinie bei y=1310. Akku-Icon + `%` links, Uhrzeit mittig (Serverzeit
beim Rendern — bewusst getrennt von „Stand HH:MM" oben, das den Zeitpunkt
der WL-Datenabfrage markiert), WLAN rechts nur als Balken-Icon (kein dBm-Text).

### Referenz-Assets

- `assets/fonts/board/Atkinson-Hyperlegible-{Regular,Bold,Italic,BoldItalic}-102.ttf`
- `assets/img/wl-logo.svg` (5-Pfad-Original, s. o.)

Beide sind zum Zeitpunkt dieser Spec-Version noch **nicht committed** —
Task 1 der Implementierungsplan muss sie ins Repo aufnehmen.

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
- **Verbindung:** LAN-Listener auf akadbrain wie v1
  (`docs/deploy-board-endpunkt.md`), Klartext-HTTP, feste IP.
- **Pin-Belegung:** kommt aus der generierten `driver.h` (§4), kein manuelles
  Übernehmen aus dem Schaltplan nötig.

### Provisionierung: WiFiManager statt `config.h`

**Löst ab:** die bisherige Annahme „WLAN-Zugangsdaten, Token und
Favoriten-IDs fix in `firmware/include/config.h`, vor jedem Flash von Hand
eingetragen" (v1 §8). Grund: pro Gerät neu flashen zu müssen, nur um WLAN,
Token oder Favoriten zu ändern, ist unnötiger Aufwand — und `config.h` mit
Geheimnissen drin ist schon einmal versehentlich gepusht worden
(`epaper-monitor/.gitignore`-Kommentar zu `firmware/*.bin`).

- **Bibliothek:** [WiFiManager](https://github.com/tzapu/WiFiManager)
  (tzapu). Beim allerersten Boot (bzw. wenn keine gespeicherten
  WLAN-Zugangsdaten gefunden werden) spannt das Gerät einen eigenen Access
  Point auf, man verbindet sich damit vom Handy/Laptop, ein Captive Portal
  öffnet automatisch ein Formular.
- **Erweitert um eigene Formularfelder** (`WiFiManagerParameter`, dafür
  vorgesehen): zusätzlich zu SSID/Passwort werden **API-Token** und
  **Favoriten-IDs** (kommagetrennt, wie bisher `BOARD_FAV_IDS`) im selben
  Formular abgefragt — eine Provisionierung für alles, keine zweite
  Konfigurationsebene.
- **Persistenz:** WiFiManager speichert WLAN-Zugangsdaten selbst
  (ESP32-eigener WLAN-Stack, NVS-Flash). Die zusätzlichen Felder (Token,
  Favoriten-IDs) werden im Save-Callback selbst persistiert — über die
  ESP32-`Preferences`-Bibliothek (NVS-Namespace), nicht als Datei. Damit
  überlebt alles denselben Tiefschlaf-/Neustart-Zyklus wie die WLAN-Daten.
- **Neu-Provisionierung ohne Reflash:** GPIO 32/33 (in v1 „für späteres
  Nachrüsten" freigehalten, siehe v1 §8) lösen künftig genau das aus — ein
  langer Tastendruck beim Boot zwingt WiFiManager zurück in den
  Access-Point-Modus, WLAN **und** Token/Favoriten lassen sich so jederzeit
  neu eingeben, ohne einen Rechner mit USB-Kabel zu brauchen.
- **`firmware/include/config.h` bleibt bestehen, aber schrumpft** auf reine
  Infrastruktur-Konstanten, die für alle Geräte gleich und nicht geheim sind:
  `BOARD_HOST`, `BOARD_PORT`, `POLL_INTERVAL_SEC`. WLAN, Token und
  Favoriten-IDs — die tatsächlich pro Gerät unterschiedlichen bzw. geheimen
  Werte — kommen nicht mehr aus dem Quellcode.

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
