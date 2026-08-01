# Board-Endpunkt (Server) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** wlmonitor bekommt einen token-geschützten Endpunkt `web/board.php`, der die Favoriten eines Benutzers **serverseitig gefiltert** und in einer stark vereinfachten Form ausliefert; `monitor_json.php` wird abgesichert, ohne seine Antwortform zu ändern.

**Architecture:** Die gesamte Logik liegt in `inc/board.php` als **reine Funktionen** (Eingabe: Daten, Ausgabe: Daten — kein Netz, keine DB, keine Superglobals). `web/board.php` ist nur Verdrahtung: Token prüfen, Favoriten laden, `monitor_get()` rufen, durch `inc/board.php` schicken, JSON ausgeben. Dadurch ist der schwierige Teil — die Filtersemantik — ohne Netz und ohne DB testbar.

**Tech Stack:** PHP 8.2+, MySQLi, PHPUnit 13, `erikr/auth` (Composer path dep). Kein Framework, kein Build-Schritt.

**Spec:** `docs/superpowers/specs/2026-08-01-epaper-abfahrtsmonitor-design.md`

## Global Constraints

- Zeilenidentität ist **(Linie, Steig)** — niemals das Fahrtziel. Ziele ändern sich bei Kurzführungen, Steige nicht.
- Eine DIVA **ohne** Eintrag in `filter_json` bedeutet *kein Filter* → alle Linien dieser Haltestelle.
- **Leere gefilterte Karten bleiben stehen**, leere ungefilterte werden entfernt.
- Kein Endpunkt schreibt oder liest `$_SESSION`. Kein `Set-Cookie` in einer Token-Antwort.
- **Keine internen Fehlertexte nach außen.** Jeder Fehlerpfad ruft `appendLog()` (Fehler-Regeln §21).
- Alle neuen Funktionen in `inc/board.php` sind rein: keine Superglobals, kein Netz, keine DB.
- Antwortform von `monitor_json.php` bleibt **Byte-für-Byte kompatibel** (Home Assistant).
- Tests laufen mit `vendor/bin/phpunit --testsuite Unit` und brauchen **keine** Netzverbindung.

---

## Dateien

| Datei | Verantwortung |
|---|---|
| `inc/board.php` (neu) | Reine Filter- und Formfunktionen. Kein I/O. |
| `tests/Unit/BoardFilterTest.php` (neu) | Filtersemantik (Task 1) |
| `tests/Unit/BoardShapeTest.php` (neu) | Antwortform (Task 2) |
| `web/board.php` (neu) | Endpunkt: Token, Favoriten, JSON |
| `tests/Unit/BoardEndpointTest.php` (neu) | Endpunktverhalten ohne Netz (Task 4) |
| `../auth/src/bootstrap.php` (ändern) | keine Session für Token-Anfragen (Task 3) |
| `web/monitor_json.php` (ändern) | Härtung, Form unverändert (Task 5) |
| `tests/bootstrap.php` (ändern) | lädt zusätzlich `inc/board.php` |

**Eingangsdaten** (von `monitor_get()`, unverändert): eine Map, die Stationen **und Metadaten** mischt —

```php
[
  '60200103' => ['id' => '60200103', 'diva' => '60200103', 'station_name' => 'Aßmayergasse',
                 'lines' => [ /* siehe unten */ ]],
  'alerts'    => [...],   // Metadaten, KEINE Station
  'trains'    => 10,
  'update_at' => '17:17:30',
  'api_ping'  => 0,
]
```

Eine Zeile in `lines`:

```php
['name' => '59A', 'towards' => 'Bhf. Meidling S U', 'type' => 'ptBusCity',
 'direction' => 'H', 'platform' => '1', 'barrier_free' => true,
 'realtime_supported' => true, 'trafficjam' => false, 'alert' => false,
 'departures' => [
   ['t' => '4', 'bf' => true, 'jam' => false, 'name_override' => null, 'towards_override' => null],
   ['t' => '*', 'bf' => true, 'jam' => true,  'name_override' => null, 'towards_override' => 'Alterlaa'],
 ]]
```

---

## Task 1: Filtersemantik in `inc/board.php`

**Files:**
- Create: `inc/board.php`
- Create: `tests/Unit/BoardFilterTest.php`
- Modify: `tests/bootstrap.php` (eine Zeile)

**Interfaces:**
- Consumes: nichts (erste Aufgabe)
- Produces:
  - `board_stations_only(array $monitor): array` — Liste der Stationen, Metadaten (`alerts`, `trains`, `update_at`, `api_ping`) entfernt
  - `board_dedupe_lines(array $lines): array` — entfernt Zeilen mit gleichem `(name, platform, towards)`
  - `board_filter_station(array $station, ?array $stationFilter): ?array` — gefilterte Station, oder `null` wenn die Karte entfällt

- [ ] **Step 1: Testdatei anlegen**

`tests/Unit/BoardFilterTest.php`:

```php
<?php
// tests/Unit/BoardFilterTest.php
//
// Filtersemantik aus inc/board.php. Bildet web/js/wl-monitor.js:325-330 nach.
// Keine DB, kein Netz.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardFilterTest extends TestCase
{
    /** Eine Linienzeile im Format von monitor_get(). */
    private function line(string $name, string $platform, string $towards): array
    {
        return [
            'name' => $name, 'towards' => $towards, 'type' => 'ptTram',
            'direction' => 'H', 'platform' => $platform, 'barrier_free' => false,
            'realtime_supported' => true, 'trafficjam' => false, 'alert' => false,
            'departures' => [],
        ];
    }

    private function station(string $diva, array $lines): array
    {
        return ['id' => $diva, 'diva' => $diva, 'station_name' => 'Test', 'lines' => $lines];
    }

    // --- board_stations_only -------------------------------------------------

    public function test_stations_only_removes_metadata_keys(): void
    {
        $monitor = [
            '60200103' => $this->station('60200103', []),
            'alerts'    => [], 'trains' => 10, 'update_at' => '17:17:30', 'api_ping' => 0,
        ];
        $out = board_stations_only($monitor);
        $this->assertCount(1, $out);
        $this->assertSame('60200103', $out[0]['diva']);
    }

    public function test_stations_only_returns_a_list_not_a_map(): void
    {
        // Der Client soll nicht raten muessen, welcher Schluessel eine Station ist.
        $monitor = [
            '60200103' => $this->station('60200103', []),
            '60200937' => $this->station('60200937', []),
            'trains'   => 2,
        ];
        $this->assertSame([0, 1], array_keys(board_stations_only($monitor)));
    }

    // --- board_filter_station ------------------------------------------------

    public function test_filter_keeps_only_matching_line_and_platform(): void
    {
        $st = $this->station('60201015', [
            $this->line('62', '1', 'Lainz, Wolkersbergenstraße'),
            $this->line('62', '2', 'Quartier Belvedere S'),
            $this->line('U6', '2', 'Siebenhirten'),
        ]);
        $out = board_filter_station($st, [
            ['line' => '62', 'platform' => '2'],
            ['line' => 'U6', 'platform' => '2'],
        ]);
        $this->assertCount(2, $out['lines']);
        $this->assertSame('Quartier Belvedere S', $out['lines'][0]['towards']);
        $this->assertSame('Siebenhirten', $out['lines'][1]['towards']);
    }

    public function test_filter_matches_platform_as_string_even_if_given_as_int(): void
    {
        // filter_json kann die Plattform als Zahl enthalten; das JS vergleicht
        // ueber String(...) — hier genauso.
        $st = $this->station('1', [$this->line('62', '2', 'Stadt')]);
        $out = board_filter_station($st, [['line' => '62', 'platform' => 2]]);
        $this->assertCount(1, $out['lines']);
    }

    public function test_station_without_filter_entry_keeps_all_lines(): void
    {
        $st = $this->station('1', [
            $this->line('62', '1', 'A'), $this->line('U6', '2', 'B'),
        ]);
        $out = board_filter_station($st, null);
        $this->assertCount(2, $out['lines']);
    }

    public function test_filtered_station_stays_even_when_empty(): void
    {
        // Die einzige gefilterte Linie faehrt gerade nicht. Die Karte MUSS
        // bleiben: verschwaende sie, saehe es aus wie "alles in Ordnung".
        $st  = $this->station('1', [$this->line('62', '1', 'A')]);
        $out = board_filter_station($st, [['line' => 'U6', 'platform' => '2']]);
        $this->assertNotNull($out);
        $this->assertSame([], $out['lines']);
    }

    public function test_unfiltered_station_without_lines_is_dropped(): void
    {
        $this->assertNull(board_filter_station($this->station('1', []), null));
    }

    // --- board_dedupe_lines --------------------------------------------------

    public function test_dedupe_removes_identical_line_platform_towards(): void
    {
        // Real beobachtet am Westbahnhof: U3 Steig 1 Simmering kam doppelt.
        $lines = [
            $this->line('U3', '1', 'Simmering'),
            $this->line('U3', '1', 'Simmering'),
            $this->line('U6', '1', 'Floridsdorf'),
        ];
        $this->assertCount(2, board_dedupe_lines($lines));
    }

    public function test_dedupe_keeps_same_line_on_different_platforms(): void
    {
        $lines = [
            $this->line('U6', '1', 'Floridsdorf'),
            $this->line('U6', '2', 'Siebenhirten'),
        ];
        $this->assertCount(2, board_dedupe_lines($lines));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit --testsuite Unit --filter BoardFilterTest`
Expected: FAIL — `Error: Call to undefined function board_stations_only()`

- [ ] **Step 3: `inc/board.php` anlegen**

```php
<?php
/**
 * inc/board.php — Aufbereitung der Monitordaten für den Board-Endpunkt.
 *
 * Ausschliesslich reine Funktionen: Eingabe Daten, Ausgabe Daten. Kein Netz,
 * keine DB, keine Superglobals — damit ist die Filtersemantik ohne laufende
 * Infrastruktur testbar.
 *
 * Bildet web/js/wl-monitor.js:325-330 nach. Solange der Browser-Filter noch
 * existiert, sind das zwei Implementierungen derselben Regel; Abweichungen
 * hier wären auf dem Display unsichtbar falsch.
 */
declare(strict_types=1);

/** Schlüssel, die monitor_get() neben den Stationen in dieselbe Map legt. */
const BOARD_META_KEYS = ['alerts', 'trains', 'update_at', 'api_ping'];

/**
 * Stationen aus der monitor_get()-Map lösen — als Liste, nicht als Map.
 *
 * monitor_get() mischt Stationen (Schlüssel = DIVA) und Metadaten in einer
 * flachen Struktur. Ein Client müsste sonst raten, was eine Station ist.
 *
 * @return list<array{id: string, diva: string, station_name: string, lines: array}>
 */
function board_stations_only(array $monitor): array
{
    $out = [];
    foreach ($monitor as $key => $value) {
        if (in_array($key, BOARD_META_KEYS, true)) continue;
        if (!is_array($value) || !isset($value['lines']) || !is_array($value['lines'])) continue;
        $out[] = $value;
    }
    return $out;
}

/**
 * Zeilen entdoppeln. Identität ist (Linie, Steig, Ziel).
 *
 * Am Westbahnhof liefert die Kette "U3 Steig 1 Simmering" zweimal (beobachtet
 * 2026-08-01). Woher das kommt, ist offen — auf dem Display wäre es eine
 * doppelte Zeile, also wird hier entdoppelt.
 */
function board_dedupe_lines(array $lines): array
{
    $gesehen = [];
    $out     = [];
    foreach ($lines as $l) {
        $key = ($l['name'] ?? '') . "\0" . ($l['platform'] ?? '') . "\0" . ($l['towards'] ?? '');
        if (isset($gesehen[$key])) continue;
        $gesehen[$key] = true;
        $out[] = $l;
    }
    return $out;
}

/**
 * Filter einer Haltestelle anwenden.
 *
 * $stationFilter ist die Positivliste dieser DIVA aus filter_json, oder null,
 * wenn die DIVA dort NICHT vorkommt — dann gilt: kein Filter, alle Linien.
 *
 * Rückgabe null bedeutet: Karte entfällt. Das passiert NUR bei ungefilterten
 * Stationen ohne Linien. Eine gefilterte Station bleibt auch leer stehen —
 * verschwände sie, sähe der Ausfall ihrer einzigen Linie aus wie Normalbetrieb.
 *
 * @param ?list<array{line: string, platform: string|int}> $stationFilter
 */
function board_filter_station(array $station, ?array $stationFilter): ?array
{
    $lines = board_dedupe_lines($station['lines'] ?? []);

    if ($stationFilter !== null) {
        $lines = array_values(array_filter($lines, static function (array $l) use ($stationFilter): bool {
            foreach ($stationFilter as $f) {
                if (($f['line'] ?? null) === ($l['name'] ?? null)
                    && (string) ($f['platform'] ?? '') === (string) ($l['platform'] ?? '')) {
                    return true;
                }
            }
            return false;
        }));
    } elseif ($lines === []) {
        return null;
    }

    $station['lines'] = $lines;
    return $station;
}
```

