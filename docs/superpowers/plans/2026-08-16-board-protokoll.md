# Board-Protokoll Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Status (2026-08-17): Erledigt und auf `main` gemergt.** Alle 5 Tasks via
> SDD implementiert, task-review-approved, whole-branch-review-approved
> (`Ready to merge: Yes with minor follow-ups`, keine Critical/Important
> Findings). Vier kleine Whole-Branch-Nachbesserungen direkt umgesetzt:
> Zustands-Speicherfehler melden jetzt 500 statt fälschlich 503;
> Regressionstests für ETag-Mismatch und den Übergang
> "keine Favoriten → erster Favorit" ergänzt; Integrationstests räumen ihre
> `data/board_state/`-Dateien jetzt in `tearDown()` auf. Merge-Commit:
> `cafdbdc` (fast-forward, 8 Feature-Commits `21a15ac..9675ab9` + 1
> Nachbesserungs-Commit). Suite bei Merge: 287/287 grün.
>
> **Nächste Phase (ESP32-Firmware/reTerminal E1003) — Entscheidung 2026-08-17: ERSETZEN.**
> `epaper-monitor/` enthält eine vollständige, committete Firmware (Commits
> bis `8ab1ad5`) für die ALTE Hardware (Waveshare 7.5″ e-Paper HAT (B),
> 800×480, GxEPD2) gegen den ALTEN v1-JSON-Endpunkt und die ALTE Spec
> `2026-08-01-epaper-abfahrtsmonitor-design.md`. Mit dem neuen binären
> Board-Protokoll (dieser Plan) und dem reTerminal-E1003-Pivot
> (`2026-08-15-epaper-monitor-v2-design.md`) inkompatibel — weder Hardware
> noch Protokoll passen mehr. Erik hat entschieden: ersetzen, nicht
> archivieren. Referenzmaterial für die neue Firmware (Pins, Seeed_GFX-API,
> Touch/RTC/Deep-Sleep) liegt im Claude-Memory-System unter
> `reference_reterminal_e1003_arduino` (aus dem Seeed-Wiki exzerpiert,
> zwei unverifizierte Diskrepanzen markiert — vor Nutzung gegenprüfen).
> Der eigentliche Austausch (Löschen der alten Firmware + neuer Spec/Plan-
> Zyklus für die neue) ist noch NICHT begonnen — nächster Schritt.

**Goal:** Rewrite `web/board.php` from its current v1 JSON contract into the binary
image-protocol endpoint from spec §5 — device-state persistence, touch-driven
favorite/page resolution, full/patch frame diffing, and disruption-page
filtering — wired to the already-shipped `inc/board_template.php` rendering
layer.

**Architecture:** Five small, independently testable units feed one
integration point. `inc/board_state.php` owns per-device persistence (favorite/
page/ETag/last-full-refresh + the last packed frame) and the pure touch-event
resolver. `inc/board_render.php` gains a pure frame-diff function and a
crop+pack function, both operating on the already-verified 1bpp pipeline.
`inc/board.php` gains two small device-signal normalizers (battery mV →
percent, RSSI → bar count) and the alerts-for-favorite filter. The test
harness (`tests/fixtures/token_probe.php`) gains header-capture, since the
rewritten endpoint's STDOUT is opaque binary. `web/board.php` itself is the
orchestrator: resolve touch → fetch data → render → pack → diff against
stored state → respond → persist new state.

**Tech Stack:** PHP 8.2, ext-gd, existing `svg_to_png()`/`png_to_1bpp_packed()`
(inc/board_render.php), existing `board_render_svg()` (inc/board_template.php),
PHPUnit, the `token_probe.php` out-of-process harness (board.php calls `exit()`
and cannot run in-process).

## Global Constraints

Copied verbatim from `docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md`
(§4/§5/§6/§8/§11) — binding for every task below.

- **No `$_SESSION`.** All device state lives in `data/board_state/`, keyed by
  `SHA-256(token)`. Analog to `RATE_LIMIT_FILE`/`data/ratelimit.json`.
- **Request headers:** `Authorization: Bearer <token>` (unchanged),
  `X-Device-Battery-mV: <n>`, `X-Device-RSSI: <n>`,
  `X-Device-Touch: <fav0|fav1|fav2|page_prev|page_next>` (absent unless a
  touch/button triggered the poll), `If-None-Match: <last known ETag>` (empty
  on the very first poll). **No `fav` query param** — the server resolves the
  device-owner's favorites from the token.
- **Touch/state resolution (spec §4 step 3):** load the first 3 favorites (by
  `sort`) as touch-bar candidates. From stored state + `X-Device-Touch`,
  determine the active favorite index (0/1/2, default 0) and active page
  (default 1). `page_prev`/`page_next` move the page; `fav0`/`fav1`/`fav2`
  switch the favorite AND reset the page to 1.
- **Success response (HTTP 200):** metadata as headers, body = raw packed
  pixel data:
  ```
  X-Board-Mode: full | patch
  X-Board-ETag: "<sha256 of the frame data>"
  X-Board-Generated: 2026-08-16T19:13:47+02:00
  X-Board-X: 0            (only meaningful for patch)
  X-Board-Y: 0
  X-Board-W: 1872
  X-Board-H: 1404
  Content-Type: application/octet-stream
  Content-Length: <n>
  ```
- **Patch vs. full decision (spec §4 step 6):** Patch only if `If-None-Match`
  matches the stored ETag **and** the last full refresh was < 30 minutes ago
  **and** neither favorite nor page changed. Otherwise full. A favorite/page
  change needs no special case — the generic diff naturally covers the whole
  area via its bounding box.
- **Error responses (401/503/500) are unchanged from v1:** small JSON body
  (`{"error":"unauthorized"}` etc.), **no image body**, so firmware can
  recognize them without a body parser. Same `appendLog($con, 'board', …)`
  pattern as v1: log only when a token was *presented* but invalid, never
  when the header is simply absent.
- **No classic HTTP caching.** `If-None-Match`/ETag are repurposed — there is
  no `304`, since departure countdowns change every poll. The ETag only lets
  the server recognize whether ITS OWN prior state for this device still
  applies before deciding patch vs. full.
- **No separate error-banner header.** Stale-data/stale-weather signaling is
  baked directly into the rendered pixels (already implemented in
  `board_render_chrome_svg`/weather rendering from the prior plan) — this
  plan does not add a header for it.
- **Pixel format:** stays on the **1bpp path** (`png_to_1bpp_packed()`,
  already implemented and tested) for the actual body bytes. The native
  4bpp/16-level Seeed_GFX buffer layout is explicitly **out of scope** (spec
  §13: "wird beim Firmware-/Protokoll-Task anhand der Seeed_GFX-Quelle
  geklärt, nicht geraten") — there is no firmware yet to verify the byte
  layout against. `board_frame_diff()`/`board_crop_and_pack()` are written
  generically enough (operate on packed-byte buffers + width/height) that
  swapping in a 4bpp packer later only touches the two call sites in
  `web/board.php`, not their logic.
- **Disruptions (spec §8):** `monitor_get()['alerts']`, filtered to entries
  whose `lines` intersect the active favorite's lines (plain string equality,
  no normalization — confirmed against `inc/monitor.php`'s own
  `relatedLines` cross-reference). Shown as extra page(s) appended after the
  favorite's departure pages; absent entirely if there are none (no empty
  "no disruptions" page).
