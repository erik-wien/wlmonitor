# Wetter-Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ORF-Wetterdaten für Wien-Hohe Warte alle 3 Stunden abrufen, in einen
Datei-Cache schreiben und daraus die für die Anzeige richtige Tages-Scheibe
(heute/morgen, Cutover 19:00) inklusive Staleness-Fehlermeldung auswählen —
vollständig ohne das neue Board-Display, testbar mit gespeicherten
HTML-Fixtures.

**Architektur:** Drei reine, unabhängig testbare Funktionen in `inc/weather.php`
(Parsen, Icon-Code-Mapping, Anzeige-Auswahl) plus ein dünner Cron-Einstiegspunkt
(`scripts/weather_fetch_cron.php`), der HTTP-Abruf, Parsing und atomares
Schreiben von `data/weather_cache.json` verdrahtet. Kein Netzzugriff in den
Tests — der Cron-Skript-Teil selbst bleibt bewusst ungetestet (dünnes Glue-Code,
analog zu `web/board.php` in v1), die Logik dahinter ist vollständig in reinen
Funktionen gekapselt.

**Tech Stack:** PHP 8.2, `ext-dom`/`DOMXPath` fürs HTML-Parsen, PHPUnit
(`tests/Unit`), kein Framework, kein Composer-Zusatzpaket.

## Global Constraints

- Quelle: `https://wetter.orf.at/wien/prognose` (Desktop-Version, **nicht** `/m/`).
- Station: **Wien-Hohe Warte**, ausgewählt über `th.legendCol`-Text, nicht über
  Tabellenposition.
- Icon/Temperatur und Text werden **positional** ausgewählt (1./2. Spalte bzw.
  1./2. `<h2>`/`<p>`-Paar), nicht über den Überschriftentext.
- Cutover **19:00 Europe/Vienna**: davor `today`-Werte, danach `tomorrow`-Werte.
- Cache-Alter **> 6 h** → Fließtext wird `null` (Aufrufer zeigt stattdessen
  `text_error`); Icon/Temperatur bleiben unverändert.
- Kein Cache vorhanden (Erstinbetriebnahme) → `available => false`.
- Fehlerfälle beim Abruf/Parsen: alter Cache-Inhalt bleibt unverändert stehen,
  Fehler geht über `appendLog($con, 'weather', …)` ins Log — nie eine leere
  oder halb geschriebene Cache-Datei.
- `declare(strict_types=1)` in jeder neuen Datei, PHPUnit-Namespace
  `WLMonitor\Tests\Unit`, deutsche Doc-Kommentare — wie im Rest von `inc/`.
- Spec: `docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md` §8.

---

### Task 1: HTML-Fixture speichern und `weather_parse_forecast()` schreiben

**Files:**
- Create: `tests/fixtures/orf_wetter_wien.html`
- Create: `inc/weather.php`
- Test: `tests/Unit/WeatherParseTest.php`

**Interfaces:**
- Produces: `weather_parse_forecast(string $html): array` — wirft
  `RuntimeException`, wenn die Struktur nicht passt; liefert sonst
  `['today' => ['icon_code' => string, 'temp_min' => int, 'temp_max' => int, 'text' => string], 'tomorrow' => [...gleiche Form...]]`.

- [ ] **Step 1: Fixture-Datei anlegen**

Speichere folgenden, auf das Wesentliche gekürzten Auszug einer echten
`wetter.orf.at/wien/prognose`-Antwort (zwei Regionen-Tabellen + Fließtext-Block)
unter `tests/fixtures/orf_wetter_wien.html`:

```html
<!doctype html>
<html lang="de">
<body>
<div class="content" role="main">
<h1>5-Tage-Prognose Wien</h1>

<div class="forecast region">
   <h2>Wien-Hohe Warte</h2>
   <table class="prognoseTable">
      <thead>
         <tr class="headLegend">
            <th scope="col" class="dayCol">Do<br/><small>13.8.</small></th>
            <th scope="col" class="dayCol">Fr<br/><small>14.8.</small></th>
            <th scope="col" class="dayCol">Sa<br/><small>15.8.</small></th>
            <th scope="col" class="dayCol">So<br/><small>16.8.</small></th>
            <th scope="col" class="dayCol">Mo<br/><small>17.8.</small></th>
         </tr>
      </thead>
      <tbody>
         <tr class="forecastIconRow">
            <th scope="row" class="legendCol"><span class="offscreen">Prognose für Wien-Hohe Warte</span></th>
            <td><div class="iconRow temperatureRow size60"><img src="/static/wetter/3_3//images/icons/day/svg/100000.svg" alt="wolkenlos" /></div></td>
            <td><div class="iconRow temperatureRow size60"><img src="/static/wetter/3_3//images/icons/day/svg/100000.svg" alt="wolkenlos" /></div></td>
            <td><div class="iconRow temperatureRow size60"><img src="/static/wetter/3_3//images/icons/day/svg/112000.svg" alt="leicht bewoelkt mit Niederschlag" /></div></td>
            <td><div class="iconRow temperatureRow size60"><img src="/static/wetter/3_3//images/icons/day/svg/100000.svg" alt="wolkenlos" /></div></td>
            <td><div class="iconRow temperatureRow size60"><img src="/static/wetter/3_3//images/icons/day/svg/110000.svg" alt="leicht bewoelkt" /></div></td>
         </tr>
         <tr class="temperatureRow">
            <th scope="row" class="legendCol"><span class="offscreen">Temperatur für </span>Wien-Hohe Warte</th>
            <td><span class="morning">18 <abbr title="Grad Celsius">&deg;C</abbr></span><br><span class="highest">35 <abbr title="Grad Celsius">&deg;C</abbr></span></td>
            <td><span class="morning">22 <abbr title="Grad Celsius">&deg;C</abbr></span><br><span class="highest">37 <abbr title="Grad Celsius">&deg;C</abbr></span></td>
            <td><span class="morning">22 <abbr title="Grad Celsius">&deg;C</abbr></span><br><span class="highest">24 <abbr title="Grad Celsius">&deg;C</abbr></span></td>
            <td><span class="morning">15 <abbr title="Grad Celsius">&deg;C</abbr></span><br><span class="highest">26 <abbr title="Grad Celsius">&deg;C</abbr></span></td>
            <td><span class="morning">17 <abbr title="Grad Celsius">&deg;C</abbr></span><br><span class="highest">32 <abbr title="Grad Celsius">&deg;C</abbr></span></td>
         </tr>
      </tbody>
   </table>
</div>

<div class="forecast region">
   <h2>Wien-Innere Stadt</h2>
   <table class="prognoseTable">
      <thead>
         <tr class="headLegend">
            <th scope="col" class="dayCol">Do<br/><small>13.8.</small></th>
            <th scope="col" class="dayCol">Fr<br/><small>14.8.</small></th>
            <th scope="col" class="dayCol">Sa<br/><small>15.8.</small></th>
            <th scope="col" class="dayCol">So<br/><small>16.8.</small></th>
            <th scope="col" class="dayCol">Mo<br/><small>17.8.</small></th>
         </tr>
      </thead>
      <tbody>
         <tr class="forecastIconRow">
            <th scope="row" class="legendCol"><span class="offscreen">Prognose für Wien-Innere Stadt</span></th>
            <td><div class="iconRow temperatureRow size60"><img src="/static/wetter/3_3//images/icons/day/svg/110000.svg" alt="leicht bewoelkt" /></div></td>
            <td><div class="iconRow temperatureRow size60"><img src="/static/wetter/3_3//images/icons/day/svg/100000.svg" alt="wolkenlos" /></div></td>
            <td><div class="iconRow temperatureRow size60"><img src="/static/wetter/3_3//images/icons/day/svg/100000.svg" alt="wolkenlos" /></div></td>
            <td><div class="iconRow temperatureRow size60"><img src="/static/wetter/3_3//images/icons/day/svg/100000.svg" alt="wolkenlos" /></div></td>
            <td><div class="iconRow temperatureRow size60"><img src="/static/wetter/3_3//images/icons/day/svg/100000.svg" alt="wolkenlos" /></div></td>
         </tr>
         <tr class="temperatureRow">
            <th scope="row" class="legendCol"><span class="offscreen">Temperatur für </span>Wien-Innere Stadt</th>
            <td><span class="morning">20 <abbr title="Grad Celsius">&deg;C</abbr></span><br><span class="highest">35 <abbr title="Grad Celsius">&deg;C</abbr></span></td>
            <td><span class="morning">22 <abbr title="Grad Celsius">&deg;C</abbr></span><br><span class="highest">36 <abbr title="Grad Celsius">&deg;C</abbr></span></td>
            <td><span class="morning">24 <abbr title="Grad Celsius">&deg;C</abbr></span><br><span class="highest">27 <abbr title="Grad Celsius">&deg;C</abbr></span></td>
            <td><span class="morning">17 <abbr title="Grad Celsius">&deg;C</abbr></span><br><span class="highest">27 <abbr title="Grad Celsius">&deg;C</abbr></span></td>
            <td><span class="morning">19 <abbr title="Grad Celsius">&deg;C</abbr></span><br><span class="highest">32 <abbr title="Grad Celsius">&deg;C</abbr></span></td>
         </tr>
      </tbody>
   </table>
</div>

<div class="storyWrapper">
   <div class="storyText" id="ss-storyText">
      <div class="fulltextWrapper" role="article">
         <h2>Heute, Mariä Himmelfahrt</h2>
<p>Von früh bis spät scheint die Sonne, damit klettert die Temperatur auf 34 oder 35 Grad. Dazu weht mäßiger, vorübergehend auch lebhafter Südostwind.</p><h2>Morgen, Sonntag</h2>
<p>Die Hitze steigert sich noch ein wenig, am Nachmittag hat es bis zu 37 Grad. Dazu scheint speziell am Vormittag die Sonne vom blauen Himmel.</p><h2>Übermorgen, Montag</h2>
<p>Das Wetter stellt sich um. Kräftiger Westwind treibt einige Wolken durch, auch ein Gewitter ist möglich.</p><h2>Der weitere Trend</h2>
<p>Am Sonntag einiges an Sonne und ein Höchstwert von rund 36 Grad.</p>
<p>Am Montag unbeständig und im Tagesverlauf gewittrige Regenschauer.</p>
      </div>
   </div>
</div>
</div>
</body>
</html>
```