- [ ] **Step 4: `inc/board.php` im Test-Bootstrap laden**

In `tests/bootstrap.php` nach der Zeile `require_once __DIR__ . '/../inc/monitor.php';` einfügen:

```php
require_once __DIR__ . '/../inc/board.php';
```

- [ ] **Step 5: Tests laufen lassen**

Run: `vendor/bin/phpunit --testsuite Unit --filter BoardFilterTest`
Expected: PASS — 8 Tests, 0 Fehler

- [ ] **Step 6: Committen**

```bash
git add inc/board.php tests/Unit/BoardFilterTest.php tests/bootstrap.php
git commit -m "feat(board): serverseitige Favoritenfilterung (rein, testbar)"
```

---

## Task 2: Antwortform in `inc/board.php`

**Files:**
- Modify: `inc/board.php` (anhängen)
- Create: `tests/Unit/BoardShapeTest.php`

**Interfaces:**
- Consumes: `board_stations_only()`, `board_filter_station()` aus Task 1
- Produces:
  - `board_type(string $wlType): string` — `ptMetro`→`metro`, `ptTram*`→`tram`, `ptBus*`→`bus`, `ptTrain*`→`train`, sonst `other`
  - `board_departure(array $dep): array` — `{'in': int, 'towards'?: string, 'line'?: string, 'delayed'?: true}`
  - `board_line(array $line): array` — `{line, platform, towards, type, realtime, alert, departures}`
  - `board_favorite(array $fav, array $monitor): array` — `{id, title, stations: [...]}`

- [ ] **Step 1: Testdatei anlegen**

`tests/Unit/BoardShapeTest.php`:

```php
<?php
// tests/Unit/BoardShapeTest.php
//
// Antwortform des Board-Endpunkts. Keine DB, kein Netz.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardShapeTest extends TestCase
{
    private function dep(string $t, bool $jam = false, ?string $tow = null, ?string $name = null): array
    {
        return ['t' => $t, 'bf' => false, 'jam' => $jam,
                'name_override' => $name, 'towards_override' => $tow];
    }

    // --- board_type ----------------------------------------------------------

    public function test_type_is_normalised(): void
    {
        $this->assertSame('metro', board_type('ptMetro'));
        $this->assertSame('tram',  board_type('ptTram'));
        $this->assertSame('tram',  board_type('ptTramWLB'));
        $this->assertSame('bus',   board_type('ptBusCity'));
        $this->assertSame('bus',   board_type('ptBusNight'));
        $this->assertSame('train', board_type('ptTrainS'));
        $this->assertSame('other', board_type(''));
    }

    // --- board_departure -----------------------------------------------------

    public function test_departure_minutes_are_integers(): void
    {
        $this->assertSame(7, board_departure($this->dep('7'))['in']);
    }

    public function test_departure_star_becomes_zero(): void
    {
        // Die alte API kodiert "faehrt jetzt" als String "*".
        $this->assertSame(0, board_departure($this->dep('*'))['in']);
    }

    public function test_departure_without_deviation_has_only_in(): void
    {
        $this->assertSame(['in' => 7], board_departure($this->dep('7')));
    }

    public function test_departure_carries_deviating_destination(): void
    {
        // Kurzgefuehrte U6: die Zeile sagt Siebenhirten, dieser Zug endet in
        // Alterlaa. Ohne dieses Feld waere die Anzeige falsch, nicht nur leer.
        $d = board_departure($this->dep('7', false, 'Alterlaa'));
        $this->assertSame('Alterlaa', $d['towards']);
    }

    public function test_departure_carries_deviating_line_name(): void
    {
        $this->assertSame('6E', board_departure($this->dep('3', false, null, '6E'))['line']);
    }

    public function test_delayed_departure_is_flagged(): void
    {
        // Das Display invertiert genau diese Abfahrt (weiss auf rot).
        $this->assertTrue(board_departure($this->dep('4', true))['delayed']);
    }

    public function test_undelayed_departure_has_no_delayed_key(): void
    {
        $this->assertArrayNotHasKey('delayed', board_departure($this->dep('4', false)));
    }

    // --- board_line ----------------------------------------------------------

    public function test_line_drops_unused_fields(): void
    {
        $line = ['name' => 'U6', 'towards' => 'Siebenhirten', 'type' => 'ptMetro',
                 'direction' => 'H', 'platform' => '2', 'barrier_free' => true,
                 'realtime_supported' => true, 'trafficjam' => false, 'alert' => false,
                 'departures' => [$this->dep('2')]];
        $out = board_line($line);
        $this->assertSame(
            ['line', 'platform', 'towards', 'type', 'realtime', 'alert', 'departures'],
            array_keys($out)
        );
        $this->assertArrayNotHasKey('direction', $out);
        $this->assertArrayNotHasKey('barrier_free', $out);
    }

    public function test_line_reports_missing_realtime(): void
    {
        $line = ['name' => '8A', 'towards' => 'X', 'type' => 'ptBusCity', 'platform' => '1',
                 'realtime_supported' => false, 'alert' => false, 'departures' => []];
        $this->assertFalse(board_line($line)['realtime']);
    }

    // --- board_favorite ------------------------------------------------------

    public function test_favorite_shapes_stations_as_list(): void
    {
        $monitor = [
            '60201015' => ['id' => '60201015', 'diva' => '60201015',
                'station_name' => 'Bhf. Meidling S U', 'lines' => [
                    ['name' => 'U6', 'towards' => 'Siebenhirten', 'type' => 'ptMetro',
                     'platform' => '2', 'realtime_supported' => true, 'alert' => false,
                     'departures' => [$this->dep('2'), $this->dep('7', false, 'Alterlaa')]],
                    ['name' => '62', 'towards' => 'Lainz', 'type' => 'ptTram',
                     'platform' => '1', 'realtime_supported' => true, 'alert' => false,
                     'departures' => [$this->dep('3')]],
                ]],
            'trains' => 3, 'update_at' => '17:17:30', 'api_ping' => 0, 'alerts' => [],
        ];
        $fav = ['id' => 12, 'title' => 'Arbeit', 'diva' => '60201015',
                'filter' => ['60201015' => [['line' => 'U6', 'platform' => '2']]]];

        $out = board_favorite($fav, $monitor);

        $this->assertSame(12, $out['id']);
        $this->assertSame('Arbeit', $out['title']);
        $this->assertCount(1, $out['stations']);
        $this->assertSame('Bhf. Meidling S U', $out['stations'][0]['name']);
        $this->assertCount(1, $out['stations'][0]['lines']);       // 62 herausgefiltert
        $this->assertSame('U6', $out['stations'][0]['lines'][0]['line']);
        $this->assertSame(
            [['in' => 2], ['in' => 7, 'towards' => 'Alterlaa']],
            $out['stations'][0]['lines'][0]['departures']
        );
    }

    public function test_favorite_without_filter_keeps_every_line(): void
    {
        $monitor = ['1' => ['id' => '1', 'diva' => '1', 'station_name' => 'X', 'lines' => [
            ['name' => 'A', 'towards' => 'T', 'type' => 'ptTram', 'platform' => '1',
             'realtime_supported' => true, 'alert' => false, 'departures' => []],
        ]]];
        $fav = ['id' => 1, 'title' => 'Alles', 'diva' => '1', 'filter' => null];
        $this->assertCount(1, board_favorite($fav, $monitor)['stations'][0]['lines']);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit --testsuite Unit --filter BoardShapeTest`
