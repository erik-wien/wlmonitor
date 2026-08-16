# E-Paper-Abfahrtsmonitor — Design

**Stand:** 2026-08-16 · entworfen, teilweise umgesetzt (Rendering-Template
in Arbeit, siehe `docs/superpowers/plans/2026-08-16-board-svg-template.md`)
**Umfang:** Hardware, Bild-Protokoll für `web/board.php`, serverseitige
Rendering-Pipeline (SVG → 16-Graustufen-Bild), Touch-Navigation
(Favoritenwechsel, Seiten, Störungen), Wetter-Integration, ESP32-Firmware

---

## 1. Ziel

Ein wandmontierter E-Paper-Monitor zeigt Wiener-Linien-Abfahrten für bis zu
drei Favoriten (umschaltbar per Touch), eine Wetterkarte und — bei Bedarf —
aktuelle Störungsmeldungen. Sämtliches Rendering (Layout, Schrift, Icons)
passiert **serverseitig**: die Firmware bekommt fertige Pixeldaten und
schreibt sie aufs Panel, ohne selbst Text oder Layout zu kennen. Das Panel
unterstützt **Partial Refresh** und **16 Graustufen**; beides wird genutzt
— Partial Refresh für die meisten Polls (nur geänderte Minutenzahlen),
Graustufen für nicht-Echtzeit-Abfahrten und die Touch-Bedienelemente.

---

## 2. Entscheidungen im Überblick

| Frage | Entscheidung | Begründung |
|---|---|---|
| Wo wird gerendert? | **Server**, SVG-Template → `rsvg-convert` → Graustufen-Rohformat | Layout als Template lesbarer als GD-Koordinaten-Code; kein Chromium-Prozess nötig; `librsvg` ist bereits im Toolchain |
| Panel-Bibliothek | **Seeed_GFX** | First-class Partial-Update-, Dual-Buffer- und Graustufen-Unterstützung, für Seeed-Hardware gebaut |
| Montage | **Querformat** (Panel physisch gedreht) | Zweispalten-Layout (Abfahrten/Wetter) bleibt sinnvoll übertragbar |
| „Live"-Kennzeichnung | **Fett**, Schwarz | Panel ist monochrom/graustufig, kein Rot verfügbar |
| Nur Fahrplan (kein Echtzeit-Datensatz) | **Mittleres Grau**, aufrecht (nicht kursiv) | Nutzt die neu verfügbaren Graustufen für einen dritten, weniger dringlichen Zustand |
| Gestörte Abfahrt | **Invertiert** (weiß auf schwarzem Block) | Alarm-Signal, muss sich von „nur Grau" klar abheben — Tie-Break: Invertierung schlägt „nur Fahrplan" |
| Partial-Update-Mechanik | **Server diffed, ETag-Selbstheilung, Zwangs-Vollbild alle 30 Min** | Firmware bleibt dumm; ETag verhindert stille Drift zwischen Server- und Geräte-Zustand, falls ein Poll ausfällt |
| Favoriten am Gerät | **Touch-Leiste mit den ersten 3 Favoriten** (nach `sort`) des Geräte-Nutzers, Single-Select | Eigener wlmonitor-Nutzer pro Gerät; Umschalten wie im Web-UI, aber auf das Wesentliche reduziert |
| Mehr Inhalt als eine Seite | **Kanonische Pagination** (Pfeile + Seitenzahlen, aktuelle Seite als gefüllter Kreis) am Ende der Abfahrtenspalte | Bekanntes UI-Muster, per Touch **und** über zwei dedizierte Hardware-Tasten am Gerät bedienbar |
| Störungen | **Eigene Seite(n)** nach den Abfahrten-Seiten des aktiven Favoriten, gefiltert auf dessen Linien/Stationen | Nutzt `monitor_get()`s bereits vorhandenes `alerts`-Feld, bisher ungenutzt |
| Abfahrten-Layout | **Eine durchgehende Liste** von Stationskarten, volle Spaltenbreite, große Schrift | Bei ~1067px Breite verschenkt eine enge Spalte Platz |
| Wetterkarte | **Dritte, schmale Spalte**, bleibt beim Blättern statisch | Nutzt die Breite, die die einspaltige Abfahrtenliste sonst übrig lässt; unabhängig vom gewählten Favoriten |
| Wetterquelle | `wetter.orf.at/wien/prognose` (Desktop, nicht `/m/`), Station **Wien-Hohe Warte**, **positionale** Auswahl (1./2. Spalte bzw. Textblock) | Robuster als Textmatching auf wechselnde Überschriften |
| Wetter-Cutover | **19:00 Wien-Zeit**: davor heute, danach morgen — Icon/Temp *und* Text gemeinsam | Nutzervorgabe |
| Wetter-Abruf | Cron **alle 3 h ab 06:00** (06/09/12/15/18/21 Uhr), Datei-Cache | Wetter ändert sich langsam |
| Wetter zu alt | **>6 h**: Fließtext wird durch Fehlermeldung ersetzt (Icon/Temp bleiben stehen) | Nutzervorgabe |
| Statuszeile | In die Kopfzeile integriert (Uhrzeit zentriert, Akku+WLAN rechts); Fußzeile gehört jetzt der Touch-Leiste | Die Fußzeile wird für die Favoriten-Buttons gebraucht |