- [ ] **Step 2: Fehlschlagenden Test schreiben**

```php
<?php
// tests/Unit/WeatherParseTest.php
//
// Parser fuer wetter.orf.at/wien/prognose. Positionale Auswahl (1./2. Spalte,
// 1./2. Textblock), nicht ueber Ueberschriftentext -- siehe Spec §8.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class WeatherParseTest extends TestCase
{
    private function fixtureHtml(): string
    {
        return file_get_contents(__DIR__ . '/../fixtures/orf_wetter_wien.html');
    }

    public function test_extracts_today_and_tomorrow_from_hohe_warte(): void
    {
        $result = weather_parse_forecast($this->fixtureHtml());

        $this->assertSame('100000', $result['today']['icon_code']);
        $this->assertSame(18, $result['today']['temp_min']);
        $this->assertSame(35, $result['today']['temp_max']);
        $this->assertStringContainsString('scheint die Sonne', $result['today']['text']);

        $this->assertSame('100000', $result['tomorrow']['icon_code']);
        $this->assertSame(22, $result['tomorrow']['temp_min']);
        $this->assertSame(37, $result['tomorrow']['temp_max']);
        $this->assertStringContainsString('Hitze steigert sich', $result['tomorrow']['text']);
    }

    public function test_ignores_innere_stadt_and_uses_hohe_warte_only(): void
    {
        // Tag 1 (Do) hat in der Fixture bei Hohe Warte den Code 100000, bei
        // Innere Stadt bewusst den abweichenden Code 110000 -- Beleg, dass
        // wirklich die Hohe-Warte-Tabelle gelesen wird und nicht per Zufall
        // (z.B. "erste Tabelle im Dokument") die falsche.
        $result = weather_parse_forecast($this->fixtureHtml());
        $this->assertSame('100000', $result['today']['icon_code']);
    }

    public function test_throws_when_hohe_warte_table_is_missing(): void
    {
        $broken = str_replace('Wien-Hohe Warte', 'Wien-Nirgendwo', $this->fixtureHtml());
        $this->expectException(RuntimeException::class);
        weather_parse_forecast($broken);
    }
}
```

