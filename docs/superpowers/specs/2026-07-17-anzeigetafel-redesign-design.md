# WL Monitor — UI-Redesign „Anzeigetafel"

Design-Spec, validiert im Brainstorming 2026-07-17 (Mockups: `.superpowers/brainstorm/53152-*/content/board-style-v3.html`, `layout.html`).

## Ziel

Große Neugestaltung der gesamten UI (Board, Header, Suche, Favoriten, Settings, Admin) in einer
„Anzeigetafel"-Designsprache: dark-first, hoher Kontrast, große Live-Countdowns — angelehnt an
echte Abfahrtstafeln, mit CI-treuen Wiener-Linien-Signalen. Keine Logik-/API-Änderungen.

## Designsprache

### Dunkel — „Tafel" (primär)

- Grund fast-schwarz (`#0a0e14`-Familie), Karten-Grund je Station mit dünner Border (`#1c2330`).
- **Countdowns: Bernstein (`#ffd52e`), Monospace (`ui-monospace`-Stack), tabular-nums.**
  Nächste Abfahrt groß (~22px), Folgezeiten klein und gedämpft (`· 6 · 15`).
- Ziel/Steig in Sans (System-Stack): Ziel ~15px hell (`#e8ecf4`), Steig 11px gedämpft (`#5c6778`).
- Stationstitel: Uppercase, letter-spacing, gedämpft; rechts **LIVE-Indikator** (grüner Dot mit
  Glow + Uhrzeit des letzten Refresh).
- **Puls-Animation NUR auf der Countdown-Ziffer bei 0 oder 1 Minute** (opacity-Puls 1.2s).
  Alle anderen Zeiten stehen ruhig.

### Hell — „Papierfahrplan"

- Vollwertige helle Variante (Aushang-Anmutung): heller Grund, dunkle Schrift,
  Countdowns dunkel statt Bernstein. Liniensignale bleiben CI-farbig (unverändert).
- Theme-Wahl hell/dunkel/auto bleibt wie heute (Preference + Dreiblock-Muster, Regel §2).

### Liniensignale (CI-treu, mandatory)

| Typ | Form |
|---|---|
| U-Bahn | eckiges Quadrat in Linienfarbe (U1 rot, U2 lila, U3 orange, U4 grün `#049334`, U6 braun `#8a6642`), weiße Schrift |
| Tram | runde schwarze Scheibe, weiße Nummer, dünner heller Ring auf dunklem Grund |
| Bus | blaues Rechteck (`#0f4c9e`-Familie), weiße Schrift |
| Nightline | dunkelblaues Rechteck, **gelbe** Schrift + gelber Rand |
| WLB / Badner Bahn | **echtes Logo-SVG** `web/img/Logo_Wiener_Lokalbahn.svg`, weiß auf WLB-Blau `#0055a5` — Mechanik bleibt exakt wie heute (`<img>` + `brightness(0) invert(1)`-Filter in `createLineBadge()`) |

Umsetzung als CSS-Klassen wie bisher (`pt-tram`, `pt-bus-city`, `pt-bus-night`, `pt-tram-wlb`,
`pt-metro` + Linienfarbklasse), gerendert von `createLineBadge()` in `wl-monitor.js`.

## Layout (validiert: Variante B)

### Desktop
- Header: Logo/Titel links, Stationssuche (Tafel-Stil), User-Menü rechts (Chrome-Header-Konventionen §12 bleiben).
- Hauptbereich: Board links (volle Restbreite), **rechte Favoriten-Sidebar** wie gewohnt,
  neu gestylt: Favoriten als Outline-Chips in der je User gewählten Palette-Farbe
  (bestehendes `bclass`), aktiver Favorit = gefüllter Chip.

### Mobil (< 600px)
- Header kompakt: Logo, Lupen-Icon (klappt Vollbreite-Suchfeld auf), User-Menü.
- Board volle Breite.
- **Favoriten als horizontale Chip-Leiste unten** (Bottom-Bar, daumenfreundlich,
  horizontal wischbar). Gleiche Chips/Farben wie Desktop-Sidebar.

## Stationssuche