---

## 3. Hardware

**Gerät:** [Seeed Studio reTerminal E1003](https://wiki.seeedstudio.com/getting_started_with_reterminal_e1003/)
— ein fertiges, gehäustes Komplettgerät (kein Selbstbau aus Einzelteilen).

- **Display:** 10,3″ Monochrom-ePaper, Panel ED103TC2, Controller **IT8951**,
  Auflösung **1404×1872 px** (nativ Hochformat), **16-stufige Graustufen**,
  Vollbild-Refresh ca. 3 s.
- **Montage:** quer (90° gedreht gegenüber der nativen Ausrichtung) →
  effektive Zeichenfläche **1872×1404**. Gerät liefert einen eigenen
  3D-gedruckten Standfuß mit Montagebohrungen auf der Rückseite (Wandmontage
  möglich).
- **MCU:** ESP32-S3R8 (WiFi/BLE dual-mode), 8 MB OPI-PSRAM, 32 MB Flash.
  Framebuffer (~321 kB) liegt in PSRAM.
- **Touch:** kapazitiv, Controller **GT911** (Goodix), I²C0, Adresse `0x5D`
  oder `0x14` (Auto-Erkennung). Pins: SDA=GPIO19, SCL=GPIO20, INT=GPIO2,
  RESET=GPIO48 (Bus geteilt mit RTC/Sensor). Bibliothek: `Seeed_GFX`
  (`mapTouchToDisplay()`, `readTouchPoint()`).
- **Physische Tasten:** grüne „Refresh"-Taste (oben, weckt aus Tiefschlaf,
  GPIO3/KEY0) + zwei weiße Seiten-Navigationstasten (oben) — deren genaue
  GPIO-Zuordnung wird bei der Firmware-Implementierung aus der
  Seeed_GFX-Beispielfirmware für E1003 übernommen, nicht hier vorab geraten.
  Dazu ein rückseitiger Netzschalter, rote Lade-LED, grüne Status-LED.
- **Batterie:** 3000 mAh, Laden über USB-C (5V/1A). Bis zu 6 Monate Laufzeit
  bei einem Refresh/Tag (Herstellerangabe). Wie genau der Ladezustand
  softwareseitig ausgelesen wird (Fuel-Gauge vs. Spannungsteiler), klärt
  sich bei der Firmware-Implementierung anhand der Seeed_GFX-Beispiele.
- **Sensoren:** Temperatur, Luftfeuchtigkeit (ungenutzt in dieser Spec).
- **Erweiterungs-Header (J2, 6-polig):** 3,3V, GND, GPIO47 (analog),
  GPIO6 (analog), GPIO20 (I²C SCL), GPIO19 (I²C SDA) — wird für diese Spec
  nicht gebraucht, alle nötigen Peripherien (Display, Touch, Tasten) sind
  bereits intern verdrahtet.