- [ ] **Step 3: Test ausführen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/WeatherParseTest.php`
Expected: FAIL — `Call to undefined function weather_parse_forecast()`

- [ ] **Step 4: `weather_parse_forecast()` implementieren**

```php
<?php
// inc/weather.php
//
// Wetter-Integration: ORF-Scraping, Icon-Code-Mapping, Anzeige-Auswahl.
// Ausschliesslich reine Funktionen -- kein Netz, keine DB. Der Netzabruf
// lebt in scripts/weather_fetch_cron.php.
declare(strict_types=1);

/**
 * Parst wetter.orf.at/wien/prognose (Desktop-Version) und liefert Icon-Code,
 * Min/Max-Temperatur und Fliesstext fuer heute und morgen, Station
 * Wien-Hohe Warte. Auswahl ist POSITIONAL (1./2. Spalte bzw. Textblock),
 * nicht ueber den Ueberschriftentext -- der wechselt je nach Tageszeit/
 * Feiertag ("Heute Nachmittag" vs. "Heute, Mariä Himmelfahrt").
 *
 * @return array{today: array{icon_code: string, temp_min: int, temp_max: int, text: string}, tomorrow: array{icon_code: string, temp_min: int, temp_max: int, text: string}}
 * @throws RuntimeException wenn die erwartete Struktur nicht gefunden wird
 */
function weather_parse_forecast(string $html): array
{
    $dom = new DOMDocument();
    $prevErrors = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_use_internal_errors($prevErrors);
    $xpath = new DOMXPath($dom);

    $iconRow = $xpath->query(
        '//tr[contains(concat(" ", normalize-space(@class), " "), " forecastIconRow ")]'
        . '[.//th[contains(@class, "legendCol")][contains(., "Wien-Hohe Warte")]]'
    )->item(0);
    $tempRow = $xpath->query(
        '//tr[contains(concat(" ", normalize-space(@class), " "), " temperatureRow ")]'
        . '[.//th[contains(@class, "legendCol")][contains(., "Wien-Hohe Warte")]]'
    )->item(0);

    if ($iconRow === null || $tempRow === null) {
        throw new RuntimeException('Wetter-Tabelle fuer Wien-Hohe Warte nicht gefunden');
    }

    $iconCodes = [];
    foreach ($xpath->query('.//td', $iconRow) as $td) {
        $img = $xpath->query('.//img', $td)->item(0);
        if ($img === null || !preg_match('/(\d{6})\.svg$/', (string) $img->getAttribute('src'), $m)) {
            throw new RuntimeException('Icon-Code nicht gefunden');
        }
        $iconCodes[] = $m[1];
    }

    $temps = [];
    foreach ($xpath->query('.//td', $tempRow) as $td) {
        $morning = $xpath->query('.//span[contains(@class,"morning")]', $td)->item(0);
        $highest = $xpath->query('.//span[contains(@class,"highest")]', $td)->item(0);
        if ($morning === null || $highest === null) {
            throw new RuntimeException('Temperaturwerte nicht gefunden');
        }
        $temps[] = [
            'min' => (int) preg_replace('/\D+/', '', $morning->textContent),
            'max' => (int) preg_replace('/\D+/', '', $highest->textContent),
        ];
    }

    $textBlocks = weather_extract_text_blocks($xpath);

    if (count($iconCodes) < 2 || count($temps) < 2 || count($textBlocks) < 2) {
        throw new RuntimeException('Wetterseite unvollstaendig geparst');
    }

    return [
        'today' => [
            'icon_code' => $iconCodes[0],
            'temp_min' => $temps[0]['min'],
            'temp_max' => $temps[0]['max'],
            'text' => $textBlocks[0],
        ],
        'tomorrow' => [
            'icon_code' => $iconCodes[1],
            'temp_min' => $temps[1]['min'],
            'temp_max' => $temps[1]['max'],
            'text' => $textBlocks[1],
        ],
    ];
}

/**
 * Sammelt alle <h2>/<p>-Paare aus .fulltextWrapper in Dokumentreihenfolge.
 * Index 0 = heute, Index 1 = morgen (positional, siehe weather_parse_forecast()).
 *
 * @return list<string>
 */