- Desktop: Suchfeld im Header, dunkles Feld, Bernstein-Fokusring.
- Mobil: Lupen-Icon → aufklappendes Vollbreite-Feld.
- Ergebnis-Dropdown im Tafel-Stil; je Station eine Zeile mit Liniensignal-Vorschau.
- Bestehende Dropdown-Pitfalls beachten (pointerdown-close, `[hidden]`-display-Falle, Regel §8).

## Settings / Admin / Help

- `preferences.php`, `security.php`, `help.php`, `admin/*`: **neue Haut, gleiche Struktur.**
  Dunkle Karten, gleiche Token; Formulare/Tabs/Flows unverändert (§15/§18-Muster bleiben).
- Bernstein sparsam (Fokus/Highlights); Button-Semantik nach Regel §7.1 unverändert
  (rot = schreibt Daten; Tafel-Bernstein ist kein Button-Farbton).

## Motion

- Countdown-Wertwechsel beim Poll-Refresh: sanftes Überblenden (kein Layout-Springen;
  tabular-nums sichern die Breite).
- Favoriten-/Stationswechsel: kurzer Fade des Boards.
- Puls nur bei 0/1 (s. o.). Alles unter `prefers-reduced-motion` neutralisiert (shared layout.css).

## Technische Umsetzung

- **Keine Änderungen an** `api.php`, Datenmodell, Poll-Logik, Favoriten-CRUD.
- `web/css/app/wl-monitor.css`: Neuschrieb in der neuen Sprache (heute 379 Zeilen, Ziel ähnliche Größe).
- **Neu:** `project-theme.css`-Ebene mit Tafel-Tokens im Dreiblock-Muster
  (`:root` = Papierfahrplan hell, `[data-theme="dark"]` + `prefers-color-scheme` = Tafel).
  Neue Tokens projektlokal (z. B. `--board-bg`, `--board-countdown`, `--board-live`),
  Semantik-Tokens der Library bleiben unangetastet.
- `wl-monitor.js`: nur Markup-/Klassenanpassungen in den Render-Funktionen
  (`createLineBadge`, Board-Rows, Favoriten-Chips, Suche); keine Logikänderung.
- `index.php` / `inc/html_header.php` / `inc/html_footer.php`: Struktur für Sidebar/Bottom-Bar
  + mobiles Such-Pattern.
- Schriften: Countdown `ui-monospace`-Stack (kein Webfont-Zukauf); Text bleibt beim
  Library-Standard (Atkinson Hyperlegible/system-ui, Regel §4).
- A11y-Floor (Regel §5): Kontrast AA auch für Bernstein-auf-Dunkel und gedämpfte Steig-Zeile
  prüfen; `aria-label` für Signale (z. B. „Linie U6"). **Entschieden: keine `aria-live`-Region
  fürs Board** — periodische Poll-Refreshes würden Screenreader zuspammen; Countdown-Updates
  bleiben rein visuell, der „Aktualisiert"-Zeitstempel ist als Text zugänglich.

## Explizit außerhalb des Umfangs

- Keine neuen Features (kein neues Favoriten-Verhalten, keine neuen Endpoints).
- Keine Änderung der Auth-/Session-Flows.
- `APP_BUILD` wird beim Abschluss der Umsetzung einmal erhöht (§13).

## Akzeptanz (grob — Detail im Implementierungsplan)

1. Board in dunkel = Tafel-Look aus Mockup v3 (Signale CI-treu, Bernstein-Countdowns, Puls nur 0/1, LIVE-Dot).
2. Hell = Papierfahrplan, Theme-Wahl funktioniert in beide Richtungen (Dreiblock).
3. Desktop: Sidebar-Chips; Mobil: Bottom-Chip-Leiste; aktive Markierung korrekt.
4. Suche im Tafel-Stil mit Signal-Vorschau, mobil aufklappbar; Dropdown-Pitfalls (§8) eingehalten.
5. Settings/Admin/Help tragen die neue Haut, Flows unverändert.
6. `prefers-reduced-motion` respektiert; Kontrast AA; keine Emojis; Icons aus Bestand/Library.
