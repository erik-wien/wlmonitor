# UI-Redesign „Anzeigetafel" — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** wlmonitor-UI komplett auf die validierte „Anzeigetafel"-Designsprache umstellen (Spec: `docs/superpowers/specs/2026-07-17-anzeigetafel-redesign-design.md`) — ohne Logik-/API-Änderungen.

**Architecture:** Ein neuer projektlokaler Token-Layer `web/css/app/wl-theme.css` überschreibt die semantischen `--color-*`-Tokens der Library im Dreiblock-Muster (hell = „Papierfahrplan", dunkel = „Tafel") und definiert `--board-*`-Tokens. Dadurch erben ALLE Seiten (Settings/Admin/Help) die neue Haut automatisch. `wl-monitor.css` wird in der neuen Sprache umgeschrieben; `wl-monitor.js` bekommt nur Markup-/Klassenänderungen in Renderfunktionen.

**Tech Stack:** PHP 8.2 (kein Framework), vanilla JS (ES-Module), geteilte CSS-Library (`web/css/shared/` Symlink), erikr/chrome-Header.

## Global Constraints

- Keine Änderungen an `api.php`, Poll-Logik, Favoriten-CRUD, Auth-Flows (Spec „außerhalb des Umfangs").
- CI-Signale sind nicht themebar: U-Bahn-Linienfarben, Tram rund-schwarz, Bus `#0f4c9e`-Familie, Nightline blau/gelb, WLB-Logo `<img src="img/Logo_Wiener_Lokalbahn.svg">` + `brightness(0) invert(1)` — Mechanik bleibt exakt wie heute.
- Puls-Animation NUR auf Countdown-Ziffern mit Wert 0 oder 1.
- Dreiblock-Dark-Mode-Muster verpflichtend (`:root` hell / `[data-theme="dark"]` / `prefers-color-scheme`-Block) — Regel §2.
- Keine Emojis; Icons aus Bestand (`css/icons.svg`-Sprite, `makeSvgIcon`).
- Button-Semantik Regel §7.1 unverändert (rot = schreibt Daten; Bernstein ist kein Button-Ton).
- `prefers-reduced-motion` neutralisiert alle Animationen (macht shared layout.css global — eigene Animationen brauchen keinen Extra-Guard, nicht dagegen arbeiten).
- Kontrast WCAG AA (Bernstein `#ffd52e` auf `#0a0e14` ≈ 12:1 ✓; gedämpfte Töne ≥ 4.5:1 für Text prüfen).
- Am Ende der Umsetzung: `APP_BUILD` in `inc/initialize.php` einmal erhöhen (aktuell 45 → 46).
- Jede Task endet mit eigenem Commit; Verifikation lokal über `http://wlmonitor.test/`.

---

### Task 1: Tafel-Token-Layer (`wl-theme.css`)

**Files:**
- Create: `web/css/app/wl-theme.css`
- Modify: `inc/layout.php` (CSS-Ladeblock, nach `components.css`, vor `wl-monitor.css`)
- Modify: `web/css/app/wl-monitor.css` (alten `--wl-monitor-*`-Tokenblock Zeilen 283–316 entfernen; Ersatz kommt in Task 2)

**Interfaces:**
- Produces: CSS-Custom-Properties, die alle Folgetasks konsumieren:
  `--board-bg`, `--board-card-bg`, `--board-border`, `--board-title`, `--board-title-line`,
  `--board-countdown`, `--board-countdown-dim`, `--board-dest`, `--board-sub`, `--board-live`,
  `--board-font-mono`. Zusätzlich überschriebene Semantik-Tokens `--color-bg/surface/surface-alt/card/border/text/muted`.

- [ ] **Step 1: `web/css/app/wl-theme.css` anlegen**

```css
/* web/css/app/wl-theme.css — Projekt-Theme „Anzeigetafel".
   Überschreibt die semantischen Library-Tokens app-weit (Dreiblock-Muster, Regel §2):
   hell = „Papierfahrplan" (Aushang), dunkel = „Tafel" (LED-Board).
   Board-spezifische Tokens tragen das --board-Präfix.
   Liniensignal-Farben sind hier NICHT themebar (CI, in wl-monitor.css hardcodiert). */

:root {
  /* ── Papierfahrplan (hell, Default) ── */
  --color-bg:          #f2efe7;
  --color-surface:     #faf8f2;
  --color-surface-alt: #ebe7dc;
  --color-card:        #faf8f2;
  --color-border:      #d8d2c2;
  --color-text:        #23201a;
  --color-muted:       #7a745f;

  --board-bg:            #f2efe7;
  --board-card-bg:       #faf8f2;
  --board-border:        #d8d2c2;
  --board-title:         #7a745f;
  --board-title-line:    #d8d2c2;
  --board-countdown:     #23201a;   /* Papier: dunkle Countdowns */
  --board-countdown-dim: #7a745f;
  --board-dest:          #23201a;
  --board-sub:           #918a73;
  --board-live:          #1a7f37;
  --board-font-mono:     ui-monospace, "SF Mono", Menlo, Consolas, monospace;
}

[data-theme="dark"] {
  /* ── Tafel (dunkel) ── */
  --color-bg:          #05070b;
  --color-surface:     #0a0e14;
  --color-surface-alt: #10151f;
  --color-card:        #0a0e14;
  --color-border:      #1c2330;
  --color-text:        #e8ecf4;
  --color-muted:       #8a93a5;

  --board-bg:            #05070b;
  --board-card-bg:       #0a0e14;
  --board-border:        #1c2330;
  --board-title:         #8a93a5;
  --board-title-line:    #1c2330;
  --board-countdown:     #ffd52e;   /* Tafel: Bernstein */
  --board-countdown-dim: #8a93a5;
  --board-dest:          #e8ecf4;
  --board-sub:           #5c6778;
  --board-live:          #4ade80;
}

@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --color-bg:          #05070b;
    --color-surface:     #0a0e14;
    --color-surface-alt: #10151f;
    --color-card:        #0a0e14;
    --color-border:      #1c2330;
    --color-text:        #e8ecf4;
    --color-muted:       #8a93a5;

    --board-bg:            #05070b;
    --board-card-bg:       #0a0e14;
    --board-border:        #1c2330;
    --board-title:         #8a93a5;
    --board-title-line:    #1c2330;
    --board-countdown:     #ffd52e;
    --board-countdown-dim: #8a93a5;
    --board-dest:          #e8ecf4;
    --board-sub:           #5c6778;
    --board-live:          #4ade80;
  }
}
```

- [ ] **Step 2: In `inc/layout.php` einbinden** — im CSS-Block (aktuell Z. 85–90) zwischen `components.css` und `wl-monitor.css`:

```php
  <link rel="stylesheet" href="css/app/wl-theme.css<?= $cssV('css/app/wl-theme.css') ?>">
```

- [ ] **Step 3: Alten Tokenblock entfernen** — in `web/css/app/wl-monitor.css` den kompletten Abschnitt „Monitor card colors" (`:root { --wl-monitor-card-bg …` bis inkl. des `@media`-Blocks, Zeilen 283–307) UND die Konsumenten-Regeln `#monitor .app-card { background-color: var(--wl-monitor-card-bg); }` / `#monitor .app-card-header { … }` (Z. 309–316) löschen. Die Referenz `color: var(--wl-monitor-header-fg)` in `.btn-add-steig` (Z. 333) auf `color: var(--board-title)` ändern.

- [ ] **Step 4: Verifizieren**

Run: `php -l inc/layout.php && grep -c "wl-monitor-card-bg\|wl-monitor-header" web/css/app/wl-monitor.css; grep -c "board-countdown" web/css/app/wl-theme.css`
Expected: `No syntax errors`; erster grep `0`; zweiter grep `3`.

Browser: `http://wlmonitor.test/` in dunkel — Grundflächen fast-schwarz, Text hell; in hell — Papier-Töne. (Board selbst noch alt gestylt — kommt in Task 2.)

- [ ] **Step 5: Commit**

```bash
git add web/css/app/wl-theme.css web/css/app/wl-monitor.css inc/layout.php
git commit -m "feat(theme): Tafel/Papierfahrplan-Token-Layer (wl-theme.css, Dreiblock)"
```

---

### Task 2: Board — Tafel-Optik, Countdown-Hierarchie, Puls 0/1, LIVE-Dot, Fade

**Files:**
- Modify: `web/css/app/wl-monitor.css` (Abschnitte „Departure table" Z. 7–22, `#monitorUpdateTime` Z. 318–324; neue Board-Regeln)
- Modify: `web/js/wl-monitor.js` — `renderMonitor()` (Z. 300–447), `appendDepartureColumns()` (Z. 449–498), `loadMonitor()` (Z. 75–113)

**Interfaces:**
- Consumes: `--board-*`-Tokens aus Task 1.
- Produces: CSS-Klassen `dep-next`, `dep-follow`, `dep-immi`, `board-live`, `board-enter` — Task 6 verifiziert sie nur.

- [ ] **Step 1: CSS — Board-Regeln ersetzen.** In `wl-monitor.css` den Abschnitt „Departure table" (Z. 7–22) ersetzen durch:

```css
/* ── Tafel: Stationskarten ─────────────────────────────────────────────────── */

#monitor .app-card {
  background: var(--board-card-bg);
  border: 1px solid var(--board-border);
  border-radius: 12px;
  overflow: hidden;
}
#monitor .app-card-header {
  background: transparent;
  border-bottom: 1px solid var(--board-title-line);
  color: var(--board-title);
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  padding: 0.55rem 0.9rem;
}
.board-live {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 0.65rem;
  font-weight: 600;
  letter-spacing: 0.1em;
  color: var(--board-live);
  white-space: nowrap;
}
.board-live::before {
  content: "";
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--board-live);
  box-shadow: 0 0 6px var(--board-live);
}

/* ── Tafel: Abfahrtszeilen ─────────────────────────────────────────────────── */

.departure-table { table-layout: auto; width: 100%; }
.departure-table td, .departure-table th { color: var(--board-dest); border-color: var(--board-title-line); }
.departure-table .badge-cell    { width: 2.8em; padding: 0.25em; vertical-align: middle; }
.departure-table .platform-cell { width: 2.2em; font-size: 0.7em; color: var(--board-sub); vertical-align: middle; }
.departure-table .towards-cell  { width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle; color: var(--board-dest); }
.departure-table .towards-cell .departure-note { font-size: 0.7em; color: var(--board-sub); white-space: normal; margin-top: 0.1em; }
.departure-table .times-cell {
  white-space: nowrap; text-align: right; vertical-align: middle; padding-left: 0.5em;
  font-family: var(--board-font-mono);
  font-variant-numeric: tabular-nums;
}
.departure-table .times-cell .dep-next {
  font-size: 1.3rem; font-weight: 800; color: var(--board-countdown); line-height: 1;
}
.departure-table .times-cell .dep-follow,
.departure-table .times-cell .dep-sep {
  font-size: 0.78rem; font-weight: 500; color: var(--board-countdown-dim);
}
.departure-table .dep-barrierfree { text-decoration: underline; text-decoration-thickness: 2px; text-underline-offset: 2px; }
.departure-table .times-cell.times-scheduled { font-style: italic; }
.departure-table .times-cell.times-scheduled .dep-next { color: var(--board-countdown-dim); }
.line-alert { margin-left: 0.15em; font-size: 0.9em; line-height: 1; }
#monitorAlerts { margin-top: 0.5rem; display: flex; flex-direction: column; gap: 0.25rem; }

/* Puls NUR bei 0/1 min (reduced-motion wird von shared layout.css neutralisiert) */
@keyframes board-pulse { 50% { opacity: 0.4; } }
.dep-immi { animation: board-pulse 1.2s ease-in-out infinite; }

/* Board-Wechsel (Station/Favorit): kurzer Fade — nicht beim Poll-Refresh */
@keyframes board-enter { from { opacity: 0; } to { opacity: 1; } }
#monitor.board-enter { animation: board-enter 0.25s ease-out; }
```

- [ ] **Step 2: JS — Countdown-Markup in `appendDepartureColumns()`.** Die `deps.forEach`-Schleife (Z. 467–484) ersetzen — Klassenlogik neu, Rest (bf/jam/deviations) identisch:

```js
  deps.forEach((d, i) => {
    if (i > 0) {
      const sep = document.createElement('span');
      sep.className = 'dep-sep';
      sep.textContent = ' · ';
      tdTimes.appendChild(sep);
    }
    const span = document.createElement('span');
    span.className = 'dep ' + (i === 0 ? 'dep-next' : 'dep-follow') + (d.bf ? ' dep-barrierfree' : '');
    const mins = parseInt(d.t, 10);
    if (i === 0 && !Number.isNaN(mins) && mins <= 1) span.classList.add('dep-immi');
    if (d.bf) span.title = 'Barrierefreies Fahrzeug';
    span.textContent = d.t;
    tdTimes.appendChild(span);
    if (d.jam) {
      tdTimes.appendChild(createAlertMarker());
      jammedTimes.push(d.t);
    }
    if (d.name_override || d.towards_override) {
      const parts = [];
      if (d.name_override) parts.push(d.name_override);
      if (d.towards_override) parts.push('→ ' + d.towards_override);
      deviations.push({ t: d.t, label: parts.join(' ') });
    }
  });
```

- [ ] **Step 3: JS — LIVE-Dot im Kartenkopf.** In `renderMonitor()` nach `nameSpan`-Append (Z. 335) einfügen (nutzt das bereits destrukturierte `update_at`):

```js
    if (update_at) {
      const live = document.createElement('span');
      live.className = 'board-live ms-2';
      live.textContent = 'LIVE ' + update_at;
      header.appendChild(live);
    }
```

Die globale Zeile `#monitorUpdateTime` („Aktualisiert: …", Z. 434–439) bleibt als Text-Fallback (A11y: zugänglicher Zeitstempel laut Spec) — CSS-Regel dazu unverändert lassen.

- [ ] **Step 4: JS — Fade nur bei Stationswechsel.** In `loadMonitor(diva, fav)` VOR dem Fetch festhalten und nach dem Rendern anwenden — an vorhandener Struktur (Z. 75 ff.):

```js
  const stationChanged = diva !== currentMonitor.diva;
  // … bestehender Fetch + renderMonitor(data) …
  if (stationChanged) {
    const el = document.getElementById('monitor');
    el.classList.remove('board-enter');
    void el.offsetWidth;             // Reflow: Animation neu starten
    el.classList.add('board-enter');
  }
```

- [ ] **Step 5: Verifizieren**

Run: `grep -c "dep-next\|dep-immi\|board-live" web/js/wl-monitor.js web/css/app/wl-monitor.css`
Expected: JS ≥ 3 Treffer, CSS ≥ 4 Treffer.

Browser `http://wlmonitor.test/` (dunkel): Stationstitel uppercase + „LIVE hh:mm:ss" mit grünem Glow-Dot; erste Abfahrt groß Bernstein, Folgezeiten klein grau mit `·`; eine Abfahrt mit 0/1 pulsiert, alle anderen ruhig; hell: Papier-Look, Countdowns dunkel. Stationswechsel per Suche → kurzer Fade; Poll-Refresh (20 s warten) → KEIN Fade.

- [ ] **Step 6: Commit**

```bash
git add web/css/app/wl-monitor.css web/js/wl-monitor.js
git commit -m "feat(board): Tafel-Optik — Countdown-Hierarchie, Puls 0/1, LIVE-Dot, Enter-Fade"
```

---

### Task 3: Favoriten als Chips + aktiver Zustand

**Files:**
- Modify: `web/css/app/wl-monitor.css` (Abschnitt „Favorites grid" Z. 240–252)
- Modify: `web/js/wl-monitor.js` — `renderFavorites()` (Z. 582–626), `loadMonitor()` (aktive Markierung)

**Interfaces:**
- Consumes: bestehende `fav.bclass`-Werte (`btn-outline-color-*` aus der Library) — bleiben die Farbquelle.
- Produces: Klassen `fav-chip`, `fav-active`; Task 4 stylt dieselben Chips in der Bottom-Leiste.

- [ ] **Step 1: JS — Chip-Klasse + aktive Markierung.** In `renderFavorites()` Z. 589 ändern:

```js
    btn.className = 'btn fav-chip ' + fav.bclass + ' text-start';
```

Am Ende von `loadMonitor(diva, fav)` (nach erfolgreichem Render) einfügen:

```js
  document.querySelectorAll('#buttons .fav-chip').forEach(b =>
    b.classList.toggle('fav-active', Number(b.dataset.favId) === (fav?.id ?? -1)));
```

- [ ] **Step 2: CSS — Chip-Gestalt.** Nach dem `#buttons`-Grid-Block ergänzen:

```css
/* Favoriten-Chips: Outline in der User-Farbe (bclass), aktiv = gefüllt.
   Die Farbe liefert die Library-Klasse btn-outline-color-* via --btn-color. */
#buttons .fav-chip {
  border-radius: 999px;
  border-width: 2px;
  font-weight: 700;
  padding: 0.45rem 1rem;
}
#buttons .fav-chip.fav-active {
  background: currentColor;
}
#buttons .fav-chip.fav-active > * {
  color: var(--color-bg);
  filter: none;
}
```

**Hinweis für den Implementierer:** Vor dem Styling prüfen, wie `btn-outline-color-*` in `web/css/shared/components.css` die Farbe setzt (`--btn-color`, `color`, `border-color`?) — `fav-active` muss den GEFÜLLTEN Zustand mit lesbarem Text erzeugen. Falls die Library-Klasse einen `:hover`-Füllzustand definiert, dieselbe Technik für `.fav-active` übernehmen statt der `currentColor`-Heuristik oben.

- [ ] **Step 3: Verifizieren**

Browser (eingeloggt nötig — Favoriten sind accountgebunden; User bitten sich anzumelden oder mit `wlmonitor.jardyx.com` gegenprüfen): Chips rund, Outline in je eigener Farbe, Titel fett; Klick auf Chip → wird gefüllt, vorheriger Chip wieder Outline; Initial-Load mit `last_fav_id` markiert den richtigen Chip. Drag-Sort am Desktop funktioniert weiter (`drag-handle` sichtbar).

- [ ] **Step 4: Commit**

```bash
git add web/css/app/wl-monitor.css web/js/wl-monitor.js
git commit -m "feat(favs): Favoriten als Farb-Chips mit aktivem Zustand"
```

---

### Task 4: Mobile Bottom-Chip-Leiste

**Files:**
- Modify: `web/css/app/wl-monitor.css` (Media-Query-Bereich um Z. 247–252 + neue Regeln)

**Interfaces:**
- Consumes: `fav-chip`-Klassen aus Task 3; `--app-footer-height` aus shared layout.css.

- [ ] **Step 1: CSS.** Den bestehenden Block `@media (max-width: 767px) { .fav-filter-sub { display:none; } }` erweitern:

```css
@media (max-width: 767px) {
  .fav-filter-sub { display: none; }

  /* Favoriten als fixe Bottom-Leiste (daumenfreundlich, horizontal wischbar) */
  #buttons {
    position: fixed;
    left: 0; right: 0;
    bottom: var(--app-footer-height);
    display: flex;
    grid-template-columns: none;
    gap: 0.4rem;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding: 0.5rem 0.75rem;
    background: var(--color-surface);
    border-top: 1px solid var(--color-border);
    z-index: 100;
    scrollbar-width: none;
  }
  #buttons::-webkit-scrollbar { display: none; }
  #buttons .fav-chip { flex: none; white-space: nowrap; }

  /* Platz für die Leiste reservieren (zusätzlich zum Footer) */
  body { padding-bottom: calc(var(--app-footer-height) + 3.4rem); }

  /* topBtn über die Leiste heben */
  #topBtn { bottom: calc(var(--app-footer-height) + 4rem); }
}
```

- [ ] **Step 2: Verifizieren**

Browser mit schmalem Viewport (DevTools-Emulation oder Fenster < 768px): Chips als eine wischbare Zeile fix über dem Footer; Board scrollt dahinter frei; kein Inhalt von der Leiste verdeckt (Body-Padding). Desktop (≥ 768px): unverändert Sidebar-Spalte.

- [ ] **Step 3: Commit**

```bash
git add web/css/app/wl-monitor.css
git commit -m "feat(mobile): Favoriten-Chips als fixe Bottom-Leiste"
```

---

### Task 5: Suche & Dropdown im Tafel-Stil + Signal-Vorschau + mobiles Aufklappen

**Files:**
- Modify: `web/css/app/wl-monitor.css` („Station search dropdown" Z. 81–111, „Station search inside .app-header" Z. 129–158)
- Modify: `web/js/wl-monitor.js` — `renderStationList()` (Z. 698–742), neue Helfer `lineSignalClass()`, Wiring fürs mobile Aufklappen
- Modify: `inc/layout.php` — `$leftExtra`-Suchmarkup (Lupen-Toggle-Button ergänzen; Markup per `grep -n "header-search" inc/layout.php` lokalisieren)

**Interfaces:**
- Consumes: Stations-Datensatz enthält `lines` (Text, z. B. `"59A, 62"` — aus `ogd_stations.Linien`, siehe `inc/stations.php:34`).
- Produces: `lineSignalClass(name)` — Heuristik Linienname → Signal-CSS-Klasse; `sig-mini`-Badges.

- [ ] **Step 1: JS — Signal-Heuristik** (über `renderStationList` einfügen):

```js
/* Liniensignal-Klasse aus dem Liniennamen (Suche liefert nur Namen, keinen Typ).
   Heuristik nach Wiener Konvention: U* Metro, N* Nightline, WLB Lokalbahn,
   Ziffern+Buchstabe Bus (59A), nur Ziffern Tram (62). */
function lineSignalClass(name) {
  const n = name.trim().toUpperCase();
  if (/^U\d$/.test(n)) return 'pt-metro ' + n;
  if (/^N\d+[A-Z]?$/.test(n)) return 'pt-bus-night';
  if (n === 'WLB' || n.startsWith('BADNER')) return 'pt-tram-wlb';
  if (/^\d+[A-Z]$/.test(n)) return 'pt-bus-city';
  if (/^\d+$/.test(n)) return 'pt-tram';
  return 'pt-default';
}
```

- [ ] **Step 2: JS — Vorschau-Badges in `renderStationList()`.** In beiden Zweigen (dist + alpha) nach dem Stationsnamen-Element ergänzen — gemeinsamer Helfer:

```js
function appendLinePreview(p, s) {
  if (!s.lines) return;
  const wrap = document.createElement('span');
  wrap.className = 'sig-preview';
  s.lines.split(',').slice(0, 6).forEach(raw => {
    const name = raw.trim();
    if (!name) return;
    if (name.toUpperCase() === 'WLB') {
      const b = document.createElement('span');
      b.className = 'line-badge sig-mini pt-tram-wlb';
      const img = document.createElement('img');
      img.src = 'img/Logo_Wiener_Lokalbahn.svg';
      img.alt = 'WLB';
      img.className = 'wlb-logo';
      b.appendChild(img);
      wrap.appendChild(b);
      return;
    }
    const b = document.createElement('span');
    b.className = 'line-badge sig-mini ' + lineSignalClass(name);
    b.textContent = name;
    wrap.appendChild(b);
  });
  p.appendChild(wrap);
}
```

Aufruf in beiden Zweigen: `appendLinePreview(p, s);` direkt vor `li.appendChild(p);`. **Achtung:** im dist-Zweig hängt der Klick-Listener am inneren `span` — Badges NACH dem span anhängen, Klick-Verhalten unverändert.

- [ ] **Step 3: CSS — Dropdown + Mini-Signale + Fokusring Bernstein:**

```css
.sig-preview { display: inline-flex; gap: 3px; margin-left: 0.5rem; vertical-align: middle; }
.line-badge.sig-mini { width: 1.7em; height: 1.7em; font-size: 0.6rem; }
.line-badge.sig-mini.pt-bus-city, .line-badge.sig-mini.pt-bus-night { width: auto; min-width: 1.9em; padding: 0 3px; }

.header-search #s:focus-visible {
  outline: none;
  border-color: var(--board-countdown);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--board-countdown) 30%, transparent);
}
```

(Die bestehende `#s:focus-visible`-Regel mit `--color-primary` ersetzen; übrige Dropdown-Regeln behalten — sie hängen an Semantik-Tokens und tragen die Tafel-Haut aus Task 1 automatisch.)

- [ ] **Step 4: Mobiles Aufklappen.** In `inc/layout.php` im `$leftExtra`-Suchblock einen nur mobil sichtbaren Lupen-Button vor dem Feld ergänzen (Icon `search` aus dem Sprite, `id="searchToggle"`, `class="btn-icon search-toggle"`, `aria-label="Station suchen"`). CSS:

```css
.search-toggle { display: none; }
@media (max-width: 600px) {
  .search-toggle { display: flex; }
  .header-search .search-row { display: none; position: absolute; top: 100%; left: 0; right: 0; padding: 0.5rem; background: var(--color-surface); border-bottom: 1px solid var(--color-border); z-index: 1050; }
  .header-search.open .search-row { display: flex; }
}
```

JS-Wiring (bei `wireStationDropdown()` ergänzen):

```js
  document.getElementById('searchToggle')?.addEventListener('click', () => {
    const hs = document.querySelector('.header-search');
    hs.classList.toggle('open');
    if (hs.classList.contains('open')) document.getElementById('s')?.focus();
  });
```

**Pitfall-Check (Regel §8):** Der bestehende Outside-Close des Dropdowns (Z. 789) nutzt `click` — beim Anfassen dieser Stelle auf `pointerdown` (capture) umstellen und sicherstellen, dass Interaktionen INNERHALB von `.header-search`/Dropdown nicht schließen.

- [ ] **Step 5: Verifizieren**

Run: `php -l inc/layout.php`
Expected: `No syntax errors`.

Browser Desktop: Suche tippen → Dropdown im Tafel-Stil, je Station Mini-Signale (62 = runde schwarze Scheibe, 59A = blaues Rechteck, U6 = braunes Quadrat, WLB = Logo); Klick lädt Station; Fokusring Bernstein. Mobil (< 600px): nur Lupe im Header, Klick klappt Vollbreite-Feld auf, Fokus landet im Feld; Scrollen im Dropdown schließt es nicht.

- [ ] **Step 6: Commit**

```bash
git add web/css/app/wl-monitor.css web/js/wl-monitor.js inc/layout.php
git commit -m "feat(search): Tafel-Dropdown mit Liniensignal-Vorschau + mobiles Aufklappen"
```

---

### Task 6: Settings/Admin/Help-Haut verifizieren, Feinschliff, APP_BUILD

**Files:**
- Modify (nur bei Befund): `web/css/app/wl-monitor.css` (Admin-Abschnitt Z. 344–379)
- Modify: `inc/initialize.php` (Z. 34: `APP_BUILD` 45 → 46)

**Interfaces:**
- Consumes: alle vorigen Tasks.

- [ ] **Step 1: Seiten-Sweep.** Nacheinander in HELL und DUNKEL prüfen: `preferences.php`, `security.php`, `help.php`, `admin.php`, `login.php`, `impressum.php`. Erwartung: neue Flächen-/Text-Töne aus Task 1 überall; Formulare, Tabs, Tabellen, Modals lesbar (AA); rote Buttons unverändert rot. Befunde als je eine kleine CSS-Korrektur in `wl-monitor.css` beheben (KEINE Änderungen an shared/).

- [ ] **Step 2: Kontrast-Stichproben.** Mit DevTools-Picker prüfen: `--board-sub` (#5c6778) auf `--board-card-bg` (#0a0e14) für die Steig-Spalte (Ziel ≥ 4.5:1 — sonst auf #6b7688 anheben und in allen drei Blöcken nachziehen); Papier-Variante analog (`#7a745f` auf `#faf8f2`).

- [ ] **Step 3: Reduced-Motion-Probe.** macOS „Bewegung reduzieren" aktivieren (oder DevTools-Emulation): Puls + Fade stehen still; Board bleibt voll benutzbar.

- [ ] **Step 4: `APP_BUILD` erhöhen** — `inc/initialize.php` Z. 34: `define('APP_BUILD', 46);`

- [ ] **Step 5: Gesamtverifikation**

Run: `php -l inc/initialize.php && for f in web/*.php; do php -l "$f" | grep -v "No syntax" ; done; echo OK`
Expected: `OK` (keine Syntaxfehler-Zeilen).

Browser-Rundgang: Board (hell+dunkel, mobil+Desktop), Chips inkl. aktiv, Bottom-Leiste, Suche inkl. Vorschau, ein Settings-Screen, Admin-Tab. Footer zeigt Build 46.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore(release): Anzeigetafel-Redesign — Feinschliff, A11y-Sweep, APP_BUILD 46"
```

---

## Self-Review-Notizen (bereits eingearbeitet)

- Spec-Abdeckung: Tokens/Dreiblock (T1), Board+Puls+LIVE+Motion (T2), Chips/Layout B Desktop (T3), Layout B mobil (T4), Suche+Signal-Vorschau+mobil (T5), Settings-Haut+A11y+APP_BUILD (T6). Signale selbst: bereits CI-konform in Bestand (`createLineBadge` + `pt-*`-Klassen) — bewusst KEINE Task.
- Suche liefert nur Liniennamen (kein Typ) → Signal-Vorschau per dokumentierter Namens-Heuristik (`lineSignalClass`), Monitor-Board nutzt weiterhin den echten API-Typ.
- `fav-active`-Fülltechnik hängt von der `btn-outline-color-*`-Implementierung der Library ab → expliziter Prüfhinweis in Task 3 statt blinder Annahme.