function weather_extract_text_blocks(DOMXPath $xpath): array
{
    $wrapper = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " fulltextWrapper ")]')->item(0);
    if ($wrapper === null) {
        throw new RuntimeException('fulltextWrapper nicht gefunden');
    }

    $blocks = [];
    foreach ($wrapper->childNodes as $node) {
        if (!($node instanceof DOMElement) || $node->tagName !== 'h2') {
            continue;
        }
        $next = $node->nextSibling;
        while ($next !== null && !($next instanceof DOMElement)) {
            $next = $next->nextSibling;
        }
        if ($next instanceof DOMElement && $next->tagName === 'p') {
            $blocks[] = trim($next->textContent);
        }
    }
    return $blocks;
}
```

- [ ] **Step 5: Test ausführen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/WeatherParseTest.php`
Expected: OK (3 tests, 3 assertions or more)

- [ ] **Step 6: Commit**

```bash
git add tests/fixtures/orf_wetter_wien.html inc/weather.php tests/Unit/WeatherParseTest.php
git commit -m "feat(weather): ORF-Wetterseite parsen (Icon-Code, Temp, Text)"
```

---

### Task 2: Icon-Code-Mapping

**Files:**
- Modify: `inc/weather.php`
- Test: `tests/Unit/WeatherIconMappingTest.php`

**Interfaces:**
- Consumes: nichts (reine Konstante + Funktion)
- Produces: `WEATHER_ICON_CATEGORIES` (const array, `string` ORF-Code → `string` Kategorie),
  `weather_map_icon_code(string $code): array{category: string, known: bool}`

- [ ] **Step 1: Fehlschlagenden Test schreiben**

```php
<?php
// tests/Unit/WeatherIconMappingTest.php

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class WeatherIconMappingTest extends TestCase
{
    public function test_known_code_maps_to_category(): void
    {
        $result = weather_map_icon_code('100000');
        $this->assertSame('klar', $result['category']);
        $this->assertTrue($result['known']);
    }

    public function test_unknown_code_falls_back_to_unbekannt(): void
    {
        $result = weather_map_icon_code('999999');
        $this->assertSame('unbekannt', $result['category']);
        $this->assertFalse($result['known']);
    }
}
```

- [ ] **Step 2: Test ausführen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/WeatherIconMappingTest.php`
Expected: FAIL — `Call to undefined function weather_map_icon_code()`

- [ ] **Step 3: Mapping implementieren**

Füge in `inc/weather.php` an (nach `weather_extract_text_blocks()`):

```php
/**
 * ORF liefert einen 6-stelligen numerischen Icon-Code. Diese Tabelle bildet
 * ihn auf eine von neun Anzeige-Kategorien ab (siehe Spec §8) und waechst
 * anhand geloggter, bislang unbekannter Codes -- kein vollstaendiges
 * Reverse-Engineering des ORF-Codesystems.
 *
 * Bekannte Kategorien: klar, leicht_bewoelkt, bewoelkt, bedeckt,
 * regen_leicht, regen_stark, schnee, gewitter, nebel, unbekannt (Fallback).
 */
const WEATHER_ICON_CATEGORIES = [
    '100000' => 'klar',
    '110000' => 'leicht_bewoelkt',
    '112000' => 'regen_leicht',
];

/**
 * @return array{category: string, known: bool}
 */
function weather_map_icon_code(string $code): array
{
    if (isset(WEATHER_ICON_CATEGORIES[$code])) {
        return ['category' => WEATHER_ICON_CATEGORIES[$code], 'known' => true];
    }
    return ['category' => 'unbekannt', 'known' => false];
}
```

- [ ] **Step 4: Test ausführen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/WeatherIconMappingTest.php`
Expected: OK (2 tests, 4 assertions)

- [ ] **Step 5: Commit**

```bash
git add inc/weather.php tests/Unit/WeatherIconMappingTest.php
git commit -m "feat(weather): Icon-Code-zu-Kategorie-Mapping mit Fallback"
```

---

### Task 3: Anzeige-Auswahl (Cutover 19:00, Staleness >6h)

