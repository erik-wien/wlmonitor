---
id: TASK-10
title: Surface WL API accessibility and traffic alerts in monitor UI
status: Done
assignee: []
created_date: '2026-04-24 11:31'
updated_date: '2026-04-26 07:14'
labels:
  - ui
  - monitor
  - accessibility
dependencies: []
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The Wiener Linien monitor API exposes two pieces of information the UI currently drops:

1. **Accessibility** — `lines[].barrierFree` and per-departure `vehicle.barrierFree`. A barrier-free vehicle means step-free boarding (low-floor tram, ramp-equipped bus, elevator-served platform).
2. **Traffic alerts** — `lines[].trafficjam` (inline flag) and the monitor response's `trafficInfos[]` + `refTrafficInfoNames[]` linkage (free-text disruption notices, elevator outages, short-form warnings).

Both are already being fetched (`monitor.php` sends `activateTrafficInfo=stoerungkurz&activateTrafficInfo=stoerunglang`) but the parser discards them.

**Goal:** Expose them visually on the monitor:

- Barrier-free departures are rendered with their countdown **underlined** (e.g. `3, 8̲, 12` — only the departures served by a barrier-free vehicle get the underline).
- Lines that are affected by any traffic alert (either `line.trafficjam === true` or the line is referenced by an active `trafficInfo`) show a `⚠️` marker next to the line badge on that card.
- Below the station cards, above the "Aktualisiert: HH:MM:SS" timestamp, a compact alert section lists every active `trafficInfo` (title + description), one alert box per entry, using the shared `.alert` component from `components.css`.

## API shape recap

```
data.monitors[].lines[].barrierFree         : bool       // default for the line
data.monitors[].lines[].trafficjam          : bool
data.monitors[].lines[].departures.departure[].vehicle.barrierFree : bool?  // overrides line default
data.monitors[].refTrafficInfoNames[]       : string[]   // → trafficInfos[].name
data.trafficInfos[]                         : { name, title, description, priority, relatedLines[], relatedStops[] }
```

Per-departure accessibility: the effective value is `dep.vehicle.barrierFree ?? line.barrierFree`. Per WL docs, `vehicle` is only present when the departure differs from line defaults, so the fallback is essential.

## Implementation plan

### 1. `inc/monitor.php` — propagate the new fields

- Change the per-line `departures` field from a pre-joined string to a **structured array**: `[{ t: "3"|"*"|number, bf: bool }, ...]`. Pre-join is currently happening because the JS only prints the string — once JS can style per-departure, the string form is dead weight.
- Add `barrier_free` (bool, from `line.barrierFree`) and `trafficjam` (bool) to each line entry.
- Add `alert` (bool) per line — true when `line.name` appears in any active `trafficInfos[].relatedLines[]` where `status === "active"` for the current station, **or** `line.trafficjam === true`.
- Emit a top-level `alerts` array in the returned structure: `[{ title, description, priority, lines: [names], stops: [divas] }, ...]`, gathered from `$json['data']['trafficInfos']` filtered to `status === "active"`. Deduplicate by `name` (same info can appear once per station).
- Return shape becomes:
  ```
  [
    '<stationId>' => [ 'id', 'diva', 'station_name', 'lines' => [ ['name','towards','type','direction','platform','barrier_free','alert','departures' => [{t,bf},…]], … ] ],
    …,
    'alerts'    => [ {title,description,priority,lines,stops}, … ],
    'trains'    => int,
    'update_at' => 'H:i:s',
    'api_ping'  => int,
  ]
  ```

### 2. `web/api.php` — pass through unchanged

`case 'monitor'` already JSON-encodes the whole structure. The missing-DIVA placeholder blocks must stay compatible with the new `lines[]` shape (they carry an empty `lines: []` — fine). No CSRF change; still GET, still read-only.

### 3. `web/js/wl-monitor.js` — render the three new signals