- **`?debug=svg` / `?debug=png`` (spec §6):** same token auth, but **bypass
  the diff/patch/ETag/state logic entirely** — pure rendering test, no device
  state is read for persistence purposes beyond resolving which
  favorite/page to preview, and nothing is written back.
- **Design decision (resolves an open question from grounding research):**
  `$dataStand` (the "Stand HH:MM" timestamp `board_render_svg()` needs) is
  simply `new DateTimeImmutable()` captured once per request and reused for
  both `$dataStand` and `$renderedAt`. `monitor_get()`'s own `update_at`
  field is an `H:i:s`-only string with no date component and no caching
  layer between the WL call and the response — reattaching today's date and
  handling a possible midnight rollover would add real complexity for zero
  behavioral difference, since `monitor_get()` is always called synchronously
  within the same request.

## File Structure

- Create: `inc/board_state.php` — per-device state persistence (meta JSON +
  raw frame file) and the pure touch-event resolver.
- Modify: `inc/board_render.php` — add `BOARD_WIDTH`/`BOARD_HEIGHT` constants,
  `board_frame_diff()`, `board_crop_and_pack()`.
- Modify: `inc/board.php` — add `board_filter_alerts_for_favorite()`,
  `board_battery_percent_from_mv()`, `board_wifi_bars_from_rssi()`.
- Modify: `tests/fixtures/token_probe.php` — capture `headers_list()` to
  STDERR (STDOUT is now opaque binary for the real endpoint); accept a
  `headers` scenario key to inject arbitrary request headers.
- Modify: `web/board.php` — full rewrite to the binary protocol.
- Modify: `tests/Integration/BoardTokenEndpointTest.php` — the two tests that
  assert on a JSON response body no longer apply verbatim (binary body now);
  update them, add new tests for the binary protocol.
- Modify: `tests/bootstrap.php` — `require_once` the new `inc/board_state.php`.
- Modify: `data/.gitignore` — add `data/board_state/`.

---

### Task 1: `inc/board_state.php` — device state persistence + touch resolution

**Files:**
- Create: `inc/board_state.php`
- Modify: `tests/bootstrap.php:29` — add `require_once __DIR__ . '/../inc/board_state.php';` right after the existing `board_template.php` require.
- Modify: `data/.gitignore` — add a `board_state/` line.
- Test: `tests/Unit/BoardStateTest.php` (new)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces (consumed by Task 5):
  - `board_state_hash(string $token): string`
  - `board_state_meta_path(string $hash): string`
  - `board_state_frame_path(string $hash): string`
  - `board_state_load_meta(string $path): array{activeFavoriteIndex:int, activePage:int, etag:?string, fullRefreshAt:?int}`
  - `board_state_save_meta(string $path, array $meta): void`
  - `board_state_load_frame(string $path): ?string`
  - `board_state_save_frame(string $path, string $packed): void`
  - `board_resolve_touch(array $meta, ?string $touch, int $favoriteCount): array{activeFavoriteIndex:int, activePage:int}`

- [x] **Step 1: Write the failing tests**

Create `tests/Unit/BoardStateTest.php`:

```php
<?php
// tests/Unit/BoardStateTest.php
//
// Per-device state persistence (Spec §4 Schritt 3/6) -- zwei Dateien pro
// Geraet (Meta-JSON + roher letzter Frame), Schluessel = SHA-256(Token).
// Pattern A (flock read-modify-write), analog zu RATE_LIMIT_FILE in
// vendor/erikr/auth/src/auth.php.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardStateTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $p) {
            @unlink($p);
        }
        $this->cleanupPaths = [];
    }

    public function test_hash_is_deterministic_sha256(): void
    {
        $this->assertSame(hash('sha256', 'abc'), board_state_hash('abc'));
    }

    public function test_meta_path_and_frame_path_share_the_same_hash_stem(): void
    {
        $hash = board_state_hash('sometoken');
        $this->assertSame(board_state_dir() . "/$hash.json", board_state_meta_path($hash));
        $this->assertSame(board_state_dir() . "/$hash.frame", board_state_frame_path($hash));
    }

    public function test_load_meta_returns_defaults_when_file_is_missing(): void
    {
        $path = board_state_dir() . '/does-not-exist-' . uniqid() . '.json';
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 1, 'etag' => null, 'fullRefreshAt' => null],
            board_state_load_meta($path)
        );
    }

    public function test_save_then_load_meta_roundtrips(): void
    {
        $path = board_state_dir() . '/test-' . uniqid() . '.json';
        $this->cleanupPaths[] = $path;

        $meta = ['activeFavoriteIndex' => 2, 'activePage' => 3, 'etag' => '"abc"', 'fullRefreshAt' => 1700000000];
        board_state_save_meta($path, $meta);

        $this->assertSame($meta, board_state_load_meta($path));
    }

    public function test_load_frame_returns_null_when_file_is_missing(): void
    {
        $path = board_state_dir() . '/does-not-exist-' . uniqid() . '.frame';
        $this->assertNull(board_state_load_frame($path));
    }

    public function test_save_then_load_frame_roundtrips_binary_data(): void
    {
        $path = board_state_dir() . '/test-' . uniqid() . '.frame';
        $this->cleanupPaths[] = $path;

        $packed = "\x00\xFF\x0F" . str_repeat("\xAA", 100);
        board_state_save_frame($path, $packed);

        $this->assertSame($packed, board_state_load_frame($path));
    }

    // --- Touch-Aufloesung (Spec §4 Schritt 3) ---------------------------------

    public function test_no_touch_keeps_existing_favorite_and_page(): void
    {
        $meta = ['activeFavoriteIndex' => 1, 'activePage' => 2];
        $this->assertSame(
            ['activeFavoriteIndex' => 1, 'activePage' => 2],
            board_resolve_touch($meta, null, 3)
        );
    }

    public function test_fav_touch_switches_favorite_and_resets_page_to_one(): void
    {
        $meta = ['activeFavoriteIndex' => 0, 'activePage' => 3];
        $this->assertSame(
            ['activeFavoriteIndex' => 2, 'activePage' => 1],
            board_resolve_touch($meta, 'fav2', 3)
        );
    }

    public function test_fav_touch_beyond_available_favorite_count_is_ignored(): void
    {
        $meta = ['activeFavoriteIndex' => 0, 'activePage' => 1];
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 1],
            board_resolve_touch($meta, 'fav2', 2) // nur 2 Favoriten (Index 0,1) -- fav2 gibt es nicht
        );
    }

    public function test_page_prev_decrements_but_not_below_one(): void
    {
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 1],
            board_resolve_touch(['activeFavoriteIndex' => 0, 'activePage' => 1], 'page_prev', 3)
        );
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 1],
            board_resolve_touch(['activeFavoriteIndex' => 0, 'activePage' => 2], 'page_prev', 3)
        );
    }

    public function test_page_next_increments_unclamped(): void
    {
        // Kein oberes Clamping hier -- der Aufrufer (web/board.php) klemmt
        // gegen die tatsaechliche, datenabhaengige Seitenzahl.
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 4],
            board_resolve_touch(['activeFavoriteIndex' => 0, 'activePage' => 3], 'page_next', 3)
        );
    }

    public function test_zero_favorites_forces_index_zero_regardless_of_stored_state(): void
    {
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 1],
            board_resolve_touch(['activeFavoriteIndex' => 2, 'activePage' => 1], null, 0)
        );
    }

    public function test_unknown_touch_value_is_a_no_op(): void
    {
        $meta = ['activeFavoriteIndex' => 1, 'activePage' => 2];
        $this->assertSame(
            ['activeFavoriteIndex' => 1, 'activePage' => 2],
            board_resolve_touch($meta, 'garbage', 3)
        );
    }
}
```

- [x] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/BoardStateTest.php`
Expected: FAIL — `board_state_hash()` (and everything else) undefined.

- [x] **Step 3: Implement `inc/board_state.php`**