Expected: FAIL — `Error: Call to undefined function board_type()`

- [ ] **Step 3: Funktionen an `inc/board.php` anhängen**

```php
// ───────────────────────────────────────────────────────────────────────────
// Antwortform
// ───────────────────────────────────────────────────────────────────────────

/**
 * Verkehrsmittel-Typ der WL-API auf vier Werte normalisieren.
 * Die WL-Kürzel (ptMetro, ptBusCity, ptTramWLB …) sind Aufrufer-Ballast.
 */
function board_type(string $wlType): string
{
    if (str_starts_with($wlType, 'ptMetro')) return 'metro';
    if (str_starts_with($wlType, 'ptTram'))  return 'tram';
    if (str_starts_with($wlType, 'ptBus'))   return 'bus';
    if (str_starts_with($wlType, 'ptTrain')) return 'train';
    return 'other';
}

/**
 * Eine Abfahrt in die schlanke Form bringen.
 *
 * 'in' ist eine Zahl in Minuten; "fährt jetzt" ist 0 (die alte API kodiert
 * das als String '*').
 *
 * 'towards' und 'line' erscheinen NUR bei Abweichung von der Zeile
 * (Kurzführung, Ersatzverkehr). Ohne sie zeigte das Display „U6 →
 * Siebenhirten in 7 min", während der Zug in Alterlaa endet — eine falsche
 * Zeile ist schlimmer als eine fehlende.
 *
 * 'delayed' markiert die Abfahrt, die das Display invertiert darstellt.
 *
 * @return array{in: int, towards?: string, line?: string, delayed?: true}
 */
function board_departure(array $dep): array
{
    $t   = (string) ($dep['t'] ?? '0');
    $out = ['in' => $t === '*' ? 0 : (int) $t];

    if (!empty($dep['towards_override'])) $out['towards'] = (string) $dep['towards_override'];
    if (!empty($dep['name_override']))    $out['line']    = (string) $dep['name_override'];
    if (!empty($dep['jam']))              $out['delayed'] = true;

    return $out;
}

/**
 * Eine Linienzeile in die schlanke Form bringen.
 *
 * Weggelassen: direction, barrier_free, trafficjam — weder das Display noch
 * Home Assistant benutzen sie. platform BLEIBT: es ist der Filterschlüssel
 * und die stabile Identität der Zeile.
 */
function board_line(array $line): array
{
    return [
        'line'       => (string) ($line['name'] ?? ''),
        'platform'   => (string) ($line['platform'] ?? ''),
        'towards'    => (string) ($line['towards'] ?? ''),
        'type'       => board_type((string) ($line['type'] ?? '')),
        'realtime'   => (bool) ($line['realtime_supported'] ?? true),
        'alert'      => (bool) ($line['alert'] ?? false),
        'departures' => array_map('board_departure', $line['departures'] ?? []),
    ];
}

/**
 * Einen Favoriten samt seiner Haltestellen in die Antwortform bringen.
 *
 * $fav ist eine Zeile aus favorites_get(): ['id', 'title', 'diva', 'filter'].
 * $monitor ist die unveränderte Ausgabe von monitor_get().
 */
function board_favorite(array $fav, array $monitor): array
{
    $filter   = is_array($fav['filter'] ?? null) ? $fav['filter'] : [];
    $stations = [];

    foreach (board_stations_only($monitor) as $station) {
        $diva     = (string) ($station['diva'] ?? '');
        $gefiltert = board_filter_station($station, $filter[$diva] ?? null);
        if ($gefiltert === null) continue;

        $stations[] = [
            'diva'  => $diva,
            'name'  => (string) ($gefiltert['station_name'] ?? ''),
            'lines' => array_map('board_line', $gefiltert['lines']),
        ];
    }

    return ['id' => (int) $fav['id'], 'title' => (string) $fav['title'], 'stations' => $stations];
}
```