**Files:**
- Modify: `inc/weather.php`
- Test: `tests/Unit/WeatherSelectDisplayTest.php`

**Interfaces:**
- Consumes: `weather_map_icon_code()` (Task 2)
- Produces: `weather_select_display(?array $cache, DateTimeImmutable $now): array`
  liefert entweder `['available' => false]` oder
  `['available' => true, 'icon_category' => string, 'temp_min' => int, 'temp_max' => int, 'text' => ?string, 'text_error' => ?string]`

- [ ] **Step 1: Fehlschlagenden Test schreiben**

```php
<?php
// tests/Unit/WeatherSelectDisplayTest.php

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

class WeatherSelectDisplayTest extends TestCase
{
    private function cache(string $fetchedAt): array
    {
        return [
            'fetched_at' => $fetchedAt,
            'today' => ['icon_code' => '100000', 'temp_min' => 18, 'temp_max' => 35, 'text' => 'Heute-Text'],
            'tomorrow' => ['icon_code' => '110000', 'temp_min' => 22, 'temp_max' => 37, 'text' => 'Morgen-Text'],
        ];
    }

    public function test_no_cache_is_unavailable(): void
    {
        $result = weather_select_display(null, new DateTimeImmutable('2026-08-15T12:00:00+02:00'));
        $this->assertFalse($result['available']);
    }

    public function test_before_1900_uses_today(): void
    {
        $result = weather_select_display(
            $this->cache('2026-08-15T09:00:00+02:00'),
            new DateTimeImmutable('2026-08-15T18:59:00+02:00')
        );
        $this->assertSame('klar', $result['icon_category']);
        $this->assertSame(18, $result['temp_min']);
        $this->assertSame('Heute-Text', $result['text']);
        $this->assertNull($result['text_error']);
    }

    public function test_at_1900_uses_tomorrow(): void
    {
        $result = weather_select_display(
            $this->cache('2026-08-15T18:00:00+02:00'),
            new DateTimeImmutable('2026-08-15T19:00:00+02:00')
        );
        $this->assertSame('leicht_bewoelkt', $result['icon_category']);
        $this->assertSame(22, $result['temp_min']);
        $this->assertSame('Morgen-Text', $result['text']);
    }

    public function test_cache_older_than_6h_replaces_text_with_error(): void
    {
        $result = weather_select_display(
            $this->cache('2026-08-15T06:00:00+02:00'),
            new DateTimeImmutable('2026-08-15T12:00:01+02:00') // 6h 0min 1s alt
        );
        $this->assertNull($result['text']);
        $this->assertStringContainsString('06:00', $result['text_error']);
        // Icon/Temperatur bleiben unveraendert stehen (Spec §8):
        $this->assertSame(18, $result['temp_min']);
        $this->assertSame('klar', $result['icon_category']);
    }

    public function test_cache_exactly_6h_old_is_not_yet_stale(): void
    {
        $result = weather_select_display(
            $this->cache('2026-08-15T06:00:00+02:00'),
            new DateTimeImmutable('2026-08-15T12:00:00+02:00') // exakt 6h
        );
        $this->assertSame('Heute-Text', $result['text']);
        $this->assertNull($result['text_error']);
    }
}
```

- [ ] **Step 2: Test ausführen, Fehlschlag bestätigen**

Run: `vendor/bin/phpunit tests/Unit/WeatherSelectDisplayTest.php`
Expected: FAIL — `Call to undefined function weather_select_display()`

- [ ] **Step 3: Implementieren**

Füge in `inc/weather.php` an:

```php
/**
 * Waehlt aus dem Wetter-Cache die fuer JETZT richtige Anzeige-Scheibe:
 * vor 19:00 Europe/Vienna "today", ab 19:00 "tomorrow" (Spec §8). Ist der
 * Cache aelter als 6h, wird NUR der Fliesstext durch eine Fehlermeldung
 * ersetzt -- Icon und Temperatur bleiben unveraendert stehen.
 *
 * @param ?array{fetched_at: string, today: array, tomorrow: array} $cache
 * @return array{available: bool, icon_category?: string, temp_min?: int, temp_max?: int, text?: ?string, text_error?: ?string}
 */
function weather_select_display(?array $cache, DateTimeImmutable $now): array
{
    if ($cache === null) {
        return ['available' => false];
    }

    $vienna = new DateTimeZone('Europe/Vienna');
    $localNow = $now->setTimezone($vienna);
    $period = ((int) $localNow->format('H') < 19) ? 'today' : 'tomorrow';
    $slice = $cache[$period];

    $mapping = weather_map_icon_code($slice['icon_code']);

    $fetchedAt = new DateTimeImmutable($cache['fetched_at']);
    $ageSeconds = $now->getTimestamp() - $fetchedAt->getTimestamp();
    $stale = $ageSeconds > 6 * 3600;

    return [
        'available' => true,
        'icon_category' => $mapping['category'],
        'temp_min' => $slice['temp_min'],
        'temp_max' => $slice['temp_max'],
        'text' => $stale ? null : $slice['text'],
        'text_error' => $stale
            ? 'Wetterbericht veraltet seit ' . $fetchedAt->setTimezone($vienna)->format('H:i')
            : null,
    ];
}
```

- [ ] **Step 4: Test ausführen, Erfolg bestätigen**

Run: `vendor/bin/phpunit tests/Unit/WeatherSelectDisplayTest.php`
Expected: OK (5 tests)

- [ ] **Step 5: Commit**

```bash
git add inc/weather.php tests/Unit/WeatherSelectDisplayTest.php
git commit -m "feat(weather): Cutover- und Staleness-Auswahl (19:00 / 6h)"
```

---

### Task 4: Cron-Einstiegspunkt

**Files:**
- Create: `scripts/weather_fetch_cron.php`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: `weather_parse_forecast()` (Task 1), `weather_map_icon_code()` (Task 2), `appendLog()` (bestehend, `inc/initialize.php`/`erikr/auth`)
- Produces: `data/weather_cache.json` (Laufzeitdatei, siehe Task 3 für die erwartete Form)

Kein PHPUnit-Test für dieses Skript — es ist duenner Glue-Code (Netzabruf +
Dateisystem), analog zu `web/board.php` in v1. Die Logik dahinter ist bereits
in Task 1–3 vollstaendig getestet. Verifikation erfolgt manuell in Step 3.

- [ ] **Step 1: `.gitignore` ergänzen**

Füge nach `data/status_cache.json` eine neue Zeile ein:

```
data/weather_cache.json
```

- [ ] **Step 2: Skript schreiben**

```php
<?php
// scripts/weather_fetch_cron.php
//
// Cron: alle 3h ab 06:00 (06/09/12/15/18/21 Uhr). Schreibt data/weather_cache.json.
// board.php ruft NIEMALS direkt ORF ab -- nur dieses Skript tut das.
// Bei Fehlern bleibt die vorhandene Cache-Datei unveraendert stehen.
declare(strict_types=1);

require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/weather.php';

const WEATHER_SOURCE_URL = 'https://wetter.orf.at/wien/prognose';
const WEATHER_CACHE_FILE = __DIR__ . '/../data/weather_cache.json';

function weather_fetch_html(string $url): string
{
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "User-Agent: wlmonitor-weather-cron/1.0 (+https://wlmonitor.eriks.cloud)\r\n",
        'timeout' => 10,
    ]]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html === false) {
        throw new RuntimeException('ORF-Wetterseite nicht erreichbar');
    }
    return $html;
}

try {
    $html = weather_fetch_html(WEATHER_SOURCE_URL);
    $forecast = weather_parse_forecast($html);

    foreach (['today', 'tomorrow'] as $period) {
        $mapping = weather_map_icon_code($forecast[$period]['icon_code']);
        if (!$mapping['known']) {
            appendLog($con, 'weather', 'Unbekannter ORF-Icon-Code: ' . $forecast[$period]['icon_code']);
        }
    }

    $cache = [
        'fetched_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        'today' => $forecast['today'],
        'tomorrow' => $forecast['tomorrow'],
    ];

    $tmpFile = WEATHER_CACHE_FILE . '.tmp';
    file_put_contents(
        $tmpFile,
        json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
    );
    rename($tmpFile, WEATHER_CACHE_FILE);

    fwrite(STDOUT, "Wetter-Cache aktualisiert: {$cache['fetched_at']}\n");
} catch (Throwable $e) {
    appendLog($con, 'weather', 'Wetter-Abruf fehlgeschlagen: ' . get_class($e) . ': ' . $e->getMessage());
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
```