```php
<?php
// inc/board_state.php
//
// Pro-Geraet-Zustand fuer das Board-Protokoll (Spec §4): welcher Favorit ist
// aktiv, welche Seite, welcher Frame + ETag wurde zuletzt geschickt, wann
// war der letzte Vollbild-Refresh. Zwei Dateien pro Geraet unter
// data/board_state/, Schluessel = SHA-256(Token) -- kein $_SESSION.
//
// Pattern A (flock read-modify-write), analog zu RATE_LIMIT_FILE
// (vendor/erikr/auth/src/auth.php). Meta und Frame sind bewusst getrennte
// Dateien statt eines JSON-Feldes: der Frame ist ~330 KB roher Bytes, Base64
// im JSON waere 33% groesser und wuerde jede Meta-Lese/Schreib-Operation
// unnoetig verlangsamen.
declare(strict_types=1);

function board_state_dir(): string
{
    return __DIR__ . '/../data/board_state';
}

function board_state_hash(string $token): string
{
    return hash('sha256', $token);
}

function board_state_meta_path(string $hash): string
{
    return board_state_dir() . '/' . $hash . '.json';
}

function board_state_frame_path(string $hash): string
{
    return board_state_dir() . '/' . $hash . '.frame';
}

/** @return array{activeFavoriteIndex:int, activePage:int, etag:?string, fullRefreshAt:?int} */
function board_state_default_meta(): array
{
    return ['activeFavoriteIndex' => 0, 'activePage' => 1, 'etag' => null, 'fullRefreshAt' => null];
}

/** @return array{activeFavoriteIndex:int, activePage:int, etag:?string, fullRefreshAt:?int} */
function board_state_load_meta(string $path): array
{
    if (!file_exists($path)) {
        return board_state_default_meta();
    }
    $fp = fopen($path, 'r');
    if ($fp === false) {
        return board_state_default_meta();
    }
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $data = json_decode((string) $raw, true);
    return is_array($data) ? array_merge(board_state_default_meta(), $data) : board_state_default_meta();
}

function board_state_save_meta(string $path, array $meta): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException('board_state: Meta-Datei nicht beschreibbar: ' . $path);
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($meta, JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function board_state_load_frame(string $path): ?string
{
    if (!file_exists($path)) {
        return null;
    }
    $fp = fopen($path, 'r');
    if ($fp === false) {
        return null;
    }
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return ($raw === false || $raw === '') ? null : $raw;
}

function board_state_save_frame(string $path, string $packed): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException('board_state: Frame-Datei nicht beschreibbar: ' . $path);
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $packed);
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Loest ein X-Device-Touch-Ereignis gegen den gespeicherten Zustand auf
 * (Spec §4 Schritt 3). Reine Funktion -- kennt die tatsaechliche Seitenzahl
 * NICHT (die haengt von den gerade geladenen Abfahrtsdaten ab, die dieser
 * Funktion nicht vorliegen). page_next erhoeht daher unbegrenzt; der
 * Aufrufer (web/board.php) klemmt das Ergebnis gegen die reale Seitenzahl,
 * bevor er board_render_svg() aufruft und den Zustand speichert.
 *
 * @param array{activeFavoriteIndex?:int, activePage?:int} $meta
 * @return array{activeFavoriteIndex:int, activePage:int}
 */
function board_resolve_touch(array $meta, ?string $touch, int $favoriteCount): array
{
    $index = $favoriteCount > 0
        ? max(0, min($favoriteCount - 1, (int) ($meta['activeFavoriteIndex'] ?? 0)))
        : 0;
    $page = max(1, (int) ($meta['activePage'] ?? 1));

    if ($touch !== null && str_starts_with($touch, 'fav') && ctype_digit(substr($touch, 3))) {
        $requested = (int) substr($touch, 3);
        if ($requested < $favoriteCount) {
            $index = $requested;
            $page = 1;
        }
    } elseif ($touch === 'page_prev') {
        $page = max(1, $page - 1);
    } elseif ($touch === 'page_next') {
        $page++;
    }

    return ['activeFavoriteIndex' => $index, 'activePage' => $page];
}
```

- [x] **Step 4: Wire the new file into the test bootstrap**

In `tests/bootstrap.php`, after the existing line
`require_once __DIR__ . '/../inc/board_template.php';` add:

```php
require_once __DIR__ . '/../inc/board_state.php';
```

- [x] **Step 5: Add the state directory to .gitignore**

In `data/.gitignore`, add a new line: `board_state/`

- [x] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/BoardStateTest.php`
Expected: PASS, all tests green.

- [x] **Step 7: Commit**

```bash
git add inc/board_state.php tests/Unit/BoardStateTest.php tests/bootstrap.php data/.gitignore
git commit -m "feat(board): add per-device state persistence + touch resolution"
```

---

### Task 2: `inc/board_render.php` — frame diff + crop-and-pack

**Files:**
- Modify: `inc/board_render.php`
- Test: `tests/Unit/BoardRenderTest.php` (append — reuses its existing `makeTestPng()` helper)

**Interfaces:**
- Consumes: `png_to_1bpp_packed()` (already in this file).
- Produces (consumed by Task 5):
  - `const BOARD_WIDTH = 1872;` / `const BOARD_HEIGHT = 1404;`
  - `board_frame_diff(string $oldPacked, string $newPacked, int $width, int $height): ?array{x:int,y:int,w:int,h:int}`
  - `board_crop_and_pack(string $pngBinary, int $x, int $y, int $w, int $h): string`

- [x] **Step 1: Write the failing tests**

Append to `tests/Unit/BoardRenderTest.php`, inside the `BoardRenderTest` class,
right before the final closing `}`:

```php
    // --- board_frame_diff() / board_crop_and_pack() (Board-Protokoll-Plan) ---

    public function test_frame_diff_returns_null_for_identical_frames(): void
    {
        $packed = str_repeat("\xFF", 16);
        $this->assertNull(board_frame_diff($packed, $packed, 16, 8));
    }

    public function test_frame_diff_returns_full_bounds_when_lengths_differ(): void
    {
        $old = str_repeat("\xFF", 8);
        $new = str_repeat("\xFF", 16);
        $diff = board_frame_diff($old, $new, 16, 8);
        $this->assertSame(['x' => 0, 'y' => 0, 'w' => 16, 'h' => 8], $diff);
    }

    public function test_frame_diff_returns_tight_bounding_box_around_single_changed_byte(): void
    {
        // 16px breit (2 Byte/Zeile), 4 Zeilen. Byte-Index 1 (Spalten 8-15) in
        // Zeile 2 (0-indiziert) unterscheidet sich -- alle anderen Bytes gleich.
        $rowBytes = 2;
        $old = str_repeat("\xFF", $rowBytes * 4);
        $new = $old;
        $new[2 * $rowBytes + 1] = "\x00";

        $diff = board_frame_diff($old, $new, 16, 4);

        $this->assertSame(['x' => 8, 'y' => 2, 'w' => 8, 'h' => 1], $diff);
    }

    public function test_frame_diff_spans_multiple_rows_and_columns(): void
    {
        $rowBytes = 2;
        $old = str_repeat("\xFF", $rowBytes * 4);
        $new = $old;
        $new[0 * $rowBytes + 0] = "\x00"; // Zeile 0, Byte 0 (Spalten 0-7)
        $new[3 * $rowBytes + 1] = "\x00"; // Zeile 3, Byte 1 (Spalten 8-15)

        $diff = board_frame_diff($old, $new, 16, 4);

        $this->assertSame(['x' => 0, 'y' => 0, 'w' => 16, 'h' => 4], $diff);
    }

    public function test_crop_and_pack_matches_packing_the_region_directly(): void
    {
        // 16x8 PNG, linke Haelfte schwarz, rechte weiss.
        $png = $this->makeTestPng(16, 8, fn($x, $y) => $x < 8 ? [0, 0, 0] : [255, 255, 255]);

        $cropped = board_crop_and_pack($png, 8, 0, 8, 8);

        // Muss identisch sein zum direkten Packen einer separat erzeugten,
        // rein weissen 8x8-PNG derselben Region.
        $expected = $this->makeTestPng(8, 8, fn($x, $y) => [255, 255, 255]);
        $expectedPacked = png_to_1bpp_packed($expected, 8, 8);

        $this->assertSame($expectedPacked, $cropped);
    }

    public function test_crop_and_pack_of_the_black_half_matches_too(): void
    {
        $png = $this->makeTestPng(16, 8, fn($x, $y) => $x < 8 ? [0, 0, 0] : [255, 255, 255]);

        $cropped = board_crop_and_pack($png, 0, 0, 8, 8);

        $expected = $this->makeTestPng(8, 8, fn($x, $y) => [0, 0, 0]);
        $expectedPacked = png_to_1bpp_packed($expected, 8, 8);

        $this->assertSame($expectedPacked, $cropped);
    }
```

- [x] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/BoardRenderTest.php`
Expected: FAIL — `board_frame_diff()`/`board_crop_and_pack()` undefined.

- [x] **Step 3: Implement in `inc/board_render.php`**

Append at the end of `inc/board_render.php`:

```php
const BOARD_WIDTH = 1872;
const BOARD_HEIGHT = 1404;

/**
 * Bounding-Box aller unterschiedlichen Bytes zwischen zwei gepackten
 * 1bpp-Frames (Spec §4 Schritt 6). x/w liegen immer auf Byte-Grenzen (8px) --
 * bitgenaues Cropping waere fuer den Firmware-Schreibvorgang unnoetig
 * kompliziert; y/h sind pixelgenau, da jede Zeile eigene Bytes hat.
 *
 * @return array{x:int,y:int,w:int,h:int}|null null = Frames identisch (kein Patch noetig)
 * @throws never -- bei Laengenmismatch wird bewusst der volle Bereich zurueckgegeben statt eines Fehlers, s. Global Constraints
 */
function board_frame_diff(string $oldPacked, string $newPacked, int $width, int $height): ?array
{
    if (strlen($oldPacked) !== strlen($newPacked)) {
        return ['x' => 0, 'y' => 0, 'w' => $width, 'h' => $height];
    }

    $rowBytes = intdiv($width + 7, 8);
    $minByteX = null;
    $maxByteX = null;
    $minY = null;
    $maxY = null;

    for ($y = 0; $y < $height; $y++) {
        $rowStart = $y * $rowBytes;
        for ($b = 0; $b < $rowBytes; $b++) {
            if ($oldPacked[$rowStart + $b] !== $newPacked[$rowStart + $b]) {
                if ($minY === null) {
                    $minY = $y;
                }
                $maxY = $y;
                if ($minByteX === null || $b < $minByteX) {
                    $minByteX = $b;
                }
                if ($maxByteX === null || $b > $maxByteX) {
                    $maxByteX = $b;
                }
            }
        }
    }

    if ($minY === null) {
        return null;
    }

    $x = $minByteX * 8;
    $w = min($width - $x, ($maxByteX - $minByteX + 1) * 8);

    return ['x' => $x, 'y' => $minY, 'w' => $w, 'h' => $maxY - $minY + 1];
}

/**
 * Schneidet ein Rechteck aus dem vollen PNG (vor der 1bpp-Packung) und packt
 * NUR diesen Ausschnitt -- fuer den Patch-Antwortkoerper aus Spec §5. $x muss
 * ein Vielfaches von 8 sein (board_frame_diff() garantiert das).
 *
 * @throws RuntimeException wenn das PNG unlesbar ist
 */
function board_crop_and_pack(string $pngBinary, int $x, int $y, int $w, int $h): string
{
    $src = @imagecreatefromstring($pngBinary);
    if ($src === false) {
        throw new RuntimeException('PNG konnte nicht gelesen werden');
    }

    $cropped = imagecreatetruecolor($w, $h);
    imagecopy($cropped, $src, 0, 0, $x, $y, $w, $h);

    ob_start();
    imagepng($cropped);
    $croppedPng = (string) ob_get_clean();

    return png_to_1bpp_packed($croppedPng, $w, $h);
}
```

- [x] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/BoardRenderTest.php`
Expected: PASS, all tests green (including the pre-existing ones in this file).

- [x] **Step 5: Commit**

```bash
git add inc/board_render.php tests/Unit/BoardRenderTest.php
git commit -m "feat(board): add frame-diff bounding box + crop-and-pack for patch responses"
```

---

### Task 3: `inc/board.php` — alerts filter + device-signal normalizers

**Files:**
- Modify: `inc/board.php`
- Test: `tests/Unit/BoardFilterTest.php` (append — alerts filter, matches the file's existing filtering theme)
- Test: `tests/Unit/BoardEndpointTest.php` (append — battery/RSSI normalizers, matches this file's existing "board data prep" tests)

**Interfaces:**
- Consumes: `board_favorite()`'s return shape (already in this file: `['id','title','stations'=>[['diva','name','lines'=>[['line',...]]]]]]`).
- Produces (consumed by Task 5):
  - `board_filter_alerts_for_favorite(array $alerts, array $favorite): array` — same shape as `monitor_get()['alerts']` entries, filtered.
  - `board_battery_percent_from_mv(int $mv): int` — 0-100.
  - `board_wifi_bars_from_rssi(int $rssi): int` — 0-3.

- [x] **Step 1: Write the failing tests**

Append to `tests/Unit/BoardFilterTest.php`, inside the `BoardFilterTest` class,
right before the final closing `}`:

```php
    // --- board_filter_alerts_for_favorite() (Spec §8) -------------------------

    public function test_alerts_filter_keeps_only_alerts_matching_a_favorite_line(): void
    {
        $favorite = ['stations' => [['lines' => [['line' => 'U6'], ['line' => '13A']]]]];
        $alerts = [
            ['title' => 'Stoerung U6', 'description' => '...', 'lines' => ['U6'], 'stops' => []],
            ['title' => 'Stoerung U3', 'description' => '...', 'lines' => ['U3'], 'stops' => []],
        ];

        $result = board_filter_alerts_for_favorite($alerts, $favorite);

        $this->assertCount(1, $result);
        $this->assertSame('Stoerung U6', $result[0]['title']);
    }

    public function test_alerts_filter_keeps_alert_matching_any_of_multiple_lines(): void
    {
        $favorite = ['stations' => [['lines' => [['line' => '13A']]]]];
        $alerts = [
            ['title' => 'Sammelstoerung', 'description' => '...', 'lines' => ['U6', '13A'], 'stops' => []],
        ];

        $this->assertCount(1, board_filter_alerts_for_favorite($alerts, $favorite));
    }

    public function test_alerts_filter_drops_alert_with_no_matching_line(): void
    {
        $favorite = ['stations' => [['lines' => [['line' => 'U6']]]]];
        $alerts = [['title' => 'X', 'description' => '', 'lines' => ['U3'], 'stops' => []]];

        $this->assertSame([], board_filter_alerts_for_favorite($alerts, $favorite));
    }

    public function test_alerts_filter_handles_favorite_with_no_stations(): void
    {
        $favorite = ['stations' => []];
        $alerts = [['title' => 'X', 'description' => '', 'lines' => ['U6'], 'stops' => []]];

        $this->assertSame([], board_filter_alerts_for_favorite($alerts, $favorite));
    }
```

Append to `tests/Unit/BoardEndpointTest.php`, inside the `BoardEndpointTest`
class, right before the final closing `}`:

```php
    // --- board_battery_percent_from_mv() / board_wifi_bars_from_rssi() -------

    public function test_battery_percent_clamps_at_full_lipo_voltage(): void
    {
        $this->assertSame(100, board_battery_percent_from_mv(4200));
        $this->assertSame(100, board_battery_percent_from_mv(4500));
    }

    public function test_battery_percent_clamps_at_zero_below_lipo_floor(): void
    {
        $this->assertSame(0, board_battery_percent_from_mv(3300));
        $this->assertSame(0, board_battery_percent_from_mv(2900));
    }

    public function test_battery_percent_is_linear_at_midpoint(): void
    {
        $this->assertSame(50, board_battery_percent_from_mv(3750));
    }

    public function test_wifi_bars_thresholds(): void
    {
        $this->assertSame(3, board_wifi_bars_from_rssi(-50));
        $this->assertSame(2, board_wifi_bars_from_rssi(-65));
        $this->assertSame(1, board_wifi_bars_from_rssi(-75));
        $this->assertSame(0, board_wifi_bars_from_rssi(-90));
    }
```

- [x] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/BoardFilterTest.php tests/Unit/BoardEndpointTest.php`
Expected: FAIL — the three new functions are undefined.

- [x] **Step 3: Implement in `inc/board.php`**

Append at the end of `inc/board.php`:

```php
// ───────────────────────────────────────────────────────────────────────────
// Board-Protokoll: Störungen + Gerätesignale (Spec §8, §3, §9)
// ───────────────────────────────────────────────────────────────────────────

/**
 * Filtert monitor_get()['alerts'] auf die Linien des aktiven Favoriten
 * (Spec §8). Kein Normalisieren noetig -- inc/monitor.php kreuzreferenziert
 * relatedLines bereits mit einem blossen Gleichheitsvergleich.
 *
 * @param list<array{title:string,description:string,priority:string,lines:list<string>,stops:list<string>}> $alerts
 * @param array{stations: list<array{lines: list<array{line:string}>}>} $favorite board_favorite()-Form
 * @return list<array{title:string,description:string,priority:string,lines:list<string>,stops:list<string>}>
 */
function board_filter_alerts_for_favorite(array $alerts, array $favorite): array
{
    $favoriteLines = [];
    foreach ($favorite['stations'] ?? [] as $station) {
        foreach ($station['lines'] ?? [] as $line) {
            $favoriteLines[(string) ($line['line'] ?? '')] = true;
        }
    }

    return array_values(array_filter($alerts, static function (array $alert) use ($favoriteLines): bool {
        foreach ($alert['lines'] ?? [] as $line) {
            if (isset($favoriteLines[(string) $line])) {
                return true;
            }
        }
        return false;
    }));
}

/**
 * Roher Akku-Millivolt-Wert -> grober Prozentwert (Spec §3, §13: bewusst
 * keine kalibrierte Fuel-Gauge-Kurve). Lineare Spreizung ueber den ueblichen
 * LiPo-Nutzbereich (3300-4200 mV), geklemmt auf 0-100.
 */
function board_battery_percent_from_mv(int $mv): int
{
    $percent = (int) round(($mv - 3300) / (4200 - 3300) * 100);
    return max(0, min(100, $percent));
}

/**
 * WLAN-RSSI (dBm) -> Balkenzahl 0-3 fuer die Kopfzeile (Spec §9). Grobe,
 * uebliche Schwellwerte -- keine praezise Kalibrierung vorgesehen (Spec §13,
 * analog zur Akku-Prozentanzeige).
 */
function board_wifi_bars_from_rssi(int $rssi): int
{
    if ($rssi >= -60) return 3;
    if ($rssi >= -70) return 2;
    if ($rssi >= -80) return 1;
    return 0;
}
```

- [x] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/BoardFilterTest.php tests/Unit/BoardEndpointTest.php`
Expected: PASS, all tests green.

- [x] **Step 5: Commit**

```bash
git add inc/board.php tests/Unit/BoardFilterTest.php tests/Unit/BoardEndpointTest.php
git commit -m "feat(board): add disruptions filter + battery/RSSI normalizers"
```

---

### Task 4: `tests/fixtures/token_probe.php` — capture response headers

**Files:**
- Modify: `tests/fixtures/token_probe.php`
- Test: `tests/Integration/TokenProbeHeaderCaptureTest.php` (new)

**Interfaces:**
- Consumes: nothing from other tasks (pure test infrastructure).
- Produces (consumed by Task 5): the probe's STDERR now contains, on its own
  line, `HEADERS:<json-encoded array of "Name: value" strings from
  headers_list()>` in addition to the existing `STATUS:<code>` line. The
  probe also accepts a new scenario key `headers` (`array<string,string>`,
  header name → value), merged into `$_SERVER` the same way real web servers
  populate `HTTP_*` — this is what lets integration tests set
  `X-Device-Touch`, `X-Device-Battery-mV`, `X-Device-RSSI`, `If-None-Match`.

- [x] **Step 1: Write the failing test**

Create `tests/Integration/TokenProbeHeaderCaptureTest.php`:

```php
<?php
// tests/Integration/TokenProbeHeaderCaptureTest.php
//
// tests/fixtures/token_probe.php now also captures headers_list() to STDERR
// (Board-Protokoll plan, Task 4) -- needed because the rewritten
// web/board.php (Task 5) returns a binary body on STDOUT, so response
// metadata (Content-Type, X-Board-*) can no longer be inferred from the body.
// Exercised here against the STILL-JSON board.php (this task runs before
// Task 5's rewrite) since its Content-Type header is a stable, unrelated
// fixture to assert against.

namespace WLMonitor\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class TokenProbeHeaderCaptureTest extends TestCase
{
    /** @return array{status: ?int, out: string, headers: list<string>} */
    private function runProbe(string $page, array $scenario): array
    {
        $scenarioFile = tempnam(sys_get_temp_dir(), 'wlm_tok_');
        file_put_contents($scenarioFile, json_encode($scenario));
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/token_probe.php')
             . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($scenarioFile);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($scenarioFile);

        $status = null;
        if (preg_match('/STATUS:(\d+)/', $stderr, $m)) {
            $status = (int) $m[1];
        }
        $headers = [];
        if (preg_match('/HEADERS:(.+)/', $stderr, $m)) {
            $headers = json_decode($m[1], true) ?? [];
        }
        return ['status' => $status, 'out' => $stdout, 'headers' => $headers];
    }

    public function test_headers_list_is_captured_on_stderr(): void
    {
        $r = $this->runProbe('board.php', []); // kein Authorization-Header -> 401, aber Header stehen trotzdem

        $this->assertSame(401, $r['status']);
        $matches = array_filter($r['headers'], static fn (string $h): bool =>
            str_starts_with($h, 'Content-Type: application/json'));
        $this->assertNotEmpty($matches, 'Content-Type-Header muss in headers_list() auftauchen: ' . json_encode($r['headers']));
    }