- [ ] **Step 4: Tests laufen lassen**

Run: `vendor/bin/phpunit --testsuite Unit --filter BoardShapeTest`
Expected: PASS — 12 Tests, 0 Fehler

- [ ] **Step 5: Gesamte Unit-Suite laufen lassen (nichts kaputtgemacht?)**

Run: `vendor/bin/phpunit --testsuite Unit`
Expected: PASS, inklusive der bestehenden `CsrfTest`, `MonitorParserTest`, `SanitizeTest`

- [ ] **Step 6: Committen**

```bash
git add inc/board.php tests/Unit/BoardShapeTest.php
git commit -m "feat(board): schlanke Antwortform (Minuten als Zahlen, Abweichungen pro Abfahrt)"
```

---

## Task 3: `auth_bootstrap()` startet für Token-Anfragen keine Session

**Files:**
- Modify: `../auth/src/apitoken.php` (eine Funktion herauslösen)
- Modify: `../auth/src/bootstrap.php:78` (Bedingung um `session_start()`)
- Modify: `../auth/tests/Unit/ApiTokenTest.php` (Tests anhängen)

**Interfaces:**
- Consumes: nichts aus früheren Tasks
- Produces: `auth_api_token_from_request(): string` — das vorgelegte Token aus
  `Authorization: Bearer` bzw. `X-Auth-Token`, oder `''`

**Warum das hierher gehört:** `auth_bootstrap()` ruft `session_start()`
**bedingungslos** (`bootstrap.php:78`) — und zwar *bevor* das Token ausgewertet
wird (Zeile 118). Ein Gerät, das alle zwei Minuten pollt, erzeugt damit trotz
Token-Auth weiter eine Session je Anfrage mitsamt `Set-Cookie` (4 Tage
Lebensdauer). Das ist genau der Befund, der `monitor_json.php` betrifft
(875 → 876 Sessiondateien pro Aufruf, gemessen 2026-08-01) — er liegt aber
nicht in der App, sondern in der Bibliothek. Eine Lösung pro App wäre
siebenfach dieselbe Notlösung (Suite-Policy §6: Library-first).