- [ ] **Step 3: Manuell verifizieren**

Run: `php scripts/weather_fetch_cron.php`
Expected: Ausgabe `Wetter-Cache aktualisiert: <ISO-8601-Zeitstempel>` auf STDOUT,
Exit-Code 0, und `data/weather_cache.json` existiert mit den Feldern
`fetched_at`, `today`, `tomorrow` (jeweils `icon_code`, `temp_min`, `temp_max`, `text`).

Prüfen:
```bash
cat data/weather_cache.json
```

Fehlerpfad manuell prüfen (URL absichtlich kaputt machen, testweise in einer
Kopie):
```bash
php -r '
require "inc/initialize.php";
require "inc/weather.php";
try { weather_fetch_html("https://wetter.orf.at/nonexistent-path-404"); }
catch (RuntimeException $e) { echo "erwarteter Fehler: " . $e->getMessage() . "\n"; }
'
```
Expected: `erwarteter Fehler: ORF-Wetterseite nicht erreichbar` (oder eine
Ausnahme aus `weather_parse_forecast()`, je nachdem ob die 404-Seite noch
gültiges HTML mit fehlender Tabelle liefert) — in beiden Fällen bleibt
`data/weather_cache.json` von diesem Testaufruf unberührt, weil der Fehler
vor dem Schreiben auftritt.

- [ ] **Step 4: Cronjob auf akadbrain einrichten (manuell, nicht Teil des Deploys)**

```
0 6,9,12,15,18,21 * * * /opt/homebrew/bin/php /pfad/zu/wlmonitor/scripts/weather_fetch_cron.php >> /pfad/zu/wlmonitor/data/weather_cron.log 2>&1
```

Exakter PHP-Pfad und wlmonitor-Pfad auf akadbrain vor dem Eintragen mit
`which php` bzw. dem tatsächlichen Deploy-Ziel prüfen — **diese Eintragung ist
manuell und nicht Teil dieses Plans**, da sie erst nach dem Deploy des Codes
sinnvoll ist.

- [ ] **Step 5: Commit**

```bash
git add scripts/weather_fetch_cron.php .gitignore
git commit -m "feat(weather): Cron-Einstiegspunkt fuer den 3h-Wetterabruf"
```

---

## Self-Review (durchgeführt)

**Spec-Abdeckung (§8):** Scraping-Selektoren ✓ (Task 1), positionale Auswahl
✓ (Task 1), Icon-Mapping mit Fallback+Log ✓ (Task 2), Cutover 19:00 ✓
(Task 3), Cache-Alter >6h → Fehlermeldung statt Text ✓ (Task 3), Cron alle 3h
ab 06:00 ✓ (Task 4, Cron-Zeile), Fehlerfälle (ORF nicht erreichbar / Struktur
geändert → alter Cache bleibt stehen) ✓ (Task 4, try/catch schreibt nur bei
Erfolg).

**Nicht in diesem Plan (folgt in der Rendering-Pipeline-Plan):** die
Icon-**Kategorien** hier sind Strings (`klar`, `leicht_bewoelkt`, …) — die
tatsächliche SVG-Zeichnung pro Kategorie ist Teil des nächsten Plans
(Rendering-Pipeline + Board-Protokoll), der auf `weather_select_display()`
aufsetzt.

**Platzhalter-Scan:** keine TBD/TODO, jeder Schritt enthält vollständigen Code
oder ein konkretes, ausführbares Kommando mit erwarteter Ausgabe.

**Typkonsistenz:** `weather_map_icon_code()` (Task 2) liefert
`{category, known}` — exakt diese Form wird in Task 3 (`$mapping['category']`)
und Task 4 (`$mapping['known']`) verwendet. `weather_parse_forecast()` liefert
`icon_code`/`temp_min`/`temp_max`/`text` je Periode — exakt diese Schlüssel
liest Task 3 aus `$cache[$period]` und Task 4 beim Cache-Aufbau.