    public function test_custom_headers_scenario_key_reaches_the_page_as_a_server_superglobal(): void
    {
        // board.php selbst liest If-None-Match nicht (Stand Task 4) -- dieser
        // Test prueft nur, dass der Header uebertragen wird, ohne die Seite
        // zum Absturz zu bringen.
        $r = $this->runProbe('board.php', ['headers' => ['If-None-Match' => '"abc"']]);

        $this->assertSame(401, $r['status'], 'unveraendertes Verhalten: fehlender Authorization-Header bleibt 401');
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/TokenProbeHeaderCaptureTest.php`
Expected: FAIL — no `HEADERS:` line on STDERR yet.

- [x] **Step 3: Implement in `tests/fixtures/token_probe.php`**

Change the scenario-key doc comment block (lines 15-22) to document the new
`headers` key — replace:

```php
 * Scenario JSON keys:
 *   authorization    ?string  Value for the Authorization header (e.g. "Bearer xyz").
 *                              Omitted/null → no Authorization header at all.
 *   get              ?array   Merged into $_GET (e.g. ['fav' => '3']).
 *   mock_wl_response ?string  Raw JSON body to serve for any https:// fetch —
 *                              lets the probe exercise monitor_get() without a
 *                              real network call, matching
 *                              tests/Unit/MonitorParserTest.php's MockHttpWrapper.
 *
 * STDOUT carries whatever the page echoes (the JSON body). The HTTP status
 * code is reported on STDERR as "STATUS:<code>\n" via a shutdown function.
 */
```

with:

```php
 * Scenario JSON keys:
 *   authorization    ?string  Value for the Authorization header (e.g. "Bearer xyz").
 *                              Omitted/null → no Authorization header at all.
 *   get              ?array   Merged into $_GET (e.g. ['fav' => '3']).
 *   headers          ?array   Extra request headers, name => value (e.g.
 *                              ['X-Device-Touch' => 'fav1', 'If-None-Match' => '"abc"']).
 *                              Merged into $_SERVER as HTTP_<NAME> the same way
 *                              a real web server populates header superglobals.
 *   mock_wl_response ?string  Raw JSON body to serve for any https:// fetch —
 *                              lets the probe exercise monitor_get() without a
 *                              real network call, matching
 *                              tests/Unit/MonitorParserTest.php's MockHttpWrapper.
 *
 * STDOUT carries whatever the page echoes (the response body — binary for
 * board.php since the Board-Protokoll rewrite). The HTTP status code is
 * reported on STDERR as "STATUS:<code>\n", and the full response header list
 * (headers_list()) as "HEADERS:<json array>\n", both via a shutdown function
 * -- STDOUT alone can no longer carry response metadata once it's opaque
 * binary.
 */
```

Then, right after the existing line `$_SERVER['REMOTE_ADDR'] = '127.0.0.1';`,
add:

```php
foreach ($scenario['headers'] ?? [] as $name => $value) {
    $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = (string) $value;
}
```

Finally, replace the shutdown function:

```php
register_shutdown_function(static function (): void {
    fwrite(STDERR, 'STATUS:' . http_response_code() . "\n");
});
```

with:

```php
register_shutdown_function(static function (): void {
    fwrite(STDERR, 'STATUS:' . http_response_code() . "\n");
    fwrite(STDERR, 'HEADERS:' . json_encode(headers_list(), JSON_UNESCAPED_SLASHES) . "\n");
});
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/TokenProbeHeaderCaptureTest.php`
Expected: PASS.

- [x] **Step 5: Run the full existing suite to confirm nothing else broke**

Run: `vendor/bin/phpunit`
Expected: PASS (the `headers` key defaults to `[]` via `?? []`, so every
existing scenario JSON without it is unaffected; the extra `HEADERS:` STDERR
line is additive and no existing test parses STDERR by exact line count).

- [x] **Step 6: Commit**

```bash
git add tests/fixtures/token_probe.php tests/Integration/TokenProbeHeaderCaptureTest.php
git commit -m "test(board): capture response headers in token_probe.php for binary endpoints"
```

---

### Task 5: `web/board.php` — rewrite to the binary Board-Protokoll

**Files:**
- Modify: `web/board.php` (full rewrite)
- Modify: `tests/Integration/BoardTokenEndpointTest.php` — update `runProbe()`
  to capture headers (same change as Task 4, applied to this file's own
  copy), update the two tests that assert on a JSON body, add new tests for
  the binary protocol.

**Interfaces:**
- Consumes (from Tasks 1-4): `board_state_hash()`, `board_state_meta_path()`,
  `board_state_frame_path()`, `board_state_load_meta()`,
  `board_state_save_meta()`, `board_state_load_frame()`,
  `board_state_save_frame()`, `board_resolve_touch()`; `BOARD_WIDTH`,
  `BOARD_HEIGHT`, `board_frame_diff()`, `board_crop_and_pack()`;
  `board_filter_alerts_for_favorite()`, `board_battery_percent_from_mv()`,
  `board_wifi_bars_from_rssi()`; the `headers` scenario key in
  `token_probe.php`. Also consumes already-shipped functions:
  `auth_api_request_user()`, `auth_api_token_presented()`,
  `auth_api_token_from_request()`, `appendLog()`, `favorites_get()`,
  `board_all_divas()`, `monitor_get()`, `monitor_inject_missing_stations()`,
  `board_favorite()`, `weather_select_display()`, `board_paginate_departures()`,
  `board_render_svg()`, `svg_to_png()`, `png_to_1bpp_packed()`.
- Produces: the live `GET /board.php` HTTP contract from Global Constraints.
  Nothing downstream in this codebase consumes it in-process (it's an
  external device endpoint) — this is the final task.

- [x] **Step 1: Update `runProbe()` in `tests/Integration/BoardTokenEndpointTest.php`**

Replace the existing `runProbe()` method:

```php
    /** @return array{status: ?int, out: string} */
    private function runProbe(string $page, array $scenario): array
    {
        $scenarioFile = tempnam(sys_get_temp_dir(), 'wlm_tok_');
        file_put_contents($scenarioFile, json_encode($scenario));
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/token_probe.php')
             . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($scenarioFile);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($scenarioFile);

        $status = null;
        if (preg_match('/STATUS:(\d+)/', $stderr, $m)) {
            $status = (int) $m[1];
        }
        return ['status' => $status, 'out' => $stdout];
    }
```

with:

```php
    /** @return array{status: ?int, out: string, headers: list<string>} */
    private function runProbe(string $page, array $scenario): array
    {
        $scenarioFile = tempnam(sys_get_temp_dir(), 'wlm_tok_');
        file_put_contents($scenarioFile, json_encode($scenario));
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/token_probe.php')
             . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($scenarioFile);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($scenarioFile);

        $status = null;
        if (preg_match('/STATUS:(\d+)/', $stderr, $m)) {
            $status = (int) $m[1];
        }
        $headers = [];
        if (preg_match('/HEADERS:(.+)/', $stderr, $m)) {
            $headers = json_decode($m[1], true) ?? [];
        }
        return ['status' => $status, 'out' => $stdout, 'headers' => $headers];
    }

    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $h) {
            if (str_starts_with($h, $name . ':')) {
                return trim(substr($h, strlen($name) + 1));
            }
        }
        return null;
    }

    private function mockMonitorResponse(string $diva, int $countdown): string
    {
        return json_encode([
            'message' => ['serverTime' => '2026-08-16T19:00:00+02:00'],
            'data' => ['monitors' => [[
                'locationStop' => ['properties' => [
                    'title' => 'Halt', 'name' => 'STK' . $diva, 'diva' => ['statId' => $diva],
                ]],
                'lines' => [[
                    'name' => 'L1', 'towards' => 'Z', 'type' => 'ptTram', 'platform' => '1',
                    'departures' => ['departure' => [['departureTime' => ['countdown' => $countdown]]]],
                ]],
            ]]],
        ]);
    }