- **WLAN:** 2,4 GHz 802.11 b/g/n (kein 5 GHz).
- **Bibliothek:** [Seeed_GFX](https://github.com/Seeed-Studio/Seeed_GFX) —
  Dual-Buffer-Rendering, regionsbasiertes Partial-Update, Graustufen-Modus
  (`initGrayMode(GRAY_LEVEL16)`), Touch-Abfrage. Arduino-kompatibel
  programmierbar.

---

## 4. Architektur — Ablauf je Zyklus

Aufwachen (Timer **oder** Tastendruck/Touch) → WLAN → HTTP-Request →
zeichnen → Tiefschlaf. Kein Dauerbetrieb, RTC-Speicher übersteht Tiefschlaf.

1. Firmware liest Batteriestand und WLAN-RSSI (`WiFi.RSSI()`, nach Connect
   verfügbar). Falls das Aufwachen durch Touch oder eine der beiden
   Seiten-Tasten ausgelöst wurde, wird das erkannte Ereignis gemerkt
   (Favoriten-Button 0/1/2, „Seite zurück", „Seite vor").
2. `GET /board.php`, Header:
   `Authorization: Bearer <token>`,
   `X-Device-Battery-mV: <n>`,
   `X-Device-RSSI: <n>`,
   `X-Device-Touch: <fav0|fav1|fav2|page_prev|page_next>` (nur bei
   Touch-/Tasten-Auslösung, sonst weggelassen),
   `If-None-Match: <letzter bekannter ETag>` (leer beim allerersten Poll).
   Kein `fav`-Parameter mehr nötig — der Server ermittelt die Favoriten des
   Geräte-Nutzers direkt aus dem Token.
3. Server lädt die Favoriten des Token-Nutzers (`favorites_get()`), nimmt
   die ersten drei nach `sort` als Touch-Leisten-Kandidaten. Anhand des
   gespeicherten Geräte-Zustands (`data/board_state/<hash>`, Schlüssel =
   SHA-256 des Tokens) und eines eventuellen `X-Device-Touch` bestimmt er
   den aktiven Favoriten-Index (0/1/2, Default 0) und die aktive Seite
   (Default 1) — `page_prev`/`page_next` verschieben die Seite,
   `fav0`/`fav1`/`fav2` wechseln den Favoriten und setzen die Seite auf 1
   zurück.
4. Server holt Abfahrten (`inc/board.php`, `board_favorite()`, Filterung/
   Entdopplung wie gehabt) für den aktiven Favoriten, liest den
   Wetter-Cache (`data/weather_cache.json`, §7) und — falls die aktive
   Seite eine Störungsseite ist — die zu den Linien/Stationen des aktiven
   Favoriten passenden Einträge aus `monitor_get()['alerts']`.
5. Server rendert das komplette Board als SVG-Template → `rsvg-convert` →
   16-Graustufen-Rohformat (§6).
6. Server vergleicht den neuen Frame mit dem zwischengespeicherten letzten
   Frame für dieses Gerät:
   - `If-None-Match` passt zum gespeicherten ETag **und** der letzte
     Vollbild-Refresh liegt < 30 Min zurück **und** weder Favorit noch
     Seite haben gewechselt → **Patch**: Bounding-Box aller geänderten
     Pixel + deren Rohdaten.
   - Sonst (ETag passt nicht, 30-Min-Grenze erreicht, Favoriten-/
     Seitenwechsel, oder noch kein gespeicherter Zustand) → **Vollbild**.
     Ein Favoriten- oder Seitenwechsel ändert praktisch die ganze Fläche —
     die allgemeine Diff-Logik erkennt das ohnehin von selbst über die
     Bounding-Box, es braucht keinen Sonderfall.
   - Nach dem Senden wird der neue Frame + ETag + Zeitpunkt (bei Vollbild)
     + aktiver Favoriten-Index + aktive Seite in `data/board_state/<hash>`
     abgelegt.
7. Firmware (Seeed_GFX) schreibt die empfangenen Pixeldaten in den
   angegebenen Ausschnitt und ruft je nach `X-Board-Mode` ein Partial- oder
   Vollbild-Update auf.

**Kein `$_SESSION`.** Der Pro-Gerät-Zustand ist ein Dateicache in `data/`
(analog zu `RATE_LIMIT_FILE`/`data/ratelimit.json`), keine PHP-Session.

**Bekannte Einschränkung:** der Cache-Key (SHA-256 des Tokens) setzt genau
ein Gerät pro Token voraus — zwei Geräte mit demselben Token würden sich
Favoriten-Index und Seitenzustand teilen. Für den vorgesehenen
Einsatz (ein Gerät, ein eigener wlmonitor-Nutzer) unproblematisch, aber
nicht Teil dieser Spec, mehrere Geräte pro Token zu unterstützen.

---

## 5. Bild-Protokoll: `web/board.php`

### Anfrage

```
GET /board.php
Authorization: Bearer <token>
X-Device-Battery-mV: 4012
X-Device-RSSI: -62
X-Device-Touch: fav1
If-None-Match: "a1b2c3…"
```

Token-Prüfung und Fehlerkörper bei 401/503/500 bleiben unverändert — diese
Fehler haben **keinen** Bildkörper, nur Statuscode + kleinen JSON-Fehlertext
(`{"error":"unauthorized"}` etc.), damit die Firmware sie ohne Bildparser
erkennen kann.

### Antwort (Erfolg, HTTP 200)

Metadaten als Header, Body = rohe Pixeldaten:

```
X-Board-Mode: full | patch
X-Board-ETag: "<sha256 der Frame-Daten>"
X-Board-Generated: 2026-08-16T19:13:47+02:00
X-Board-X: 0            (nur bei patch relevant)
X-Board-Y: 0
X-Board-W: 1872
X-Board-H: 1404
Content-Type: application/octet-stream
Content-Length: <n>
```

Body: **Graustufen-Rohformat, das native Pufferformat, das Seeed_GFX für
Partial-Update-Aufrufe erwartet** (16 Stufen/4 Bit pro Pixel — exakte
Byte-Packung wird bei der Firmware-Implementierung anhand der
Seeed_GFX-Quelle verifiziert, analog zur bereits verifizierten
1bpp-Packung aus einer früheren Rendering-Iteration, s. §6). Bei
`X-Board-Mode: full` deckt der Body die volle 1872×1404-Fläche ab, bei
`patch` nur das per `X-Board-X/Y/W/H` angegebene Rechteck.

**Kein klassisches HTTP-Caching:** `If-None-Match`/`ETag` werden hier
zweckentfremdet. Es gibt bei jedem Poll neue Daten (Minuten-Countdowns
laufen weiter), ein `304 Not Modified` würde also nie passen. Der ETag
dient ausschließlich dazu, dass der Server erkennt, ob sein Vorzustand für
dieses Gerät noch stimmt, bevor er einen Patch statt eines Vollbilds
schickt.

**Kein separater Fehlerbanner-Header.** Alle Fehlerfälle laufen entweder
über den HTTP-Status ohne Bildkörper (401/503/500) oder werden direkt in
die Pixel gerendert (invertierter Zeitstempel bei veralteten
Abfahrtsdaten, Fehlertext in der Wetterkarte bei veraltetem Wetter-Cache,
§7/§11) — die Firmware muss dafür keinen zusätzlichen Header auswerten.

---

## 6. Rendering-Pipeline

Neue Datei `inc/board_render.php` (Grundfunktionen bereits vorhanden:
`svg_to_png()`, `png_to_1bpp_packed()`):

1. SVG-Template wird mit den Daten aus `inc/board.php` + `inc/weather.php`
   + den Statuszeilen-/Touch-Werten befüllt (`inc/board_template.php`,
   in Arbeit, s. Implementierungsplan).
2. `rsvg-convert` rendert das SVG zu PNG (1872×1404).
   **Font-Einbindung:** `rsvg-convert` nutzt system-installierte Schriften
   über Fontconfig, nicht `<link>`/`@font-face`. `svg_to_png()` setzt dafür
   die Umgebungsvariable `FONTCONFIG_FILE` als expliziten `$env`-Parameter
   an `proc_open()` (nicht global via `putenv()`, sonst verschmutzt der
   Wert den Prozess über den Aufruf hinaus) auf eine zur Laufzeit erzeugte
   Fontconfig-XML, die auf `assets/fonts/board/` verweist.
3. PHP/GD (kein ImageMagick — auf dem Zielsystem nicht installiert, `ext-gd`
   dagegen vorhanden) liest das PNG und packt es fürs Panel:
   - **1bpp-Pfad** (`png_to_1bpp_packed()`, bereits implementiert und
     getestet): harter Schwellwert pro Pixel (Luminanz < 128 → Schwarz,
     sonst Weiß), MSB-first, zeilenweise. Bleibt als einfachster,
     verifizierter Pfad bestehen, falls sich das 4bpp-Graustufenformat als
     unpraktikabel erweist oder für Debug-Zwecke ein reiner Schwarz/Weiß-
     Vergleich gebraucht wird.
   - **4bpp-Graustufen-Pfad** (neu, für den eigentlichen Board-Betrieb):
     quantisiert jeden Pixel anhand seiner Luminanz auf einen von 16
     Grautönen (0=Schwarz…15=Weiß, nächstliegende Stufe), gepackt nach dem
     Pufferlayout, das Seeed_GFX/IT8951 für Graustufen-Schreibvorgänge
     erwartet — die exakte Byte-Anordnung ist beim jetzigen Planungsstand
     nicht verifiziert und wird beim Firmware-/Protokoll-Task anhand der
     Seeed_GFX-Quelle geklärt, nicht geraten.
   Bewusst **kein** Error-Diffusion-Dithering: das würde Text-/Icon-Kanten
   körnig statt scharf machen.
4. Dieselbe Datei übernimmt danach auch den Frame-Vergleich aus §4 Schritt 6
   (neuer Frame gegen `data/board_state/`, ETag-Abgleich,
   Bounding-Box-Bildung) — Rendern und Diffen sind ein zusammenhängender
   Schritt, kein separates Modul.

Icons liegen **nicht** als Bitmaps vor, sondern als Vektor-Primitive direkt
im SVG-Template — Wettericons aus einfachen Grundformen (Sonne = Kreis +
Strahlen, Wolke = Outline-Pfad, s. §7), das WL-Logo als eingebettetes
5-Pfad-SVG (s. §9). Auf einem Graustufen-Panel ohne Farbverläufe bringt
eine Bitmap keinen Vorteil, Vektorform bleibt bei jeder Neuberechnung
scharf und ist im SVG-Template direkt editierbar.

### Debug-Ausgabe im Browser

`GET /board.php?debug=svg` (gleiche Token-Auth wie sonst) liefert das rohe
SVG **vor** der `rsvg-convert`/Graustufen-Reduktion, mit
`Content-Type: image/svg+xml` — direkt im Browser-Tab zu öffnen und dort
sogar per DevTools zu inspizieren. `&debug=png` liefert entsprechend das
Zwischenergebnis nach `rsvg-convert`, aber vor der Reduktion (zeigt
Antialiasing, wie es vor der Quantisierung aussieht). Beide Debug-Zweige
durchlaufen **nicht** die Diff-/Patch- oder ETag-Logik aus §4 — sie sind
ein reiner Rendering-Test, kein Geräte-Zustand.

---

## 7. Wetter-Integration

### Scraping (`inc/weather.php`)

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
  geparst (der wechselt je nach Tageszeit/Feiertag).
- Cutover **19:00 Wien-Zeit**: davor heute-Werte (Icon, Temp, Text), ab 19:00
  morgen-Werte — alle drei Felder wechseln gemeinsam, nicht einzeln.
- **`robots.txt`-Hinweis:** `wetter.orf.at` sperrt benannte KI-Crawler
  vollständig, erlaubt aber `User-Agent: *` bis auf `/full` und
  `/oon/media/`. Der Cron-Scraper tritt mit einem gewöhnlichen UA-String
  auf und ruft nur 6×/Tag ab — das fällt unter den generellen `*`-Eintrag.

### Icon-Mapping

ORF liefert einen 6-stelligen numerischen Code (am 15.8.2026 real beobachtet:
`100000` = wolkenlos, `110000` = leicht bewölkt, `112000` = leicht bewölkt mit
Niederschlag, `122000` = stark bewölkt mit starkem Niederschlag, `122001` =
stark bewölkt mit starkem Niederschlag und Gewitter). Eigenes Icon-Set mit 9
Kategorien + Fallback:

klar · leicht bewölkt · bewölkt · bedeckt · Regen leicht · Regen stark ·
Schnee · Gewitter · Nebel · **unbekannt** (Fallback)

Mapping-Tabelle in `inc/weather.php`, startend mit den fünf oben belegten
Codes. Ein nicht gemappter Code fällt auf „unbekannt" zurück **und** erzeugt
einen `appendLog()`-Eintrag — so wächst die Tabelle anhand echter
Beobachtung, ohne dass sich das Rendering deswegen sichtbar verhält wie ein
Fehler.

### Cache & Abruf-Rhythmus

Datei `scripts/weather_fetch_cron.php`, per Cron **alle 3 h ab 06:00**
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

## 8. Störungsmeldungen

Nutzt `monitor_get()['alerts']` (`inc/monitor.php`, bereits vorhanden,
bisher ungenutzt) — Liste realer WL-Störungen mit `title`, `description`,
`priority`, `lines`, `stops`. Für den aktiven Favoriten werden nur die
Einträge übernommen, deren `lines` mindestens eine der Linien des
Favoriten enthalten (Filterung analog zu `board_filter_station()`).

Angezeigt als eigene Seite(n) am Ende der Abfahrten-Seiten des aktiven
Favoriten (Pagination zeigt sie mit), Layout wie eine Abfahrtenzeile:
Titel fett, darunter gekürzter Beschreibungstext (max. 3 Zeilen, „…" bei
Kürzung — reale ORF-Störungstexte können mehrere hundert Zeichen lang
sein). Kein automatischer Zeilenumbruch in SVG, gleiche
Wortumbruch-Logik wie beim Wetter-Fließtext (§9), auf die schmalere
Abfahrtenspalten-Breite angepasst.

Gibt es für den aktiven Favoriten keine passenden Störungen, entfällt die
Störungsseite ersatzlos (keine leere Seite mit „keine Störungen").

---

## 9. Layout (1872 × 1404, Querformat)

Pixelgenau durch iteratives Rendern über die echte Pipeline erarbeitet und
am gerenderten Bild abgenommen — mit echten Favoriten („Westbahnhof",
„Nach Hause") und echten WL-Störungsmeldungen verifiziert. Alle Werte
unten sind verbindlich, nicht Richtwerte.

```
┌────────────────────────────────────────────────────────────────────────────┐
│ ▛▜ WIENER LINIEN            19:14                    ▂▄▆ [🔋78%]           │  90 px
├───────────────────────────────────────────────────────────┬───────────────┤
│  WESTBAHNHOF S U                                             │      ☀        │
│  ⬤18 1  Schlachthausgasse U                        7 · 22   │   18°–35°C    │
│  ⬤ 6 1  Geiereckstraße                             1 · 14   │  Heute        │
│  ⬤ 9 2  Gersthof S (grau, nur Fahrplan)             9 · 16   │  Von früh bis │
│  ▪U3 1  Simmering (gestört, invertiert)             ✱ · 8    │  spät scheint │
│  ▪U6 1  Floridsdorf                                ✱ · 6    │  die Sonne…   │
│  ▪U6 2  Siebenhirten                               5 · 12   │               │
│                                                                │               │
│  Stand 19:13                              ← 1 (2) 3 →       │               │
├───────────────────────────────────────────────────────────┴───────────────┤
│ [ Arbeit ]        [ Nach Hause — aktiv ]        [ Westbahnhof ]            │  84 px
└────────────────────────────────────────────────────────────────────────────┘
```

### Kopfzeile (0–90 px)

- Trennlinie bei y=90 (2px).
- WL-Logo links (`assets/img/wl-logo.svg`, 5 Pfade — Hintergrund, weißes
  Innenfeld, Möwe-Zeichen in zwei Teilen, Wortmarke „WIENER LINIEN" als
  eigener Pfad). Alle 5 Pfade sind Pflicht — fehlt die Wortmarke, bleibt
  nur ein schwarzes Rechteck. **Rein schwarz/weiß eingebettet**, nicht in
  den Original-Markenfarben — bei 16 Graustufen würden Rot (`#e3000f`) und
  Dunkelblau (`#240c4b`) sonst zu uneinheitlichen mittleren Grautönen
  quantisiert statt sauber Schwarz zu bleiben. Eingebettet via
  `<g transform="translate(24,12) scale(0.5025)">` (Quell-viewBox
  561,3×131,6).
- Server-Renderzeit zentriert: `x=936 y=55`, 34px fett (Uhrzeit, zu der
  dieses Bild gerendert wurde — nicht zu verwechseln mit „Stand HH:MM" am
  Ende der Abfahrtenspalte, das den Zeitpunkt der WL-Datenabfrage markiert,
  s. u.).
- Akku + WLAN rechts, **eine Zeile**, rechtsbündig auf `x=1856`: WLAN-Balken
  (`translate(1665,46)`, 3 Balken wie gehabt), dann Akku-Icon
  (`translate(1713,42)`, Umriss + Füllbalken proportional zum Ladestand,
  Füllbreite `max(2, round(48 · Prozent/100))`), dann „Prozent %"-Text
  rechtsbündig bei `x=1856 y=63`, 24px fett.

### Trennlinie Abfahrten|Wetter

Vertikal bei `x=1113` (2px), volle Höhe zwischen Kopf- und Fußzeile.
Beide Spalten halten **≥30px Abstand** zu dieser Linie — Abfahrten-Inhalt
endet bei `x=1083` (33px Abstand), Wetter-Inhalt beginnt bei `x=1150`
(37px Abstand).

### Abfahrtenliste (links, x=16–1083)

Eine durchgehende Liste aus Stationsblöcken. Jeder Block hat eine
ALL-CAPS-Kopfzeile (Stationsname) und darunter eine Zeile pro
Linie/Richtung. Bei mehrstationigen Favoriten folgt Block auf Block ohne
zusätzliche Trennung außer dem normalen Abstand — keine übergreifende
Favoriten-Überschrift.

**Vertikales Raster** — über eine Cursor-Regel aus zwei Konstanten
hergeleitet (60 %/30 % von 96px Zeilenraster):

1. Kopfzeile-Trennlinie (y=90) bzw. das untere Ende des vorigen Blocks ist
   der Cursor.
2. **Vor** jedem Stationskopf: 58px (60 % von 96) Abstand vom Cursor bis
   zum **Cap-Top** des Kopftexts (nicht bis zur Baseline!). Cap-Top wird
   mit dem ungünstigsten Fall (Umlaut-Trema, 48px ab Baseline) berechnet,
   damit die Zeile bei jedem Stationsnamen gleich viel Luft hat. Kopftext:
   55px fett, ALL-CAPS.
3. **Nach** dem Stationskopf: 29px (30 % von 96) Abstand von der
   Kopf-Baseline bis zum **höchsten sichtbaren Element der ersten Zeile**
   — bei Standard-Badges (68px hoch) IMMER das Badge, nie der
   Fahrtrichtung-Text (37px Cap-Höhe).
4. **Zeilenraster:** 96px von Badge-Mitte zu Badge-Mitte, solange Zeilen
   derselben Station folgen.
5. Cursor nach dem letzten Element eines Blocks = Badge-Unterkante der
   letzten Zeile (`R+34`) — Ausgangspunkt für den nächsten Stationskopf.

**Zeilen-interne Ausrichtung** — alle Elemente einer Zeile werden auf die
**optische Mitte des Badges** zentriert (Baseline = `R + capHeight/2`,
gemessene Cap-Höhen pro Schriftgröße: 55px→37, 46px→31,5, 32px→22, 26px→18,
22px→15). Bei R = Badge-Mitte (`translate(54,R)`):

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
| `train` (auch Fallback für `other`) | **ungefülltes** Rechteck 68×68 rx=14, 5px schwarzer Rand |

WLB (Wiener Lokalbahnen) normalisiert über `board_type()` auf `tram` →
Kreis-Badge mit Label „WLB" (24px statt 26px, da 3-stellig).

**Zeilen-Zustände** (an echten Zeilen durchgerendert und abgenommen —
Formeln beziehen sich auf `R` = Badge-Mitte der jeweiligen Zeile):
- `"in": 0` ("fährt jetzt") → `✱` an Stelle der Live-Abfahrtszahl, gleiche
  Formatierung wie die Zahl.
- **Nur Fahrplan** (`realtime === false`): Live-Abfahrt UND Folgeabfahrt
  in **mittlerem Grau** (`fill="#808080"`), aufrecht — nicht kursiv. Badge,
  Steig-Nummer und Fahrtrichtung bleiben schwarz; nur die Zeitangaben und
  der Trennpunkt werden grau.
- **Gestörte Abfahrt** (`delayed === true`): weiß auf schwarzem Block,
  schlägt „nur Fahrplan"/Grau, falls beides zuträfe (eine gestörte
  Fahrplan-Abfahrt zeigt also fett-weiß auf Schwarz, nicht grau). Rechteck
  `x=950 y=(R-20) width=60 height=42 fill=black`, Live-Abfahrt-Text
  unverändert an Position `x=1000 y=(R+16)`, nur `fill="white"`. Deckt
  ausschließlich die Live-Abfahrt ab, nicht den Trennpunkt oder die
  Folgeabfahrt.

### Stand + Pagination (unteres Ende der Abfahrtenspalte)

- „Stand HH:MM" (Zeitpunkt der WL-Datenabfrage) links, `x=16 y=1286`, 24px.
- **Kanonische Pagination**, nur wenn mehr als eine Seite existiert
  (Abfahrten-Überlauf und/oder Störungsseite): Pille bei `x=793 y=1256`,
  `width=290 height=48 rx=24`, weiß mit 2px schwarzem Rand. Darin (alle
  Elemente vertikal auf die Pillen-Mitte `y=1280` zentriert, Baseline =
  Mitte + halbe Cap-Höhe): Zurück-Pfeil „←", Seitenzahlen als reiner Text
  (schwarz, 24-26px), die **aktive Seite als gefüllter schwarzer Kreis**
  (`r=20`) mit weißer, fetter Zahl darin, Vor-Pfeil „→". Ein Pfeil ohne
  Ziel (erste bzw. letzte Seite) wird **ausgegraut** (`fill="#b0b0b0"`)
  statt weggelassen — die Pille behält so auf jeder Seite dieselbe Breite.
  Bedienbar per Touch **und** über die beiden physischen
  Seiten-Navigationstasten des Geräts (§3).

### Touch-Leiste (Fußzeile, 1310–1404, 84–94px)

Ersetzt die reine Statuszeile — die Statuswerte sind in die Kopfzeile
gewandert (s. o.). Bis zu drei Favoriten-Buttons (erste drei Favoriten des
Geräte-Nutzers nach `sort`), gleich breit über die volle Breite verteilt
(je `602px`, `16px` Randabstand, `16px` Lücke dazwischen), Höhe `74px`,
vertikal zentriert in der Fußzeile (`y=1320` bis `y=1394`), `rx=10`.

- **Aktiver Favorit:** Button gefüllt schwarz, Label weiß, fett, 34px,
  zentriert.
- **Inaktive Favoriten:** Button weiß mit 3px schwarzem Rand, Label
  schwarz.

Antippen wechselt sofort den angezeigten Favoriten (setzt die Seite auf 1
zurück, s. §4).

### Wetterkarte (rechts, x=1150–1856)

Bleibt beim Blättern durch Abfahrten-/Störungsseiten **statisch** — sie
gehört zum Standort, nicht zum gewählten Favoriten oder zur aktiven Seite.

Icon oben, `scale(1.8)`, zentriert um `x=1492`, darunter Temperatur
„von–bis" zentriert (40px fett). Danach Überschrift „Heute" (30px fett)
und Fließtext (39px, Zeilenumbruch programmatisch berechnet — 37
Zeichen/Zeile, hergeleitet aus der verfügbaren Spaltenbreite (706px)
geteilt durch die gemessene mittlere Zeichenbreite bei 39px Atkinson
Hyperlegible (17,37px/Zeichen) mit 8% Sicherheitsabstand).

**Icon-Set** — alle 9 Kategorien aus §7 + Fallback, aus denselben
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
`weather_map_icon_code()`, s. §7):

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

`WEATHER_ICON_CATEGORIES` (§7) kennt heute erst 5 ORF-Codes (die
restlichen 4 Kategorien plus deren Codes wachsen bei Beobachtung) — die
Icon-Zuordnungstabelle hier deckt trotzdem **alle 9** bereits ab.

### Referenz-Assets

- `assets/fonts/board/Atkinson-Hyperlegible-{Regular,Bold,Italic,BoldItalic}-102.ttf`
  (committed)
- `assets/img/wl-logo.svg` (5-Pfad-Original, committed)

---

## 10. Firmware

- **Ort/Deploy-Ausschluss:** `firmware/` im wlmonitor-Repo, von
  `scripts/ssh_deploy.php` ausgeschlossen.
- **Bibliothek:** Seeed_GFX (§3).
- **Kein eigenes Layout/Font-Rendering** für den Normalbetrieb — die
  Firmware schreibt nur empfangene Pixel-Rechtecke ins Panel, liest Touch/
  Tasten aus und meldet sie im nächsten Request (§4). Ausnahme: ein
  **minimaler lokaler Text-Fallback** für den Fall, dass der Server nicht
  erreichbar ist (§11) — dafür bleibt eine einzelne kleine Bitmap-Font
  eingebettet, ausschließlich für die Fehlerbanner-Strings.
- **Touch/Tasten → Request:** Touch-Koordinaten werden serverseitig NICHT
  ausgewertet — die Firmware bildet einen Touch-Punkt selbst auf eine der
  fünf festen Zonen ab (3 Favoriten-Buttons, Pagination-Zurück,
  Pagination-Vor; exakte Pixel-Rechtecke aus §9) und schickt nur das
  Ergebnis (`X-Device-Touch`, §5). Die beiden physischen
  Seiten-Navigationstasten lösen direkt `page_prev`/`page_next` aus, ohne
  Touch-Koordinaten-Mapping.
- **Battery/RSSI:** wird als Request-Header mitgeschickt (§5); genaue
  Auslesemethode (Fuel-Gauge vs. Spannungsteiler) klärt sich bei der
  Implementierung (§3).
- **Kein NTP.** Der „Stand"-Zeitstempel kommt aus `X-Board-Generated`; die
  Uhrzeit in der Kopfzeile rendert der Server, die Firmware muss dafür
  selbst keine Uhr führen.
- **Verbindung:** LAN-Listener auf akadbrain, Klartext-HTTP, feste IP.

### Provisionierung: WiFiManager

Grund: pro Gerät neu flashen zu müssen, nur um WLAN oder Token zu ändern,
ist unnötiger Aufwand.

- **Bibliothek:** [WiFiManager](https://github.com/tzapu/WiFiManager)
  (tzapu). Beim allerersten Boot (bzw. wenn keine gespeicherten
  WLAN-Zugangsdaten gefunden werden) spannt das Gerät einen eigenen Access
  Point auf, man verbindet sich damit vom Handy/Laptop, ein Captive Portal
  öffnet automatisch ein Formular.
- **Erweitert um eigenes Formularfeld** (`WiFiManagerParameter`):
  zusätzlich zu SSID/Passwort wird das **API-Token** abgefragt — kein
  `fav`-Feld mehr nötig, da die Favoriten jetzt serverseitig aus dem Token
  ermittelt werden (§4).
- **Persistenz:** WiFiManager speichert WLAN-Zugangsdaten selbst
  (ESP32-eigener WLAN-Stack, NVS-Flash). Das Token wird im Save-Callback
  über die ESP32-`Preferences`-Bibliothek (NVS-Namespace) persistiert.
- **Neu-Provisionierung ohne Reflash:** langer Druck auf die grüne
  Refresh-Taste beim Boot zwingt WiFiManager zurück in den
  Access-Point-Modus — WLAN **und** Token lassen sich so jederzeit neu
  eingeben, ohne einen Rechner mit USB-Kabel zu brauchen.
- **`firmware/include/config.h` bleibt bestehen, aber schrumpft** auf reine
  Infrastruktur-Konstanten, die für alle Geräte gleich und nicht geheim
  sind: `BOARD_HOST`, `BOARD_PORT`, `POLL_INTERVAL_SEC`. WLAN und Token —
  die tatsächlich pro Gerät unterschiedlichen bzw. geheimen Werte — kommen
  nicht mehr aus dem Quellcode.

---

## 11. Fehlerfälle

Bei jedem Fehler bleibt das letzte Bild stehen, das Gerät lügt nie über
den Zustand der Daten.

| Lage | Verhalten |
|---|---|
| WLAN nicht verfügbar | Bild bleibt stehen. Nach 3 Fehlversuchen: kleiner lokaler Banner „⚠ offline seit HH:MM" (einzige verbleibende lokale Textausgabe der Firmware) |
| HTTP 401 | lokaler Banner „⚠ Token ungültig" |
| HTTP 503 / Zeitüberschreitung | wie WLAN-Ausfall |
| Antwort unlesbar (Header fehlen, Content-Length passt nicht zum Body) | wie 503, zusätzlich Zähler |
| `X-Board-Generated` älter als 15 Min | Zeitstempel-Darstellung invertiert (weiß auf schwarz) |
| Wetter-Cache > 6 h alt | Fließtext der Wetterkarte durch Fehlermeldung ersetzt (§7) — passiert serverseitig beim Rendern, keine Firmware-Logik nötig |
| Server erreichbar, aber `If-None-Match` passt nicht zum serverseitigen Zustand | kein Firmware-Fehlerfall — Server erkennt das selbst und schickt automatisch ein Vollbild (§4, §5) |

---

## 12. Tests

- `inc/weather.php` — Parser gegen gespeicherte HTML-Fixtures: Icon-Code,
  Temp-Min/Max, Text für heute/morgen korrekt extrahiert; unbekannter
  Icon-Code fällt auf „unbekannt" zurück und loggt.
- `inc/weather.php` — Cutover-Logik: vor/nach 19:00 liefert die richtigen
  Werte; Cache-Alter-Schwelle (>6 h → Fehlermeldung statt Text) einzeln
  testbar (reine Funktion, Cache-Inhalt + `now` als Eingabe).
- `inc/board_template.php` — Layout-/Rendering-Funktionen, siehe
  Implementierungsplan (`docs/superpowers/plans/2026-08-16-board-svg-template.md`).
- `inc/board_render.php` — Diff-/Patch-Logik: zwei Frames rein, korrekte
  Bounding-Box raus; ETag-Mismatch löst Vollbild aus; 30-Min-Grenze löst
  Vollbild aus, auch wenn sich nichts geändert hat; Favoriten-/
  Seitenwechsel löst Vollbild aus.
- `web/board.php` — Endpunkttest: Header-Vertrag (`X-Board-*`) bei
  Vollbild/Patch, `Content-Length` stimmt mit Body überein, 401/503
  weiterhin ohne Bildkörper, `X-Device-Touch` verschiebt Favorit/Seite
  korrekt im Geräte-Zustand.
- Firmware (`pio test -e native`): Fallback-Bannerlogik (WLAN-Fehler, 401,
  Zeitüberschreitung), Touch-Zone-zu-Request-Mapping.

---

## 13. Nicht Teil dieses Entwurfs

- Akkubetrieb-Feinschliff (Lade-Charakteristik, Spannungs-zu-Prozent-Kurve
  kalibrieren) — die Kopfzeile zeigt einen groben Prozentwert, keine
  präzise Fuel-Gauge-Kalibrierung.
- Mehrere Geräte pro Token (s. Einschränkung in §4).
- Mehr als 3 Favoriten gleichzeitig am Gerät wählbar.
- Eigene Wartung/Erweiterung der ORF-Icon-Code-Tabelle über die drei
  Startwerte hinaus — wächst anhand geloggter unbekannter Codes.
- Exakte Byte-Packung des 4bpp-Graustufenformats (§6) — wird beim
  Firmware-/Protokoll-Implementierungsschritt anhand der Seeed_GFX-Quelle
  geklärt.