**Was sich dadurch ändert:** Anfragen **mit** Token-Header bekommen keine
Session mehr. Das ist die dokumentierte Semantik des Token-Systems („presenting
one never writes to `$_SESSION`", `apitoken.php:7-9`) — nur eben bisher nicht
durchgezogen. Endpunkte, die ein Token akzeptieren, dürfen folglich **kein**
`csrf_verify()` aufrufen: CSRF-Token brauchen eine Session, und ein Bearer-Token
ist gegen CSRF ohnehin immun (ein Browser schickt es nicht automatisch mit).

- [ ] **Step 1: Tests an `../auth/tests/Unit/ApiTokenTest.php` anhängen**

```php
    // ── Token-Erkennung vor dem Session-Start (Board-Endpunkt) ──────────────

    public function test_token_from_request_reads_bearer_header(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
        $this->assertSame('abc123', \auth_api_token_from_request());
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function test_token_from_request_reads_fallback_header(): void
    {
        // Manche Proxy-/FPM-Setups verschlucken Authorization.
        $_SERVER['HTTP_X_AUTH_TOKEN'] = 'def456';
        $this->assertSame('def456', \auth_api_token_from_request());
        unset($_SERVER['HTTP_X_AUTH_TOKEN']);
    }

    public function test_token_from_request_ignores_other_auth_schemes(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
        $this->assertSame('', \auth_api_token_from_request());
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function test_token_from_request_is_empty_without_headers(): void
    {
        $this->assertSame('', \auth_api_token_from_request());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_bootstrap_starts_no_session_for_token_requests(): void
    {
        // Der Kern: ein pollendes Geraet darf keine Sessiondateien erzeugen.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer irgendwas';
        @\auth_bootstrap();
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_bootstrap_still_starts_a_session_without_token(): void
    {
        // Gegenprobe: die Web-Apps duerfen sich NICHT aendern.
        @\auth_bootstrap();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }
```

- [ ] **Step 2: Tests laufen lassen, Fehlschlag bestätigen**

Run: `cd ../auth && vendor/bin/phpunit --testsuite Unit --filter ApiTokenTest`
Expected: FAIL — `Error: Call to undefined function auth_api_token_from_request()`

- [ ] **Step 3: Funktion in `../auth/src/apitoken.php` herauslösen**

Vor `auth_apply_api_token()` einfügen:

```php
/**
 * Das vorgelegte Token aus den Anfrage-Headern, oder '' wenn keines da ist.
 *
 * Herausgelöst, damit auth_bootstrap() die Frage „ist das eine
 * Token-Anfrage?" beantworten kann, BEVOR es eine Session startet — ohne
 * Datenbank und ohne Seiteneffekt.
 */
function auth_api_token_from_request(): string
{
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
        return $m[1];
    }
    if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        return (string) $_SERVER['HTTP_X_AUTH_TOKEN'];
    }
    return '';
}
```

Und `auth_apply_api_token()` auf die neue Funktion umstellen — die
Header-Auswertung steht dann nur noch an einer Stelle:

```php
function auth_apply_api_token(mysqli $con): bool
{
    $tok = auth_api_token_from_request();
    if ($tok === '') return false; // no token header → web app unchanged
```

(Die vier Zeilen darüber, die `$auth`/`$m`/`HTTP_X_AUTH_TOKEN` selbst lesen,
entfallen.)

- [ ] **Step 4: `session_start()` in `../auth/src/bootstrap.php` bedingt machen**

`session_start($sessionOpts);` (Zeile 78) und den daran hängenden
Wiederherstellungsblock in eine Bedingung setzen:

```php
    // Token-Anfragen bekommen KEINE Session. Ein Bearer-Token ist per Design
    // sitzungslos ("presenting one never writes to $_SESSION", apitoken.php);
    // ohne diese Bedingung legte jede Anfrage eines pollenden Geraets
    // trotzdem eine Sessiondatei an (4 Tage Lebensdauer) und schickte ein
    // Set-Cookie zurueck.
    $istTokenAnfrage = function_exists('auth_api_token_from_request')
        && auth_api_token_from_request() !== '';

    if (!$istTokenAnfrage) {
        session_start($sessionOpts);
        // … bestehender Block zur Wiederherstellung ueber die sId-Cookie …
    }
```

- [ ] **Step 5: Tests laufen lassen**

Run: `cd ../auth && vendor/bin/phpunit --testsuite Unit`
Expected: PASS — die bisherigen Tests unverändert plus 6 neue

- [ ] **Step 6: Gegenprobe — die sieben Apps dürfen sich nicht ändern**

```bash
cd ../suche      && vendor/bin/phpunit 2>&1 | tail -2
cd ../biblio     && php tests/harness.php 2>&1 | tail -1
cd ../simplechat && php tests/run.php 2>&1 | tail -1
cd ../last.fm    && php tests/run.php 2>&1 | tail -1
cd ../wlmonitor  && vendor/bin/phpunit --testsuite Unit 2>&1 | tail -2
```

Expected: alle grün. Diese Änderung liegt in einer Bibliothek, die sieben Apps
laden — ohne diesen Schritt ist sie nicht abgesichert.

- [ ] **Step 7: Committen (im auth-Repo)**

```bash
cd ../auth
git add src/apitoken.php src/bootstrap.php tests/Unit/ApiTokenTest.php
git commit -m "fix(bootstrap): keine Session fuer Token-Anfragen

auth_bootstrap() rief session_start() bedingungslos, und zwar bevor das Token
ueberhaupt ausgewertet wurde. Ein pollendes Geraet erzeugte damit trotz
Token-Auth eine Sessiondatei je Anfrage (Cookie 4 Tage) — bei zwei Minuten
Takt sind das 720 pro Tag. Gemessen an monitor_json.php: 875 -> 876 Dateien
durch einen einzigen curl-Aufruf.

Die Header-Auswertung steckte in auth_apply_api_token() und lief damit erst
NACH dem Session-Start. Sie ist jetzt als auth_api_token_from_request()
herausgeloest und ohne DB-Zugriff aufrufbar.

Folge fuer Endpunkte, die Token akzeptieren: kein csrf_verify() — CSRF-Token
brauchen eine Session, und ein Bearer-Token ist gegen CSRF ohnehin immun."
```

---

## Task 4: Endpunkt `web/board.php`

**Files:**
- Create: `web/board.php`
- Create: `tests/Unit/BoardEndpointTest.php`

**Interfaces:**
- Consumes: `board_favorite()` aus Task 2; `favorites_get()` aus `inc/favorites.php`; `monitor_get()` aus `inc/monitor.php`; `auth_api_request_user()` / `auth_api_token_presented()` aus `erikr/auth`
- Produces:
  - `board_selected_favorites(array $alle, string $favParam): array` — Favoriten nach `?fav=` filtern, fremde/unbekannte IDs still ignorieren
  - `board_all_divas(array $favs): string` — kommaseparierte DIVA-Liste über alle gewählten Favoriten, entdoppelt

- [ ] **Step 1: Testdatei anlegen**

`tests/Unit/BoardEndpointTest.php`:

```php
<?php
// tests/Unit/BoardEndpointTest.php
//
// Auswahl- und Sammel-Logik des Board-Endpunkts. Rein, ohne HTTP.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardEndpointTest extends TestCase
{
    private function favs(): array
    {
        return [
            ['id' => 3,  'title' => 'Arbeit',    'diva' => '60200103,60200470', 'filter' => null],
            ['id' => 7,  'title' => '➙ Stadt',   'diva' => '60200368,60200470', 'filter' => null],
            ['id' => 11, 'title' => 'Oberlaa',   'diva' => '60200999',          'filter' => null],
        ];
    }

    public function test_selection_returns_requested_ids_in_requested_order(): void
    {
        $out = board_selected_favorites($this->favs(), '7,3');
        $this->assertSame([7, 3], array_column($out, 'id'));
    }

    public function test_selection_ignores_unknown_ids_silently(): void
    {
        // Fremde IDs duerfen keine Auskunft ueber fremde Datensaetze geben —
        // also weder Fehler noch Unterschied in der Antwort.
        $out = board_selected_favorites($this->favs(), '3,9999');
        $this->assertSame([3], array_column($out, 'id'));
    }

    public function test_empty_parameter_returns_all_favorites_in_order(): void
    {
        $this->assertSame([3, 7, 11], array_column(board_selected_favorites($this->favs(), ''), 'id'));
    }

    public function test_selection_ignores_non_numeric_input(): void
    {
        $out = board_selected_favorites($this->favs(), "3,';DROP TABLE wl_favorites;--");
        $this->assertSame([3], array_column($out, 'id'));
    }

    public function test_divas_are_collected_and_deduplicated(): void
    {
        // 60200470 kommt in beiden Favoriten vor — die WL-API soll sie einmal
        // bekommen, nicht zweimal.
        $sel = board_selected_favorites($this->favs(), '3,7');
        $this->assertSame('60200103,60200470,60200368', board_all_divas($sel));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit --testsuite Unit --filter BoardEndpointTest`
Expected: FAIL — `Error: Call to undefined function board_selected_favorites()`

- [ ] **Step 3: Die beiden reinen Funktionen an `inc/board.php` anhängen**

```php
// ───────────────────────────────────────────────────────────────────────────
// Auswahl
// ───────────────────────────────────────────────────────────────────────────

/**
 * Favoriten nach dem ?fav=-Parameter auswählen.
 *
 * $alle ist die Liste aus favorites_get() — sie enthält bereits NUR die
 * Favoriten des Token-Benutzers. Unbekannte IDs werden still übergangen:
 * ein Fehler oder eine abweichende Antwort verriete, ob eine fremde ID
 * existiert.
 *
 * Leerer Parameter → alle, in der Reihenfolge von favorites_get() (sort, id).
 */
function board_selected_favorites(array $alle, string $favParam): array
{
    $favParam = trim($favParam);
    if ($favParam === '') return $alle;

    $nachId = [];
    foreach ($alle as $f) $nachId[(int) $f['id']] = $f;

    $out = [];
    foreach (explode(',', $favParam) as $roh) {
        $roh = trim($roh);
        if ($roh === '' || !ctype_digit($roh)) continue;
        $id = (int) $roh;
        if (isset($nachId[$id])) $out[] = $nachId[$id];
    }
    return $out;
}

/**
 * Alle DIVAs der gewählten Favoriten als kommaseparierte Liste, entdoppelt.
 * Eine Haltestelle, die in zwei Favoriten vorkommt, wird bei der WL-API nur
 * einmal angefragt.
 */
function board_all_divas(array $favs): string
{
    $divas = [];
    foreach ($favs as $f) {
        foreach (explode(',', (string) ($f['diva'] ?? '')) as $d) {
            $d = preg_replace('/[^0-9]/', '', $d);
            if ($d !== '' && !in_array($d, $divas, true)) $divas[] = $d;
        }
    }
    return implode(',', $divas);
}
```

- [ ] **Step 4: Tests laufen lassen**

Run: `vendor/bin/phpunit --testsuite Unit --filter BoardEndpointTest`
Expected: PASS — 5 Tests, 0 Fehler

- [ ] **Step 5: `web/board.php` anlegen**

```php
<?php
/**
 * web/board.php — JSON-Abfahrtstafel für Geräte (E-Paper-Display, Home
 * Assistant), token-authentifiziert.
 *
 * GET /board.php?fav=<id>[,<id>…]
 * Authorization: Bearer <token>      (X-Auth-Token als Ausweichheader)
 *
 * Bewusst KEINE Sitzung: nichts wird aus $_SESSION gelesen oder dorthin
 * geschrieben. Alles, was die Antwort bestimmt, hängt am Token-Benutzer.
 * Der Vorgänger monitor_json.php tat das Gegenteil und legte bei jedem
 * anonymen Aufruf eine Session an (4 Tage Lebensdauer).
 *
 * Fehler nennen nach aussen nur eine Kennung; die Ursache geht ins auth_log
 * (Fehler-Regeln §21).
 *
 * Spec: docs/superpowers/specs/2026-08-01-epaper-abfahrtsmonitor-design.md
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/initialize.php';   // ruft auth_bootstrap(), löst das Token auf
require_once __DIR__ . '/../inc/favorites.php';
require_once __DIR__ . '/../inc/monitor.php';
require_once __DIR__ . '/../inc/board.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Antwort senden und beenden. */
function board_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

$userId = auth_api_request_user();
if ($userId === null) {
    appendLog($con, 'board', 'Zugriff ohne gueltiges Token'
        . (auth_api_token_presented() ? ' (Token vorgelegt, aber ungueltig)' : ' (kein Token)'));
    board_out(['error' => 'unauthorized'], 401);
}

try {
    $favs = board_selected_favorites(favorites_get($con, $userId), (string) ($_GET['fav'] ?? ''));
    if ($favs === []) {
        board_out(['generated' => date('c'), 'favorites' => []]);
    }

    // Zwei Abfahrten je Zeile — genau das, was das Layout zeigt. Mehr waere
    // unbenutzte Nutzlast auf einem Geraet mit wenig Heap.
    $monitor = monitor_get($con, board_all_divas($favs), 2);

    $out = ['generated' => date('c'), 'favorites' => []];
    foreach ($favs as $fav) {
        $out['favorites'][] = board_favorite($fav, $monitor);
    }
    board_out($out);
} catch (RuntimeException | InvalidArgumentException $e) {
    appendLog($con, 'board', 'Upstream-Fehler: ' . $e->getMessage());
    board_out(['error' => 'upstream_unavailable'], 503);
} catch (Throwable $e) {
    appendLog($con, 'board', 'Fehler: ' . get_class($e) . ': ' . $e->getMessage());
    board_out(['error' => 'server_error'], 500);
}
```

- [ ] **Step 6: Endpunkt von Hand prüfen — ohne Token**

```bash
php -S 127.0.0.1:8123 -t web &
sleep 1
curl -s -o /dev/null -w 'HTTP %{http_code}\n' 'http://127.0.0.1:8123/board.php'
kill %1
```

Expected: `HTTP 401`

Hinweis: **ohne** Token startet `auth_bootstrap()` weiterhin eine Session (so
soll es sein — das ist der Web-Pfad), es kommt also ein `Set-Cookie`. Die
Session-Freiheit wird im nächsten Schritt geprüft, wo sie hingehört: bei einer
Token-Anfrage.

- [ ] **Step 7: Endpunkt von Hand prüfen — mit Token**

Token in `web/profil.php` anlegen (Abschnitt „API-Token"), dann:

```bash
php -S 127.0.0.1:8123 -t web &
sleep 1
curl -s -H "Authorization: Bearer $TOKEN" 'http://127.0.0.1:8123/board.php?fav=3' | head -c 400
echo
curl -sI -H "Authorization: Bearer $TOKEN" 'http://127.0.0.1:8123/board.php?fav=3' \
  | grep -qi '^set-cookie' && echo 'FEHLER: Set-Cookie trotz Token' || echo 'kein Set-Cookie (richtig)'
kill %1
```

Expected: JSON mit `generated` (ISO 8601 mit Zone) und
`favorites[0].stations[].lines[].departures[].in` als Zahl — sowie
`kein Set-Cookie (richtig)`. Letzteres belegt Task 3: ohne jene Änderung käme
hier trotz Token eine Session zurück.

- [ ] **Step 8: Committen**

```bash
git add web/board.php inc/board.php tests/Unit/BoardEndpointTest.php
git commit -m "feat(board): token-geschuetzter Endpunkt web/board.php"
```

---

## Task 5: `monitor_json.php` härten

**Files:**
- Modify: `web/monitor_json.php` (komplett ersetzen, 25 Zeilen)
- Create: `tests/Unit/MonitorJsonShapeTest.php`

**Interfaces:**
- Consumes: `auth_api_request_user()`, `monitor_get()`
- Produces: nichts für spätere Aufgaben

Der Test sichert ab, was hier **nicht** passieren darf: eine Formänderung. Home Assistant hängt daran.

- [ ] **Step 1: Test anlegen, der die Form festnagelt**

`tests/Unit/MonitorJsonShapeTest.php`:

```php
<?php
// tests/Unit/MonitorJsonShapeTest.php
//
// monitor_json.php wird abgesichert, ohne seine Antwortform zu aendern —
// Home Assistant parst sie. Dieser Test nagelt die Form fest.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class MonitorJsonShapeTest extends TestCase
{
    public function test_source_still_emits_the_unchanged_structure(): void
    {
        $src = file_get_contents(__DIR__ . '/../../web/monitor_json.php');

        // Die Ausgabe ist unveraendert monitor_get() — keine Umformung.
        $this->assertStringContainsString('monitor_get(', $src);
        $this->assertMatchesRegularExpression('/echo\s+json_encode\(\s*\$data/', $src);

        // Und die Haertung ist da:
        $this->assertStringContainsString('auth_api_request_user', $src,
            'Token-Pflicht fehlt');
        $this->assertStringNotContainsString('$_SESSION', $src,
            'Der Endpunkt darf keine Sitzung mehr anfassen');
        $this->assertStringNotContainsString('$e->getMessage()', $src,
            'Interne Fehlertexte duerfen nicht nach aussen');
        $this->assertStringContainsString('appendLog(', $src,
            'Jeder Fehlerpfad muss loggen (§21)');
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit --testsuite Unit --filter MonitorJsonShapeTest`
Expected: FAIL — „Token-Pflicht fehlt"

- [ ] **Step 3: `web/monitor_json.php` ersetzen**

```php
<?php
/**
 * web/monitor_json.php — JSON-Abfahrtsfeed für Home Assistant.
 *
 * Die Antwortform ist bewusst UNVERÄNDERT (Home Assistant parst sie); nur die
 * Anfrage hat sich geändert: sie braucht jetzt ein Token.
 *
 * Vorher war dieser Endpunkt anonym erreichbar und damit ein offener Proxy auf
 * die Wiener-Linien-API zu Lasten unseres Kontingents. Der Kopfkommentar
 * behauptete ein Rate-Limit, das es nie gab (RATE_LIMIT_FILE wird definiert,
 * aber nirgends benutzt). Ausserdem legte jeder Aufruf eine PHP-Session an
 * (Cookie 4 Tage) und die Antwort hing an $_SESSION des Aufrufers.
 *
 * Für neue Clients: web/board.php (schlankere Form).
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/monitor.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$userId = auth_api_request_user();
if ($userId === null) {
    appendLog($con, 'monitor_json', 'Zugriff ohne gueltiges Token');
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

// Anzahl der Abfahrten aus den Einstellungen des TOKEN-Benutzers — nicht aus
// der Sitzung eines zufaelligen Aufrufers.
$maxDep = MAX_DEPARTURES;
$stmt = $con->prepare('SELECT departures FROM wl_preferences WHERE idUser = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) {
    $maxDep = max(1, (int) $row['departures']);
}
$stmt->close();

$diva = sanitizeDivaInput((string) ($_GET['diva'] ?? '60200103'));

try {
    $data = monitor_get($con, $diva, $maxDep);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    appendLog($con, 'monitor_json', 'Fehler: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'upstream_unavailable']);
}
```

- [ ] **Step 4: Tests laufen lassen**

Run: `vendor/bin/phpunit --testsuite Unit`
Expected: PASS — alle Suiten

- [ ] **Step 5: Formgleichheit gegen die alte Fassung belegen**

```bash
git stash push web/monitor_json.php
php -S 127.0.0.1:8124 -t web & sleep 1
curl -s 'http://127.0.0.1:8124/monitor_json.php?diva=60201015' | python3 -c 'import json,sys; print(sorted(json.load(sys.stdin).keys()))' > /tmp/alt.txt
kill %1
git stash pop
php -S 127.0.0.1:8124 -t web & sleep 1
curl -s -H "Authorization: Bearer $TOKEN" 'http://127.0.0.1:8124/monitor_json.php?diva=60201015' | python3 -c 'import json,sys; print(sorted(json.load(sys.stdin).keys()))' > /tmp/neu.txt
kill %1
diff /tmp/alt.txt /tmp/neu.txt && echo 'Form identisch'
```

Expected: `Form identisch`

- [ ] **Step 6: Committen**

```bash
git add web/monitor_json.php tests/Unit/MonitorJsonShapeTest.php
git commit -m "fix(monitor_json): Token-Pflicht, keine Session, keine internen Fehlertexte"
```

---

## Nach dem letzten Task

**Home Assistant bricht ab dem Deploy**, bis dort ein Header ergänzt ist:

```yaml
rest:
  - resource: https://wlmonitor.jardyx.com/monitor_json.php?diva=60201015
    headers:
      Authorization: !secret wlmonitor_token
```

Das Token wird in `web/profil.php` erzeugt (Abschnitt „API-Token"). **Vor** dem Deploy anlegen, nicht danach.

## Bewusst nicht Teil dieses Plans

- Die automatisierte Gegenprobe „`wl-monitor.js` und `inc/board.php` liefern dasselbe", die die Spec in §10 nennt. Der Browser-Filter steckt mitten in `renderMonitor()` und ist nicht einzeln aufrufbar; ihn herauszulösen gehört zur späteren UI-Umstellung. Bis dahin tragen die Tests aus Task 1 die JS-Semantik **mit Zeilenverweis** nach (`wl-monitor.js:325-330`).
- Umstellung des Web-UI auf die Serverfilterung.
- Zwischenspeichern der WL-Antwort.
- Die Firmware (eigener Plan).