```

- [x] **Step 2: Write the failing tests for the binary protocol**

The two existing board.php tests that assert on a JSON body no longer match
the rewritten contract. Replace `test_board_shows_placeholder_for_diva_the_wl_api_omitted`
and `test_board_returns_200_not_503_when_wl_api_has_nothing_for_any_requested_diva`:

```php
    public function test_board_shows_placeholder_for_diva_the_wl_api_omitted(): void
    {
        // Die eigentliche Platzhalter-Logik (eine gefilterte, von der WL-API
        // stillschweigend weggelassene Station bleibt als leere Karte
        // bestehen statt zu verschwinden) ist auf inc/board.php- und
        // inc/monitor.php-Ebene unit-getestet (board_favorite(),
        // monitor_inject_missing_stations()). Hier, end-to-end durch den
        // binaeren Endpunkt, ist nur noch pruefbar, dass die Pipeline dabei
        // NICHT fehlschlaegt -- der Koerper selbst ist opakes 1bpp.
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111,90222222', [
            '90222222' => [['line' => '63', 'platform' => '1']],
        ]);

        $mock = json_encode([
            'message' => ['serverTime' => '2026-08-16T10:00:00+02:00'],
            'data'    => ['monitors' => [[
                'locationStop' => ['properties' => [
                    'title' => 'Halt 1', 'name' => 'STK90111111', 'diva' => ['statId' => '90111111'],
                ]],
                'lines' => [[
                    'name' => 'L1', 'towards' => 'Z', 'type' => 'ptTram', 'platform' => '1',
                    'departures' => ['departure' => [['departureTime' => ['countdown' => 4]]]],
                ]],
            ]]],
        ]);

        $r = $this->runProbe('board.php', [
            'authorization'    => 'Bearer ' . $token,
            'mock_wl_response' => $mock,
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertSame('full', $this->headerValue($r['headers'], 'X-Board-Mode'));
        $this->assertGreaterThan(0, strlen($r['out']), 'Body darf nicht leer sein');
    }

    public function test_board_returns_200_not_503_when_wl_api_has_nothing_for_any_requested_diva(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90333333', null);

        // WL API returns an empty monitors array — monitor_get() throws
        // RuntimeException('No monitors found…') for this.
        $mock = json_encode([
            'message' => ['serverTime' => '2026-08-16T10:00:00+02:00'],
            'data'    => ['monitors' => []],
        ]);

        $r = $this->runProbe('board.php', [
            'authorization'    => 'Bearer ' . $token,
            'mock_wl_response' => $mock,
        ]);

        $this->assertSame(200, $r['status'], 'no departures anywhere is not an outage');
        $this->assertSame('full', $this->headerValue($r['headers'], 'X-Board-Mode'));
    }

    // --- Board-Protokoll: Vollbild/Patch/Touch/Debug (Spec §4, §5, §6) -------

    public function test_first_poll_for_a_device_is_always_full_mode(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);
        $mock = $this->mockMonitorResponse('90111111', 4);

        $r = $this->runProbe('board.php', ['authorization' => 'Bearer ' . $token, 'mock_wl_response' => $mock]);

        $this->assertSame(200, $r['status']);
        $this->assertSame('full', $this->headerValue($r['headers'], 'X-Board-Mode'));
        $this->assertSame('1872', $this->headerValue($r['headers'], 'X-Board-W'));
        $this->assertSame('1404', $this->headerValue($r['headers'], 'X-Board-H'));
        $this->assertSame((string) strlen($r['out']), $this->headerValue($r['headers'], 'Content-Length'));
        $this->assertNotNull($this->headerValue($r['headers'], 'X-Board-ETag'));
    }

    public function test_second_poll_with_matching_etag_and_unchanged_favorite_returns_patch_mode(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);

        $r1 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
        ]);
        $etag = $this->headerValue($r1['headers'], 'X-Board-ETag');
        $this->assertNotNull($etag);

        // Andere Abfahrtszeit -> garantiert sichtbar anderer Frame, unabhaengig
        // von der Uhrzeit, zu der der Test laeuft.
        $r2 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 9),
            'headers' => ['If-None-Match' => $etag],
        ]);

        $this->assertSame(200, $r2['status']);
        $this->assertSame('patch', $this->headerValue($r2['headers'], 'X-Board-Mode'));
        $this->assertGreaterThan(0, strlen($r2['out']));
        $w = (int) $this->headerValue($r2['headers'], 'X-Board-W');
        $h = (int) $this->headerValue($r2['headers'], 'X-Board-H');
        $this->assertLessThan(1872 * 1404, $w * $h, 'ein Patch darf nicht die volle Flaeche sein');
    }

    public function test_favorite_switch_touch_forces_full_mode_even_with_matching_etag(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('A', '90111111', null);
        $this->createFavorite('B', '90222222', null);

        $r1 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
        ]);
        $etag = $this->headerValue($r1['headers'], 'X-Board-ETag');

        $r2 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90222222', 4),
            'headers' => ['If-None-Match' => $etag, 'X-Device-Touch' => 'fav1'],
        ]);

        $this->assertSame(200, $r2['status']);
        $this->assertSame('full', $this->headerValue($r2['headers'], 'X-Board-Mode'));
    }

    public function test_malformed_upstream_response_returns_503_json_error(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);

        $r = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => 'not valid json',
        ]);

        $this->assertSame(503, $r['status']);
        $this->assertSame('application/json; charset=utf-8', $this->headerValue($r['headers'], 'Content-Type'));
        $this->assertSame(['error' => 'upstream_unavailable'], json_decode($r['out'], true));
    }

    public function test_debug_svg_returns_raw_svg_and_bypasses_state_logic(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);

        $r = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
            'get' => ['debug' => 'svg'],
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertSame('image/svg+xml; charset=utf-8', $this->headerValue($r['headers'], 'Content-Type'));
        $this->assertStringStartsWith('<svg', $r['out']);
        $this->assertNull($this->headerValue($r['headers'], 'X-Board-Mode'), 'Debug-Zweig darf keine Diff-/State-Header setzen');
    }

    public function test_debug_png_returns_raw_png(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);

        $r = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
            'get' => ['debug' => 'png'],
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertSame('image/png', $this->headerValue($r['headers'], 'Content-Type'));
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $r['out']);
    }

    public function test_user_with_no_favorites_still_gets_a_valid_full_frame(): void
    {
        // Kein mock_wl_response -- board.php darf monitor_get() bei null
        // Favoriten gar nicht erst aufrufen, sonst wuerde dieser Test an
        // einem echten (fehlenden) Netzwerkzugriff haengen bleiben.
        $token = $this->createTokenUser();

        $r = $this->runProbe('board.php', ['authorization' => 'Bearer ' . $token]);

        $this->assertSame(200, $r['status']);
        $this->assertSame('full', $this->headerValue($r['headers'], 'X-Board-Mode'));
        $this->assertGreaterThan(0, strlen($r['out']));
    }
```

- [x] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Integration/BoardTokenEndpointTest.php`
Expected: FAIL — `web/board.php` still returns JSON, none of the
`X-Board-*` headers exist yet, `debug` is unhandled.

- [x] **Step 4: Rewrite `web/board.php`**

Replace the entire file content with:

```php
<?php
/**
 * web/board.php — Bild-Protokoll für Geräte (E-Paper-Display), token-
 * authentifiziert. Liefert rohe 1bpp-Pixeldaten statt JSON (Spec §5).
 *
 * GET /board.php
 * Authorization: Bearer <token>      (X-Auth-Token als Ausweichheader)
 * X-Device-Battery-mV: <n>           (optional)
 * X-Device-RSSI: <n>                 (optional)
 * X-Device-Touch: fav0|fav1|fav2|page_prev|page_next   (optional)
 * If-None-Match: "<letzter ETag>"    (optional)
 *
 * Bewusst KEINE Sitzung -- wie im Vorgänger, alles haengt am Token-Nutzer.
 * Pro-Geraet-Zustand (aktiver Favorit/Seite/letzter Frame) liegt in
 * data/board_state/<sha256(token)>.{json,frame} (inc/board_state.php),
 * kein $_SESSION.
 *
 * Fehler nennen nach aussen nur eine Kennung; die Ursache geht ins auth_log
 * (Fehler-Regeln §21). 401/503/500 haben KEINEN Bildkoerper (Spec §5).
 *
 * ?debug=svg / ?debug=png liefern Zwischenstufen der Rendering-Pipeline
 * (gleiche Auth, aber OHNE Diff-/Patch-/State-Logik, Spec §6).
 *
 * Spec: docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/favorites.php';
require_once __DIR__ . '/../inc/monitor.php';
require_once __DIR__ . '/../inc/board.php';
require_once __DIR__ . '/../inc/weather.php';
require_once __DIR__ . '/../inc/board_render.php';
require_once __DIR__ . '/../inc/board_template.php';
require_once __DIR__ . '/../inc/board_state.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Fehlerantwort senden und beenden -- einziger JSON-Zweig dieses Endpunkts. */
function board_error_out(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

$userId = auth_api_request_user();
if ($userId === null) {
    if (auth_api_token_presented()) {
        appendLog($con, 'board', 'Zugriff ohne gueltiges Token (Token vorgelegt, aber ungueltig)');
    }
    board_error_out(['error' => 'unauthorized'], 401);
}

try {
    $token = auth_api_token_from_request();
    $hash = board_state_hash($token);
    $metaPath = board_state_meta_path($hash);
    $framePath = board_state_frame_path($hash);
    $oldMeta = board_state_load_meta($metaPath);

    $touchBarFavorites = array_slice(favorites_get($con, $userId), 0, 3);
    $touchBarTitles = array_map(static fn (array $f): string => (string) $f['title'], $touchBarFavorites);

    $touchHeader = $_SERVER['HTTP_X_DEVICE_TOUCH'] ?? null;
    $resolved = board_resolve_touch($oldMeta, is_string($touchHeader) ? $touchHeader : null, count($touchBarFavorites));

    if ($touchBarFavorites === []) {
        // Frisch provisioniertes Geraet ohne Favoriten -- kein monitor_get()-
        // Aufruf (kein Netz noetig), leeres aber gueltiges Board.
        $activeFavorite = ['id' => 0, 'title' => '', 'stations' => []];
        $filteredAlerts = [];
    } else {
        $activeFavoriteRaw = $touchBarFavorites[$resolved['activeFavoriteIndex']];
        $divas = board_all_divas([$activeFavoriteRaw]);

        try {
            $monitor = monitor_get($con, $divas, 2);
        } catch (RuntimeException $e) {
            // "Keine Abfahrten fuer keine der angefragten DIVAs" ist kein
            // Upstream-Ausfall, sondern ein gueltiger Zustand -- nur DIESE
            // eine RuntimeException wird so behandelt.
            if (!str_contains($e->getMessage(), 'No monitors found')) {
                throw $e;
            }
            $monitor = [];
        }
        $monitor = monitor_inject_missing_stations($con, $monitor, $divas);

        $activeFavorite = board_favorite($activeFavoriteRaw, $monitor);
        $filteredAlerts = board_filter_alerts_for_favorite($monitor['alerts'] ?? [], $activeFavorite);
    }

    $totalDeparturePages = board_paginate_departures($activeFavorite, 1)['totalPages'];
    $totalPages = $totalDeparturePages + ($filteredAlerts !== [] ? 1 : 0);
    $requestedPage = max(1, min($totalPages, $resolved['activePage']));

    $weatherCachePath = __DIR__ . '/../data/weather_cache.json';
    $weatherCache = file_exists($weatherCachePath)
        ? json_decode((string) file_get_contents($weatherCachePath), true)
        : null;

    $renderedAt = new DateTimeImmutable();
    $dataStand = $renderedAt; // s. Global Constraints: bewusst kein Reparse von monitor_get()['update_at']
    $weather = weather_select_display(is_array($weatherCache) ? $weatherCache : null, $renderedAt);

    $batteryMv = $_SERVER['HTTP_X_DEVICE_BATTERY_MV'] ?? null;
    $batteryPercent = is_numeric($batteryMv) ? board_battery_percent_from_mv((int) $batteryMv) : 0;
    $rssi = $_SERVER['HTTP_X_DEVICE_RSSI'] ?? null;
    $wifiBars = is_numeric($rssi) ? board_wifi_bars_from_rssi((int) $rssi) : 0;

    $svg = board_render_svg(
        $touchBarTitles,
        $resolved['activeFavoriteIndex'],
        $activeFavorite,
        $filteredAlerts,
        $requestedPage,
        $weather,
        $dataStand,
        $renderedAt,
        $batteryPercent,
        $wifiBars
    );

    $debug = (string) ($_GET['debug'] ?? '');
    if ($debug === 'svg') {
        header('Content-Type: image/svg+xml; charset=utf-8');
        echo $svg;
        exit;
    }

    $png = svg_to_png($svg);

    if ($debug === 'png') {
        header('Content-Type: image/png');
        echo $png;
        exit;
    }

    $newPacked = png_to_1bpp_packed($png, BOARD_WIDTH, BOARD_HEIGHT);
    $newEtag = '"' . hash('sha256', $newPacked) . '"';

    $oldFrame = board_state_load_frame($framePath);
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $stateChanged = $resolved['activeFavoriteIndex'] !== (int) ($oldMeta['activeFavoriteIndex'] ?? -1)
        || $requestedPage !== (int) ($oldMeta['activePage'] ?? -1);
    $fullRefreshAt = $oldMeta['fullRefreshAt'] ?? null;
    $recentFullRefresh = $fullRefreshAt !== null && (time() - (int) $fullRefreshAt) < 1800;

    $canPatch = $oldFrame !== null
        && $ifNoneMatch !== ''
        && $ifNoneMatch === ($oldMeta['etag'] ?? null)
        && $recentFullRefresh
        && !$stateChanged;

    $diff = $canPatch ? board_frame_diff($oldFrame, $newPacked, BOARD_WIDTH, BOARD_HEIGHT) : null;

    if ($diff !== null) {
        $mode = 'patch';
        $body = board_crop_and_pack($png, $diff['x'], $diff['y'], $diff['w'], $diff['h']);
        $x = $diff['x'];
        $y = $diff['y'];
        $w = $diff['w'];
        $h = $diff['h'];
    } else {
        // Voll-Frame: entweder kann nicht gepatcht werden (ETag-Mismatch,
        // 30-Min-Grenze, Favoriten-/Seitenwechsel, erster Poll ueberhaupt),
        // oder der neue Frame ist byte-identisch zum alten (board_frame_diff()
        // liefert dann null) -- in beiden Faellen ist ein Vollbild die
        // korrekte, immer gueltige Antwort.
        $mode = 'full';
        $body = $newPacked;
        $x = 0;
        $y = 0;
        $w = BOARD_WIDTH;
        $h = BOARD_HEIGHT;
        $fullRefreshAt = time();
    }

    board_state_save_meta($metaPath, [
        'activeFavoriteIndex' => $resolved['activeFavoriteIndex'],
        'activePage' => $requestedPage,
        'etag' => $newEtag,
        'fullRefreshAt' => $fullRefreshAt,
    ]);
    board_state_save_frame($framePath, $newPacked);

    header('X-Board-Mode: ' . $mode);
    header('X-Board-ETag: ' . $newEtag);
    header('X-Board-Generated: ' . $renderedAt->format(DATE_ATOM));
    header('X-Board-X: ' . $x);
    header('X-Board-Y: ' . $y);
    header('X-Board-W: ' . $w);
    header('X-Board-H: ' . $h);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
} catch (RuntimeException | InvalidArgumentException $e) {
    appendLog($con, 'board', 'Upstream-Fehler: ' . $e->getMessage());
    board_error_out(['error' => 'upstream_unavailable'], 503);
} catch (Throwable $e) {
    appendLog($con, 'board', 'Fehler: ' . get_class($e) . ': ' . $e->getMessage());
    board_error_out(['error' => 'server_error'], 500);
}
```

- [x] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/BoardTokenEndpointTest.php`
Expected: PASS, all tests green — including the 4 pre-existing
`monitor_json.php`/401-path tests, which this rewrite does not touch.

- [x] **Step 6: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS. This is the integration point for every task in this plan
plus the whole prior Board-SVG-Template feature — a regression anywhere in
that chain shows up here.

- [x] **Step 7: Manual smoke test against the real pipeline**

Not part of the automated suite (needs a real DB user + token + favorite),
but before committing, sanity-check by hand once against the dev environment:

```bash
curl -s -D- -o /tmp/board_frame.bin \
  -H "Authorization: Bearer <a real dev token>" \
  "http://localhost/wlmonitor.test/board.php" | grep -i '^X-Board'
ls -la /tmp/board_frame.bin   # sollte 1872*1404/8 = 328536 Byte sein bei Vollbild
```

- [x] **Step 8: Commit**

```bash
git add web/board.php tests/Integration/BoardTokenEndpointTest.php
git commit -m "feat(board): rewrite board.php to the binary image protocol (Board-Protokoll)"
```

---

## Self-Review

**Spec coverage:**
- §4 (Ablauf je Zyklus, Header, Favoriten-/Seitenaufloesung, Diff-Entscheidung, Zustandsspeicherung) → Tasks 1, 5.
- §5 (Bild-Protokoll: Request-/Response-Header, Fehlerkoerper, kein `fav`-Parameter, kein klassisches Caching) → Task 5.
- §6 (Rendering-Pipeline-Anschluss, 1bpp-Pfad bleibt, 4bpp explizit ausgeklammert, Debug-Endpunkte) → Tasks 2, 5.
- §8 (Störungsseite: Filterung, Anhaengen als letzte Seite(n), kein leeres "keine Störungen") → Task 3 (Filter) + Task 5 (bereits in `board_render_svg()` aus dem Vorgaenger-Plan verdrahtet — die Filterung ist das fehlende Stueck).
- §11 (Fehlerfaelle: 401/503/500 bleiben JSON ohne Bildkoerper; `If-None-Match`-Mismatch ist kein Firmware-Fehlerfall) → Task 5.
- §12 (Tests: Diff-/Patch-Logik, Endpunkt-Headervertrag, `Content-Length`, 401/503, `X-Device-Touch` verschiebt Zustand korrekt) → Tasks 2, 5.
- §13 (4bpp-Byte-Packung nicht Teil dieses Entwurfs; mehr als 3 Favoriten nicht vorgesehen) → explizit in Global Constraints und in Tasks 2/5 respektiert (nur die ersten 3 Favoriten werden je gelesen).

**Placeholder scan:** No TBD/TODO markers; every step carries complete,
runnable code and exact commands.

**Type consistency:** `board_resolve_touch()`'s return shape
(`activeFavoriteIndex`, `activePage`) matches the keys `web/board.php` reads
from `$resolved` in Task 5. `board_state_load_meta()`'s default shape
(`activeFavoriteIndex`, `activePage`, `etag`, `fullRefreshAt`) matches what
`board_state_save_meta()` writes and what `web/board.php` reads from
`$oldMeta`. `board_frame_diff()`'s return shape (`x`,`y`,`w`,`h`) matches
`board_crop_and_pack()`'s positional parameters in Task 5's call site.
`board_filter_alerts_for_favorite()`'s favorite-shape parameter matches
`board_favorite()`'s actual return shape (verified against `inc/board.php`'s
current implementation, not assumed).