- `appendDepartureColumns(tr, line)` currently sets `tdTimes.textContent = line.departures`. Replace with a loop that builds `<span class="dep" [data-bf]>` per entry, separated by `, ` text nodes. Accessible-departure spans get `.dep-barrierfree`.
- `createLineBadge(line)` or its call site: when `line.alert === true`, append a `<span class="line-alert" aria-label="Störung auf dieser Linie">⚠️</span>` after the badge (inside the same badge cell, so the row layout stays intact). This is an emoji, not an SVG — the user asked for `⚠️` specifically.
- In `renderMonitor()`, after the stations loop and before the `monitorUpdateTime` paragraph, render `data.alerts` into a `<div id="monitorAlerts">` containing one `.alert.alert-warning` per entry with `role="alert"`, title in `<strong>`, description below. Empty array → render nothing (no empty container, no stale node from previous render). The container is replaced on every `renderMonitor` call (via `container.replaceChildren()` already in place — alerts live inside `#monitor`, so they get cleared automatically).

### 4. `web/css/app/wl-monitor.css` — minimal additions

- `.departure-table .dep-barrierfree { text-decoration: underline; text-decoration-thickness: 2px; text-underline-offset: 2px; }` — discoverable underline that works in both light and dark. `text-decoration-color` inherits from `color` so no token reference needed.
- `.line-alert { margin-left: 0.25em; font-size: 0.9em; }` — space the emoji away from the badge; don't restyle the emoji itself (let the OS render it).
- `#monitorAlerts { margin-top: 0.5rem; display: flex; flex-direction: column; gap: 0.25rem; }` — stack alerts tightly. Use shared `.alert.alert-warning` styling from `components.css`; do not duplicate alert colors here (§1 design tokens).

### 5. No build, no migration, no new dependencies

Pure CSS + server-side parse changes + JS rendering. No DB changes, no auth changes, no CSRF changes.

### 6. Backwards compatibility

The old `departures` string is replaced, not augmented. No other consumer reads `monitor` output — `js/wl-monitor.js` is the only client. `api.php?action=monitor` is internal, not a public API. No need to support both shapes.

### 7. Testing

- Manual: load monitor for a station with active disruptions (U4 elevator outages are reliable). Verify: alerts list populated at bottom, ⚠️ on affected U4 row, no marker on unaffected 13A rows.
- Manual: low-floor tram stop (e.g. line 2 at Schottentor) — verify underlined countdowns on barrier-free vehicles only.
- Manual: clear-weather stop with no alerts — verify no `#monitorAlerts` container, no ⚠️ markers, existing rendering unchanged.
- Manual: filtered favourite with no current departures — verify empty-state fallback ("Keine aktuellen Abfahrten") still shows.
- Regression: `tests/` PHPUnit run (`vendor/bin/phpunit`). Any test that asserts `monitor_get` shape needs updating to the structured `departures` array.

### 8. APP_BUILD bump

Per CLAUDE.md + memory: increment `APP_BUILD` in `inc/initialize.php` once at the end of the session.

## Files touched

- `inc/monitor.php` — parse `barrierFree`, `trafficjam`, `trafficInfos`; restructure `departures` array
- `web/js/wl-monitor.js` — per-departure spans, ⚠️ marker, alerts section rendering
- `web/css/app/wl-monitor.css` — three small rules
- `inc/initialize.php` — APP_BUILD bump
- `tests/*` — update any monitor fixture/assertion that hits the changed shape

## Out of scope

- No global traffic-info page, no "subscribe to line alerts", no push notifications. Only the per-page departure monitor surfaces these fields.
- No `aufzugsinfo` (elevator outage) handling — that's a separate category the current `monitor_get` doesn't request; expand if the user asks.
- No per-departure icon for accessibility beyond the underline (the user specifically requested underscoring; a wheelchair glyph was not asked for).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Per-departure countdown spans are underlined iff the (vehicle or line) barrierFree flag is true
- [ ] #2 Lines with an active trafficInfo reference or trafficjam===true show a ⚠️ next to the line badge
- [ ] #3 Active trafficInfos are rendered as .alert.alert-warning boxes below the station cards and above the update-time line
- [ ] #4 No empty #monitorAlerts container is rendered when the monitor has no active alerts
- [ ] #5 monitor_get() returns structured departures (array of {t, bf}) and a top-level alerts array
- [ ] #6 Stations with no alerts and only barrier-bound vehicles render identically to the pre-change UI (no visual regression)
- [ ] #7 APP_BUILD bumped once in inc/initialize.php
- [ ] #8 No DB schema changes, no new composer dependencies, no build-step introduction
<!-- AC:END -->
