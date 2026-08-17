# reTerminal E1003 Firmware Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `epaper-monitor/`'s old Waveshare/GxEPD2/v1-JSON firmware
with new firmware for the Seeed reTerminal E1003, speaking the binary
Board-Protokoll (`web/board.php`) over HTTPS, with touch-driven
favorite/pagination navigation and a sleep/staleness indicator.

**Architecture:** Renovate-in-place (Approach A, approved during
brainstorming): the old wake→WiFi→fetch→render→sleep control flow and the
error-state escalation logic (`error_state.h/cpp`) are proven and
protocol-independent — kept unchanged. Everything protocol/hardware-bound
(HTTP client, display driver, config) is rewritten. New pure-logic modules
(`board_response`, `touch_zone`) are added to `lib/boardlogic/` so the
Board-Protokoll response validation and touch-to-zone mapping stay testable
via `pio test -e native` without hardware — matching the split already used
for `error_state`. No hardware exists yet: hardware-bound code (display,
touch, buttons, battery) is written completely and compiles
(`pio run -e esp32dev`), but is explicitly unverified until real hardware
arrives — this is called out per-task, not glossed over.

**Tech Stack:** PlatformIO, Arduino-ESP32 core (XIAO_ESP32S3 board target),
Seeed_GxEPD2 (Adafruit-GFX-based), WiFiManager (tzapu), ESP32 `Preferences`
(NVS), Unity (native tests, no ESP32 toolchain needed).

## Global Constraints

Copied verbatim from `docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md`
(§3/§4/§5/§9/§10/§11), binding for every task below.

- **Board:** Seeed reTerminal E1003 — ESP32-S3R8, 8MB OPI-PSRAM (must be
  enabled: `board_build.arduino.memory_type = qio_opi` in platformio.ini,
  the Arduino-IDE-menu equivalent of Tools > PSRAM > OPI PSRAM), panel
  1872×1404 (landscape, rotated from native portrait), 16-level grayscale
  capable but **this firmware renders 1bpp only** (server's rendering
  pipeline is 1bpp-only for now, 4bpp deferred — spec §6/§13, not this
  plan's concern to fix).
- **No hardware yet.** Every hardware-bound file must compile
  (`pio run -e esp32dev`) but cannot be runtime-verified. Say so explicitly
  in every hardware-bound task's self-review; do not claim verified
  behavior you cannot observe.
- **Pins** (verified against `reference_reterminal_e1003_arduino` memory,
  cross-checked where noted):
  ```c
  #define EPD_SCK_PIN       7
  #define EPD_MISO_PIN      8
  #define EPD_MOSI_PIN      9
  #define EPD_CS_PIN        10
  #define EPD_RES_PIN       12
  #define EPD_BUSY_PIN      13
  #define EPD_TFT_ENABLE    11
  #define EPD_ITE_ENABLE    21

  #define PIN_KEY0          3   // CONFIRMED: deep-sleep wake button (2 independent sources agree)
  #define PIN_KEY1          4   // role UNCONFIRMED for E1003 -- used here for page_prev
  #define PIN_KEY2          5   // role UNCONFIRMED for E1003 -- used here for page_next

  #define BATTERY_ADC_PIN     1
  #define BATTERY_ENABLE_PIN  40  // E1003-specific (other E10xx variants use GPIO21)

  #define TOUCH_SDA_PIN     19
  #define TOUCH_SCL_PIN     20
  #define TOUCH_INT_PIN     2
  #define TOUCH_RESET_PIN   48
  ```
- **HTTPS, not LAN listener** (spec §10, corrected 2026-08-17 — an earlier
  spec draft described a plaintext LAN-only listener left over from the
  pre-pivot design; the actually-built `web/board.php` has no IP-trust
  logic, it's a normal Bearer-token endpoint): `WiFiClientSecure` +
  `esp_crt_bundle_attach()` (ESP32's own root CA bundle, no pinning)
  against `https://wlmonitor.eriks.cloud/board.php`.
- **Minimal SNTP time sync before the first HTTPS request of each wake
  cycle** (decided during this plan's brainstorming — TLS certificate date
  validation needs an approximately-correct clock, which an ESP32 does not
  have after boot/deep-sleep-wake without this): a single best-effort
  `configTime()` call, short timeout, proceed with the HTTPS request
  regardless of whether it succeeds.
- **No secrets compiled into any header file.** WLAN credentials and the
  API token are entered via a WiFiManager captive-portal form and persisted
  in NVS flash (`Preferences`), never in source. `include/board_config.h`
  (new, **committed** — unlike the old gitignored `config.h`) holds only
  non-secret, same-for-every-device constants: `BOARD_HOST`, `BOARD_PORT`,
  `POLL_INTERVAL_SEC`.
- **Request headers** (spec §5): `Authorization: Bearer <token>`,
  `X-Device-Battery-mV: <n>`, `X-Device-RSSI: <n>`,
  `X-Device-Touch: <fav0|fav1|fav2|page_prev|page_next>` (omitted unless a
  touch/button triggered this wake), `If-None-Match: <last known ETag>`
  (empty on first-ever poll).
- **Response headers** (spec §5, including two headers added 2026-08-17
  specifically to support this firmware's touch-zone mapping):
  ```
  X-Board-Mode: full | patch
  X-Board-ETag: "<sha256>"
  X-Board-Generated: <ISO8601>
  X-Board-X / X-Board-Y / X-Board-W / X-Board-H: <int>  (always present, 0/0/1872/1404 for full)
  X-Board-Favorite-Count: 0-3
  X-Board-Total-Pages: 1-n
  Content-Type: application/octet-stream
  Content-Length: <n>
  ```
  Body: raw packed 1bpp pixel data, MSB-first, row width rounded up to a
  multiple of 8 bytes, **1=white, 0=black**.
- **Touch zones** (spec §9/§10, resolved during this plan's brainstorming):
  - **3 favorite buttons**, footer row `y∈[1320,1394)`, only as many zones
    as `X-Board-Favorite-Count` says, dynamically split:
    `buttonWidth = (1872 - 32 - (count-1)*16) / count`,
    `zone[i].x ∈ [16 + i*(buttonWidth+16), that + buttonWidth)`.
  - **2 pagination zones** (left half = prev, right half = next), only if
    `X-Board-Total-Pages > 1`, pill row `y∈[1256,1304)`:
    `pillWidth = max(290, (880 + totalPages*58) - 822 + 58)`,
    pill spans `x∈[793, 793+pillWidth)`, split at the midpoint. Deliberately
    the WHOLE half as the target, not a tight box around the tiny arrow
    glyph — touch is the *primary* input here (physical side buttons are a
    secondary, redundant path, flagged as hard to reach on a wall-mounted
    unit).
  - The green refresh/wake button (GPIO3/KEY0) wakes from deep sleep but
    sends no `X-Device-Touch` value at all.
- **Sleep/staleness marker** (spec §9/§10/§11, replaces the older
  timestamp-inversion idea): immediately before `esp_deep_sleep_start()`,
  overwrite the WiFi-bars header rect (`x∈[1665,1719), y∈[46,74)` — 3 bars
  at `translate(1665,46)`, each roughly 18px tall) with a simple "zzz" mark.
  No asset needed, drawn with GxEPD2's built-in default font.
- **Error banner escalation** (spec §11, unchanged from the old firmware —
  `error_state.h/cpp` is reused as-is): `NetworkUnavailable` (WiFi failure,
  HTTP 503/timeout) and `UnreadableResponse` (headers missing or
  Content-Length mismatch) both show an "offline seit HH:MM" banner after 3
  consecutive failures; `Unauthorized` (HTTP 401) shows a "Token ungültig"
  banner immediately. `Success` resets the counter. Banner text uses
  GxEPD2's built-in default font — no custom bitmap font asset.
- **`epaper-monitor/` stays excluded from web deploy**: already listed in
  `scripts/ssh_deploy.php`'s exclude array (verified, not assumed) — no
  change needed there.

## File Structure

- Create: `epaper-monitor/lib/boardlogic/board_response.h`, `.cpp` — pure
  Board-Protokoll response-header validation (native-testable).
- Create: `epaper-monitor/lib/boardlogic/touch_zone.h`, `.cpp` — pure touch
  coordinate → zone mapping (native-testable).
- Delete: `epaper-monitor/lib/boardlogic/board_model.h`, `.cpp`,
  `layout.h`, `.cpp` (client-side JSON model / layout math — obsolete, the
  server now sends pre-rendered pixels).
- Delete: `epaper-monitor/test/test_board_model/`, `test/test_layout/`
  (tests for the deleted modules above).
- Delete: `epaper-monitor/src/logo.h`, `src/panel_select.h`,
  `src/bw_test.cpp`, `tools/convert_logo.py`, `tools/test_convert_logo.py`,
  `include/config.example.h` (all specific to the old Waveshare 3-color
  panel or the old gitignored-secrets config pattern).
- Create: `epaper-monitor/include/board_config.h` (**committed**,
  non-secret infra constants).
- Rewrite: `epaper-monitor/src/board_client.h`, `.cpp` — HTTPS I/O against
  the new binary protocol (hardware-bound, uses `board_response.h`).
- Rewrite: `epaper-monitor/src/display.h`, `.cpp` — GxEPD2 driver: init,
  full/patch blit, sleep-icon patch, local error banner (hardware-bound).
- Create: `epaper-monitor/src/touch.h`, `.cpp` — GT911 I2C driver
  (hardware-bound, uses `touch_zone.h`).
- Create: `epaper-monitor/src/buttons.h`, `.cpp` — GPIO button reads
  (hardware-bound).
- Create: `epaper-monitor/src/battery.h`, `.cpp` — battery ADC read
  (hardware-bound).
- Rewrite: `epaper-monitor/src/main.cpp` — orchestration: SNTP,
  WiFiManager provisioning, wake/poll/apply/sleep loop, RTC-anchored
  failure counter (hardware-bound).
- Rewrite: `epaper-monitor/src/hw_bringup.cpp` (was `bw_test.cpp`) —
  minimal E1003 display bring-up smoke sketch, isolated PlatformIO
  environment.
- Modify: `epaper-monitor/platformio.ini` — new board target, PSRAM
  setting, new lib_deps.
- Modify: `epaper-monitor/README.md` — new build/provisioning
  instructions (no more `config.h` secrets).

---

### Task 1: `lib/boardlogic/board_response.h/cpp` — pure response-header validation

**Files:**
- Create: `epaper-monitor/lib/boardlogic/board_response.h`
- Create: `epaper-monitor/lib/boardlogic/board_response.cpp`
- Test: `epaper-monitor/test/test_board_response/test_board_response.cpp` (new)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces (consumed by Task 4): `BoardHeaders` struct, `BoardResponseStatus`
  enum, `ParsedBoardResponse` struct, `validateBoardResponse(const
  BoardHeaders&, size_t): ParsedBoardResponse`.

- [ ] **Step 1: Write the failing tests**

Create `epaper-monitor/test/test_board_response/test_board_response.cpp`:

```cpp
#include <unity.h>
#include "board_response.h"

void test_valid_full_frame_headers_parse_ok(void) {
    BoardHeaders h;
    h.mode = "full"; h.etag = "\"abc\""; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "1872"; h.h = "1404";
    h.contentLength = "10"; h.favoriteCount = "1"; h.totalPages = "1";

    ParsedBoardResponse r = validateBoardResponse(h, 10);

    TEST_ASSERT_TRUE(r.status == BoardResponseStatus::Ok);
    TEST_ASSERT_FALSE(r.isPatch);
    TEST_ASSERT_EQUAL(1872, r.w);
    TEST_ASSERT_EQUAL(1404, r.h);
    TEST_ASSERT_EQUAL(1, r.favoriteCount);
    TEST_ASSERT_EQUAL(1, r.totalPages);
}

void test_valid_patch_headers_parse_ok(void) {
    BoardHeaders h;
    h.mode = "patch"; h.etag = "\"def\""; h.generated = "2026-08-17T10:05:00+02:00";
    h.x = "8"; h.y = "16"; h.w = "64"; h.h = "32";
    h.contentLength = "256"; h.favoriteCount = "3"; h.totalPages = "2";

    ParsedBoardResponse r = validateBoardResponse(h, 256);

    TEST_ASSERT_TRUE(r.status == BoardResponseStatus::Ok);
    TEST_ASSERT_TRUE(r.isPatch);
    TEST_ASSERT_EQUAL(8, r.x);
    TEST_ASSERT_EQUAL(16, r.y);
    TEST_ASSERT_EQUAL(3, r.favoriteCount);
    TEST_ASSERT_EQUAL(2, r.totalPages);
}

void test_missing_mode_is_malformed(void) {
    BoardHeaders h;
    h.etag = "\"abc\""; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "1872"; h.h = "1404";
    h.contentLength = "10"; h.favoriteCount = "1"; h.totalPages = "1";

    TEST_ASSERT_TRUE(validateBoardResponse(h, 10).status == BoardResponseStatus::MissingOrMalformedHeaders);
}

void test_unknown_mode_value_is_malformed(void) {
    BoardHeaders h;
    h.mode = "partial"; h.etag = "\"abc\""; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "1872"; h.h = "1404";
    h.contentLength = "10"; h.favoriteCount = "1"; h.totalPages = "1";

    TEST_ASSERT_TRUE(validateBoardResponse(h, 10).status == BoardResponseStatus::MissingOrMalformedHeaders);
}

void test_non_numeric_width_is_malformed(void) {
    BoardHeaders h;
    h.mode = "full"; h.etag = "\"abc\""; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "abc"; h.h = "1404";
    h.contentLength = "10"; h.favoriteCount = "1"; h.totalPages = "1";

    TEST_ASSERT_TRUE(validateBoardResponse(h, 10).status == BoardResponseStatus::MissingOrMalformedHeaders);
}

void test_favorite_count_out_of_range_is_malformed(void) {
    BoardHeaders h;
    h.mode = "full"; h.etag = "\"abc\""; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "1872"; h.h = "1404";
    h.contentLength = "10"; h.favoriteCount = "4"; h.totalPages = "1"; // spec: max 3

    TEST_ASSERT_TRUE(validateBoardResponse(h, 10).status == BoardResponseStatus::MissingOrMalformedHeaders);
}

void test_content_length_mismatch_is_detected(void) {
    BoardHeaders h;
    h.mode = "full"; h.etag = "\"abc\""; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "1872"; h.h = "1404";
    h.contentLength = "999"; h.favoriteCount = "1"; h.totalPages = "1";

    ParsedBoardResponse r = validateBoardResponse(h, 10);

    TEST_ASSERT_TRUE(r.status == BoardResponseStatus::ContentLengthMismatch);
}

void test_empty_etag_is_malformed(void) {
    BoardHeaders h;
    h.mode = "full"; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "1872"; h.h = "1404";
    h.contentLength = "10"; h.favoriteCount = "1"; h.totalPages = "1";

    TEST_ASSERT_TRUE(validateBoardResponse(h, 10).status == BoardResponseStatus::MissingOrMalformedHeaders);
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_valid_full_frame_headers_parse_ok);
    RUN_TEST(test_valid_patch_headers_parse_ok);
    RUN_TEST(test_missing_mode_is_malformed);
    RUN_TEST(test_unknown_mode_value_is_malformed);
    RUN_TEST(test_non_numeric_width_is_malformed);
    RUN_TEST(test_favorite_count_out_of_range_is_malformed);
    RUN_TEST(test_content_length_mismatch_is_detected);
    RUN_TEST(test_empty_etag_is_malformed);
    return UNITY_END();
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `pio test -e native -f test_board_response` (from `epaper-monitor/`)
Expected: FAIL to compile — `board_response.h` doesn't exist yet.

- [ ] **Step 3: Write `board_response.h`**

```cpp
#pragma once
#include <string>
#include <cstddef>

// Rohe Header-Werte, so wie HTTPClient::header(name) sie liefert (immer als
// String, auch Zahlen) -- string statt Arduino::String, damit diese Datei
// ohne Arduino-Toolchain nativ testbar bleibt (Spec: kein Hardware-Zugriff
// vorhanden, siehe Global Constraints).
struct BoardHeaders {
    std::string mode;           // "X-Board-Mode": "full" | "patch"
    std::string etag;           // "X-Board-ETag"
    std::string generated;      // "X-Board-Generated" (ISO8601)
    std::string x;              // "X-Board-X" (Dezimalstring)
    std::string y;
    std::string w;
    std::string h;
    std::string contentLength;  // "Content-Length"
    std::string favoriteCount;  // "X-Board-Favorite-Count" (0-3)
    std::string totalPages;     // "X-Board-Total-Pages" (>=1)
};

enum class BoardResponseStatus {
    Ok,
    MissingOrMalformedHeaders,
    ContentLengthMismatch,
};

struct ParsedBoardResponse {
    BoardResponseStatus status = BoardResponseStatus::MissingOrMalformedHeaders;
    bool isPatch = false;
    int x = 0;
    int y = 0;
    int w = 0;
    int h = 0;
    int favoriteCount = 0;
    int totalPages = 1;
    std::string etag;
    std::string generated;
};

// Reine Funktion: validiert die vom Server gesendeten X-Board-*-Header
// gegen die tatsaechlich empfangene Body-Laenge (Spec §5/§11 "Antwort
// unlesbar"). Nimmt rohe String-Werte entgegen (kein HTTPClient/Arduino
// noetig), damit sie ohne Hardware testbar ist.
ParsedBoardResponse validateBoardResponse(const BoardHeaders& headers, size_t actualBodyLength);
```

- [ ] **Step 4: Write `board_response.cpp`**

```cpp
#include "board_response.h"
#include <cstdlib>

namespace {

bool parseNonNegativeInt(const std::string& s, int& out, int maxValue) {
    if (s.empty()) return false;
    for (char c : s) {
        if (c < '0' || c > '9') return false;
    }
    long value = std::strtol(s.c_str(), nullptr, 10);
    if (value < 0 || value > maxValue) return false;
    out = (int) value;
    return true;
}

} // namespace

ParsedBoardResponse validateBoardResponse(const BoardHeaders& headers, size_t actualBodyLength) {
    ParsedBoardResponse result;

    if (headers.mode != "full" && headers.mode != "patch") {
        return result;
    }
    if (headers.etag.empty() || headers.generated.empty()) {
        return result;
    }

    int x, y, w, h, contentLength, favoriteCount, totalPages;
    // Grosszuegige Obergrenze fuer x/y/w/h (Panel ist 1872x1404, 100000
    // ist bewusst weit drueber statt exakt 1872/1404 -- die Panelgroesse
    // selbst validiert diese Funktion nicht, nur "ist eine plausible Zahl").
    if (!parseNonNegativeInt(headers.x, x, 100000)
        || !parseNonNegativeInt(headers.y, y, 100000)
        || !parseNonNegativeInt(headers.w, w, 100000)
        || !parseNonNegativeInt(headers.h, h, 100000)
        || !parseNonNegativeInt(headers.contentLength, contentLength, 10000000)
        || !parseNonNegativeInt(headers.favoriteCount, favoriteCount, 3)
        || !parseNonNegativeInt(headers.totalPages, totalPages, 1000)) {
        return result;
    }
    if (totalPages < 1) {
        return result;
    }

    if ((size_t) contentLength != actualBodyLength) {
        result.status = BoardResponseStatus::ContentLengthMismatch;
        return result;
    }

    result.status = BoardResponseStatus::Ok;
    result.isPatch = (headers.mode == "patch");
    result.x = x;
    result.y = y;
    result.w = w;
    result.h = h;
    result.favoriteCount = favoriteCount;
    result.totalPages = totalPages;
    result.etag = headers.etag;
    result.generated = headers.generated;
    return result;
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `pio test -e native -f test_board_response`
Expected: PASS, 8/8 tests green.

- [ ] **Step 6: Commit**

```bash
cd epaper-monitor
git add lib/boardlogic/board_response.h lib/boardlogic/board_response.cpp test/test_board_response/test_board_response.cpp
git commit -m "feat(firmware): add pure Board-Protokoll response-header validation"
```

---

### Task 2: `lib/boardlogic/touch_zone.h/cpp` — pure touch-coordinate mapping

**Files:**
- Create: `epaper-monitor/lib/boardlogic/touch_zone.h`
- Create: `epaper-monitor/lib/boardlogic/touch_zone.cpp`
- Test: `epaper-monitor/test/test_touch_zone/test_touch_zone.cpp` (new)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces (consumed by Task 6): `TouchZone` enum,
  `mapTouchToZone(int x, int y, int favoriteCount, int totalPages): TouchZone`,
  `touchZoneToHeaderValue(TouchZone): const char*` (returns `nullptr` for
  `TouchZone::None`).

- [ ] **Step 1: Write the failing tests**

Create `epaper-monitor/test/test_touch_zone/test_touch_zone.cpp`:

```cpp
#include <unity.h>
#include "touch_zone.h"

// --- Favoriten-Zonen (y in [1320,1394)) ------------------------------------

void test_three_favorites_split_evenly(void) {
    // buttonWidth = (1872-32-32)/3 = 602
    // fav0: [16,618)  fav1: [634,1236)  fav2: [1252,1854)
    TEST_ASSERT_TRUE(mapTouchToZone(300, 1350, 3, 1) == TouchZone::Fav0);
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1350, 3, 1) == TouchZone::Fav1);
    TEST_ASSERT_TRUE(mapTouchToZone(1500, 1350, 3, 1) == TouchZone::Fav2);
}

void test_favorite_zone_boundaries_are_exact(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(16, 1320, 3, 1) == TouchZone::Fav0);   // top-left corner, inclusive
    TEST_ASSERT_TRUE(mapTouchToZone(617, 1393, 3, 1) == TouchZone::Fav0); // bottom-right, still inside
    TEST_ASSERT_TRUE(mapTouchToZone(618, 1350, 3, 1) == TouchZone::None); // gap between fav0/fav1
    TEST_ASSERT_TRUE(mapTouchToZone(634, 1350, 3, 1) == TouchZone::Fav1); // fav1 starts here
    TEST_ASSERT_TRUE(mapTouchToZone(300, 1394, 3, 1) == TouchZone::None); // one px below the row
    TEST_ASSERT_TRUE(mapTouchToZone(300, 1319, 3, 1) == TouchZone::None); // one px above the row
}

void test_single_favorite_spans_almost_the_whole_row(void) {
    // buttonWidth = (1872-32-0)/1 = 1840, fav0: [16,1856)
    TEST_ASSERT_TRUE(mapTouchToZone(16, 1350, 1, 1) == TouchZone::Fav0);
    TEST_ASSERT_TRUE(mapTouchToZone(1800, 1350, 1, 1) == TouchZone::Fav0);
    TEST_ASSERT_TRUE(mapTouchToZone(1860, 1350, 1, 1) == TouchZone::None);
}

void test_two_favorites_split_in_half(void) {
    // buttonWidth = (1872-32-16)/2 = 912, fav0: [16,928) fav1: [944,1856)
    TEST_ASSERT_TRUE(mapTouchToZone(500, 1350, 2, 1) == TouchZone::Fav0);
    TEST_ASSERT_TRUE(mapTouchToZone(1000, 1350, 2, 1) == TouchZone::Fav1);
    TEST_ASSERT_TRUE(mapTouchToZone(935, 1350, 2, 1) == TouchZone::None); // gap
}

void test_zero_favorites_means_no_favorite_zones_at_all(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(300, 1350, 0, 1) == TouchZone::None);
}

// --- Pagination-Zonen (y in [1256,1304)) -----------------------------------

void test_pagination_zones_absent_when_only_one_page(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1280, 3, 1) == TouchZone::None);
}

void test_pagination_pill_at_minimum_width_splits_at_938(void) {
    // totalPages=2: arrowX=880+116=996, pillWidth=max(290,996-822+58)=max(290,232)=290
    // pill: [793,1083), mid=793+145=938
    TEST_ASSERT_TRUE(mapTouchToZone(800, 1280, 3, 2) == TouchZone::PagePrev);
    TEST_ASSERT_TRUE(mapTouchToZone(937, 1280, 3, 2) == TouchZone::PagePrev);
    TEST_ASSERT_TRUE(mapTouchToZone(938, 1280, 3, 2) == TouchZone::PageNext);
    TEST_ASSERT_TRUE(mapTouchToZone(1080, 1280, 3, 2) == TouchZone::PageNext);
    TEST_ASSERT_TRUE(mapTouchToZone(1083, 1280, 3, 2) == TouchZone::None); // past the pill
}

void test_pagination_pill_grows_with_more_pages(void) {
    // totalPages=5: arrowX=880+290=1170, pillWidth=max(290,1170-822+58)=max(290,406)=406
    // pill: [793,1199), mid=793+203=996
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1280, 3, 5) == TouchZone::PagePrev);
    TEST_ASSERT_TRUE(mapTouchToZone(1100, 1280, 3, 5) == TouchZone::PageNext);
    TEST_ASSERT_TRUE(mapTouchToZone(1198, 1280, 3, 5) == TouchZone::PageNext);
    TEST_ASSERT_TRUE(mapTouchToZone(1199, 1280, 3, 5) == TouchZone::None);
}

void test_pagination_row_boundaries_are_exact(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1256, 3, 2) == TouchZone::PagePrev); // top of row
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1303, 3, 2) == TouchZone::PagePrev); // bottom of row
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1304, 3, 2) == TouchZone::None);     // one px below
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1255, 3, 2) == TouchZone::None);     // one px above
}

// --- Zellen ausserhalb beider Reihen ----------------------------------------

void test_touch_in_the_departures_area_maps_to_none(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(500, 700, 3, 2) == TouchZone::None);
}

// --- Header-Wert-Umwandlung -------------------------------------------------

void test_zone_to_header_value(void) {
    TEST_ASSERT_EQUAL_STRING("fav0", touchZoneToHeaderValue(TouchZone::Fav0));
    TEST_ASSERT_EQUAL_STRING("fav1", touchZoneToHeaderValue(TouchZone::Fav1));
    TEST_ASSERT_EQUAL_STRING("fav2", touchZoneToHeaderValue(TouchZone::Fav2));
    TEST_ASSERT_EQUAL_STRING("page_prev", touchZoneToHeaderValue(TouchZone::PagePrev));
    TEST_ASSERT_EQUAL_STRING("page_next", touchZoneToHeaderValue(TouchZone::PageNext));
    TEST_ASSERT_NULL(touchZoneToHeaderValue(TouchZone::None));
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_three_favorites_split_evenly);
    RUN_TEST(test_favorite_zone_boundaries_are_exact);
    RUN_TEST(test_single_favorite_spans_almost_the_whole_row);
    RUN_TEST(test_two_favorites_split_in_half);
    RUN_TEST(test_zero_favorites_means_no_favorite_zones_at_all);
    RUN_TEST(test_pagination_zones_absent_when_only_one_page);
    RUN_TEST(test_pagination_pill_at_minimum_width_splits_at_938);
    RUN_TEST(test_pagination_pill_grows_with_more_pages);
    RUN_TEST(test_pagination_row_boundaries_are_exact);
    RUN_TEST(test_touch_in_the_departures_area_maps_to_none);
    RUN_TEST(test_zone_to_header_value);
    return UNITY_END();
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `pio test -e native -f test_touch_zone`
Expected: FAIL to compile — `touch_zone.h` doesn't exist yet.

- [ ] **Step 3: Write `touch_zone.h`**

```cpp
#pragma once

enum class TouchZone {
    None,
    Fav0,
    Fav1,
    Fav2,
    PagePrev,
    PageNext,
};

// Reine Funktion: bildet einen Touch-Punkt (Panel-Pixelkoordinaten,
// 1872x1404) auf eine Zone ab. favoriteCount (0-3) und totalPages (>=1)
// kommen aus X-Board-Favorite-Count/X-Board-Total-Pages der letzten
// erfolgreichen Antwort (Spec §5) -- ohne Hardware/GT911-Abhaengigkeit
// testbar, s. Global Constraints.
TouchZone mapTouchToZone(int x, int y, int favoriteCount, int totalPages);

// X-Device-Touch-Wert (Spec §5) fuer eine Zone, oder nullptr bei
// TouchZone::None (dann wird der Header gar nicht gesetzt).
const char* touchZoneToHeaderValue(TouchZone zone);
```

- [ ] **Step 4: Write `touch_zone.cpp`**

```cpp
#include "touch_zone.h"

namespace {

const int FAVORITE_ROW_TOP = 1320;
const int FAVORITE_ROW_BOTTOM = 1394; // exklusiv
const int FAVORITE_MARGIN = 16;
const int FAVORITE_GAP = 16;
const int PANEL_WIDTH = 1872;

const int PAGINATION_ROW_TOP = 1256;
const int PAGINATION_ROW_BOTTOM = 1304; // exklusiv
const int PAGINATION_PILL_START_X = 793;
const int PAGINATION_ARROW_BASE_X = 822;
const int PAGINATION_NUMBER_START_X = 880;
const int PAGINATION_SLOT_WIDTH = 58;
const int PAGINATION_MIN_PILL_WIDTH = 290;

TouchZone mapFavoriteTouch(int x, int favoriteCount) {
    if (favoriteCount <= 0) return TouchZone::None;

    int buttonWidth = (PANEL_WIDTH - 2 * FAVORITE_MARGIN - (favoriteCount - 1) * FAVORITE_GAP) / favoriteCount;
    for (int i = 0; i < favoriteCount; i++) {
        int xStart = FAVORITE_MARGIN + i * (buttonWidth + FAVORITE_GAP);
        int xEnd = xStart + buttonWidth;
        if (x >= xStart && x < xEnd) {
            switch (i) {
                case 0: return TouchZone::Fav0;
                case 1: return TouchZone::Fav1;
                case 2: return TouchZone::Fav2;
            }
        }
    }
    return TouchZone::None;
}

TouchZone mapPaginationTouch(int x, int totalPages) {
    if (totalPages <= 1) return TouchZone::None;

    int arrowX = PAGINATION_NUMBER_START_X + totalPages * PAGINATION_SLOT_WIDTH;
    int pillWidth = arrowX - PAGINATION_ARROW_BASE_X + PAGINATION_SLOT_WIDTH;
    if (pillWidth < PAGINATION_MIN_PILL_WIDTH) pillWidth = PAGINATION_MIN_PILL_WIDTH;

    int pillEnd = PAGINATION_PILL_START_X + pillWidth;
    if (x < PAGINATION_PILL_START_X || x >= pillEnd) return TouchZone::None;

    int mid = PAGINATION_PILL_START_X + pillWidth / 2;
    return (x < mid) ? TouchZone::PagePrev : TouchZone::PageNext;
}

} // namespace

TouchZone mapTouchToZone(int x, int y, int favoriteCount, int totalPages) {
    if (y >= FAVORITE_ROW_TOP && y < FAVORITE_ROW_BOTTOM) {
        return mapFavoriteTouch(x, favoriteCount);
    }
    if (y >= PAGINATION_ROW_TOP && y < PAGINATION_ROW_BOTTOM) {
        return mapPaginationTouch(x, totalPages);
    }
    return TouchZone::None;
}

const char* touchZoneToHeaderValue(TouchZone zone) {
    switch (zone) {
        case TouchZone::Fav0: return "fav0";
        case TouchZone::Fav1: return "fav1";
        case TouchZone::Fav2: return "fav2";
        case TouchZone::PagePrev: return "page_prev";
        case TouchZone::PageNext: return "page_next";
        default: return nullptr;
    }
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `pio test -e native -f test_touch_zone`
Expected: PASS, 11/11 tests green.

- [ ] **Step 6: Commit**

```bash
cd epaper-monitor
git add lib/boardlogic/touch_zone.h lib/boardlogic/touch_zone.cpp test/test_touch_zone/test_touch_zone.cpp
git commit -m "feat(firmware): add pure touch-coordinate-to-zone mapping"
```

---

### Task 3: Remove obsolete modules, add committed non-secret config

**Files:**
- Delete: `epaper-monitor/lib/boardlogic/board_model.h`, `board_model.cpp`
- Delete: `epaper-monitor/lib/boardlogic/layout.h`, `layout.cpp`
- Delete: `epaper-monitor/test/test_board_model/test_board_model.cpp` (+ remove now-empty dir)
- Delete: `epaper-monitor/test/test_layout/test_layout.cpp` (+ remove now-empty dir)
- Delete: `epaper-monitor/src/logo.h`, `src/panel_select.h`
- Delete: `epaper-monitor/tools/convert_logo.py`, `tools/test_convert_logo.py`
- Delete: `epaper-monitor/include/config.example.h`
- Create: `epaper-monitor/include/board_config.h`

**Interfaces:**
- Consumes: nothing from other tasks (pure cleanup + one new constants file).
- Produces (consumed by Tasks 4/8): `BOARD_HOST`, `BOARD_PORT`,
  `POLL_INTERVAL_SEC` macros from `board_config.h`.

This task has no test cycle of its own (deletion + a constants-only header)
— verify by confirming the repo state matches the list above and that
`git status` shows exactly these changes, nothing else.

- [ ] **Step 1: Delete the obsolete files**

```bash
cd epaper-monitor
git rm lib/boardlogic/board_model.h lib/boardlogic/board_model.cpp
git rm lib/boardlogic/layout.h lib/boardlogic/layout.cpp
git rm test/test_board_model/test_board_model.cpp
git rm test/test_layout/test_layout.cpp
rmdir test/test_board_model test/test_layout
git rm src/logo.h src/panel_select.h
git rm tools/convert_logo.py tools/test_convert_logo.py
git rm include/config.example.h
```

- [ ] **Step 2: Write `include/board_config.h`**

```cpp
#pragma once

// Nicht geheime Infrastruktur-Konstanten, fuer jedes Geraet gleich --
// bewusst COMMITTED (anders als die alte gitignorete config.h). WLAN und
// API-Token sind KEINE Compile-Zeit-Konstanten mehr, sondern werden per
// WiFiManager-Captive-Portal eingegeben und in NVS-Flash persistiert
// (Preferences-Bibliothek, s. main.cpp) -- damit gibt es ueberhaupt keine
// Geheimnisse mehr, die versehentlich in dieses Repo committed werden
// koennten (Spec §10, Vorfall vom 2026-08-03 mit der alten config.h).

#define BOARD_HOST "wlmonitor.eriks.cloud"
#define BOARD_PORT 443
#define POLL_INTERVAL_SEC 120
```

- [ ] **Step 3: Verify the repo state**

Run: `git status` (from repo root)
Expected: exactly the deletions listed in Step 1, plus
`include/board_config.h` as a new untracked file. No other changes.

- [ ] **Step 4: Commit**

```bash
cd epaper-monitor
git add include/board_config.h
git commit -m "chore(firmware): remove obsolete v1-protocol modules, add committed non-secret config"
```

---

### Task 4: `src/board_client.h/cpp` — HTTPS I/O against the binary protocol

**Files:**
- Modify (full rewrite): `epaper-monitor/src/board_client.h`
- Modify (full rewrite): `epaper-monitor/src/board_client.cpp`

**Interfaces:**
- Consumes: `board_response.h`'s `BoardHeaders`, `ParsedBoardResponse`,
  `BoardResponseStatus`, `validateBoardResponse()` (Task 1);
  `include/board_config.h`'s `BOARD_HOST`/`BOARD_PORT` (Task 3).
- Produces (consumed by Task 8): `BoardFetchOutcome` enum,
  `BoardFetchResult` struct, `fetchBoard(const char* token, const char*
  touchValue, const char* lastEtag, int batteryMv, int rssi, uint32_t
  timeoutMs, BoardFetchResult& out): void`.

**Hardware-bound — cannot be runtime-verified without a real device.**
Compiles against `pio run -e esp32dev`; the actual TLS handshake, header
parsing off a real `HTTPClient`, and binary body read are unverified until
hardware arrives. Say so explicitly in the self-review.

- [ ] **Step 1: Write `board_client.h`**

```cpp
#pragma once
#include <cstdint>
#include <vector>
#include "board_response.h"

enum class BoardFetchOutcome {
    Success,
    NetworkUnavailable,  // TLS/connect failure, timeout, or HTTP 503
    Unauthorized,        // HTTP 401
    UnreadableResponse,  // headers missing/malformed or Content-Length mismatch
};

struct BoardFetchResult {
    BoardFetchOutcome outcome = BoardFetchOutcome::NetworkUnavailable;
    ParsedBoardResponse parsed;   // only meaningful when outcome == Success
    std::vector<uint8_t> body;    // raw packed pixel bytes, only when outcome == Success
};

// Fuehrt GET https://BOARD_HOST:BOARD_PORT/board.php aus (board_config.h),
// mit Authorization: Bearer <token>, X-Device-Battery-mV, X-Device-RSSI,
// optional X-Device-Touch (touchValue == nullptr -> Header weggelassen)
// und optional If-None-Match (lastEtag == nullptr oder leer -> weggelassen,
// Spec §5). timeoutMs begrenzt Verbindungsaufbau UND Antwortwartezeit.
void fetchBoard(const char* token, const char* touchValue, const char* lastEtag,
                 int batteryMv, int rssi, uint32_t timeoutMs, BoardFetchResult& out);
```

- [ ] **Step 2: Write `board_client.cpp`**

```cpp
#include "board_client.h"
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <esp_crt_bundle.h>
#include "board_config.h"

namespace {

const char* HEADER_NAMES[] = {
    "X-Board-Mode", "X-Board-ETag", "X-Board-Generated",
    "X-Board-X", "X-Board-Y", "X-Board-W", "X-Board-H",
    "X-Board-Favorite-Count", "X-Board-Total-Pages", "Content-Length",
};
const size_t HEADER_COUNT = sizeof(HEADER_NAMES) / sizeof(HEADER_NAMES[0]);

} // namespace

void fetchBoard(const char* token, const char* touchValue, const char* lastEtag,
                 int batteryMv, int rssi, uint32_t timeoutMs, BoardFetchResult& out) {
    out = BoardFetchResult{};

    WiFiClientSecure client;
    // arduino-esp32 buendelt seit Core 2.x ein Mozilla-Root-CA-Bundle
    // (CONFIG_MBEDTLS_CERTIFICATE_BUNDLE, standardmaessig aktiv) --
    // WiFiClientSecure::setCACertBundle() bindet es ein. UNVERIFIZIERT ohne
    // installierte Toolchain: das exakte Symbol/die Methode kann sich
    // zwischen Core-Versionen unterscheiden (manche brauchen diesen Aufruf
    // gar nicht, wenn das Bundle bereits global aktiv ist) -- beim ersten
    // echten Build pruefen und ggf. korrigieren. NIEMALS durch
    // client.setInsecure() ersetzen (der Bearer-Token liefe sonst
    // ungeprueft ueber eine potenziell falsche Gegenstelle -- Grund, warum
    // dieser Plan ueberhaupt HTTPS statt Klartext-HTTP gewaehlt hat).
    extern const uint8_t rootca_crt_bundle_start[] asm("_binary_x509_crt_bundle_start");
    client.setCACertBundle(rootca_crt_bundle_start);
    HTTPClient http;
    http.setConnectTimeout(timeoutMs);
    http.setTimeout(timeoutMs);
    http.collectHeaders(HEADER_NAMES, HEADER_COUNT);

    String url = String("https://") + BOARD_HOST + ":" + BOARD_PORT + "/board.php";
    if (!http.begin(client, url)) {
        out.outcome = BoardFetchOutcome::NetworkUnavailable;
        return;
    }

    http.addHeader("Authorization", String("Bearer ") + token);
    http.addHeader("X-Device-Battery-mV", String(batteryMv));
    http.addHeader("X-Device-RSSI", String(rssi));
    if (touchValue != nullptr) {
        http.addHeader("X-Device-Touch", touchValue);
    }
    if (lastEtag != nullptr && lastEtag[0] != '\0') {
        http.addHeader("If-None-Match", lastEtag);
    }

    int status = http.GET();
    if (status <= 0) {
        http.end();
        out.outcome = BoardFetchOutcome::NetworkUnavailable;
        return;
    }
    if (status == 401) {
        http.end();
        out.outcome = BoardFetchOutcome::Unauthorized;
        return;
    }
    if (status != 200) {
        // 503 und alles sonstige Unerwartete: wie ein Verbindungsausfall
        // behandeln (Spec §11 "wie WLAN-Ausfall").
        http.end();
        out.outcome = BoardFetchOutcome::NetworkUnavailable;
        return;
    }

    BoardHeaders headers;
    headers.mode           = http.header("X-Board-Mode").c_str();
    headers.etag            = http.header("X-Board-ETag").c_str();
    headers.generated       = http.header("X-Board-Generated").c_str();
    headers.x               = http.header("X-Board-X").c_str();
    headers.y               = http.header("X-Board-Y").c_str();
    headers.w               = http.header("X-Board-W").c_str();
    headers.h               = http.header("X-Board-H").c_str();
    headers.favoriteCount    = http.header("X-Board-Favorite-Count").c_str();
    headers.totalPages       = http.header("X-Board-Total-Pages").c_str();
    headers.contentLength    = http.header("Content-Length").c_str();

    WiFiClient* stream = http.getStreamPtr();
    int contentLength = http.getSize(); // -1, falls unbekannt -- validateBoardResponse() prueft ohnehin gegen den echten gelesenen Byte-Count
    out.body.reserve(contentLength > 0 ? (size_t) contentLength : 4096);

    uint8_t buf[512];
    while (http.connected() && (contentLength > 0 || contentLength == -1)) {
        size_t avail = stream->available();
        if (avail == 0) {
            if (!stream->connected()) break;
            delay(1);
            continue;
        }
        size_t toRead = avail > sizeof(buf) ? sizeof(buf) : avail;
        int got = stream->readBytes(buf, toRead);
        if (got <= 0) break;
        out.body.insert(out.body.end(), buf, buf + got);
        if (contentLength > 0) contentLength -= got;
    }
    http.end();

    out.parsed = validateBoardResponse(headers, out.body.size());
    if (out.parsed.status == BoardResponseStatus::Ok) {
        out.outcome = BoardFetchOutcome::Success;
    } else {
        out.outcome = BoardFetchOutcome::UnreadableResponse;
    }
}
```

- [ ] **Step 3: Confirm it compiles**

Run: `pio run -e esp32dev` (from `epaper-monitor/` — this will fail until
Task 8's `platformio.ini` update adds the XIAO_ESP32S3 board target and
`esp_crt_bundle.h`-providing core version; if this task runs before Task 8,
note the compile step as deferred to Task 8's own compile check instead of
blocking here).

- [ ] **Step 4: Self-review**

Confirm in writing (in the commit message or a comment) that: this code
compiles conceptually against the documented `HTTPClient`/`WiFiClientSecure`
API shapes, but the actual TLS handshake against `wlmonitor.eriks.cloud`,
the real header names/casing returned by the server, and the streaming
body read have **not** been run against real hardware or a real network.
Specifically flag the `rootca_crt_bundle_start` linker-symbol call as the
least certain line in this file — it's the standard documented
arduino-esp32 pattern as far as could be confirmed without a working
toolchain, but the exact symbol name is known to drift between core
versions; if it fails to link, check the installed arduino-esp32 core's
own examples for the current correct call before improvising a fix.

- [ ] **Step 5: Commit**

```bash
cd epaper-monitor
git add src/board_client.h src/board_client.cpp
git commit -m "feat(firmware): rewrite board_client for HTTPS + binary Board-Protokoll (unverified, no hardware yet)"
```

---

### Task 5: `src/display.h/cpp` — GxEPD2 driver, blit, sleep icon, fallback banner

**Files:**
- Modify (full rewrite): `epaper-monitor/src/display.h`
- Modify (full rewrite): `epaper-monitor/src/display.cpp`

**Interfaces:**
- Consumes: `error_state.h`'s `ErrorBanner` enum (unchanged, existing file).
- Produces (consumed by Task 8): `initDisplay(): void`,
  `applyFullFrame(const uint8_t* packed, int w, int h): void`,
  `applyPatch(const uint8_t* packed, int x, int y, int w, int h): void`,
  `showErrorBanner(ErrorBanner banner, const char* sinceTime): void`,
  `markSleepIcon(): void`, `sleepPanel(): void`.

**Hardware-bound — cannot be runtime-verified without a real device.**

- [ ] **Step 1: Write `display.h`**

```cpp
#pragma once
#include <cstdint>
#include "error_state.h"

void initDisplay();

// Vollbild: packed ist 1bpp MSB-first, Zeilenbreite auf Vielfaches von 8
// aufgerundet, 1=weiss/0=schwarz (Spec §6), w/h immer 1872x1404 bei Vollbild.
void applyFullFrame(const uint8_t* packed, int w, int h);

// Patch: packed deckt NUR das Rechteck (x,y,w,h) ab, gleiche Bit-Konvention.
void applyPatch(const uint8_t* packed, int x, int y, int w, int h);

// Lokaler Fallback-Banner (Spec §11) -- ueberlagert die Kopfzeile, kein
// eigenes Bitmap-Font-Asset, nutzt GxEPD2s eingebaute Standardschrift.
// sinceTime: "HH:MM"-String fuer den Offline-Banner, ignoriert beim
// TokenInvalid-Banner (kein Zeitstempel im Text, Spec §11 "Token ungueltig").
void showErrorBanner(ErrorBanner banner, const char* sinceTime);

// Ueberschreibt das WLAN-Balken-Rechteck der Kopfzeile mit einem "zzz"
// (Spec §9/§10/§11) -- wird unmittelbar vor esp_deep_sleep_start() gerufen.
void markSleepIcon();

// Versetzt das Panel in den stromsparenden Ruhezustand (unabhaengig vom
// ESP32-Tiefschlaf -- das Panel selbst hat einen eigenen deep-power-down).
void sleepPanel();
```

- [ ] **Step 2: Write `display.cpp`**

> **Update 2026-08-17 (post-execution correction):** the class name and API
> shape below were originally guessed from a Seeed wiki example
> (`GxEPD2_ED103TC2_1872x1404`, wrapped via `GxEPD2_BW<Panel, Panel::HEIGHT>`
> + `selectSPI()` + `setRotation(1)`) and turned out to be **wrong** — that
> class does not exist in the actual `zinggjm/GxEPD2@^1.5.3` library already
> in `lib_deps`. This was caught during real execution by reading the
> installed library source directly
> (`.pio/libdeps/esp32dev/GxEPD2/src/it8951/GxEPD2_it103_1872x1404.h/.cpp`,
> `GxEPD2_EPD.h`, `GxEPD2_GFX.h`) rather than guessing further. The block
> below is the corrected, verified-against-real-source version — task
> briefs extracted from this plan for Task 6 onward should use this code,
> not the original guess. Key corrections, each independently re-verified
> by the task reviewer (opus) via its own read of the same library source:
> the real class is `GxEPD2_it103_1872x1404` (IT8951, natively 1872×1404 —
> no rotation needed); `GxEPD2_GFX` is pure-abstract in this library
> version, so `GxEPD2_BW<GxEPD2_it103_1872x1404, 128>` is the real
> text-drawing vehicle; the raw pixel blit bypasses GFX entirely via
> `epd.epd2.drawImage()`; `invert=false` was derived algebraically from
> `_send8pixel()`/`writeImage()`, not guessed, and is provably correct for
> the 1=white/0=black protocol convention; this driver hardcodes the global
> `SPI` object and ignores `selectSPI()` entirely, so the original
> `hspi(HSPI)` pattern would have been silently ineffective; the DC pin is
> genuinely absent from the project's own pin reference and is set to `-1`
> (GxEPD2's "not wired" convention) with source-level evidence (`_dc` is
> never referenced by this driver — the IT8951 protocol multiplexes
> command/data over SPI preamble words) but this specific claim is **still
> not 100% hardware-confirmable** without a real device or schematic.

```cpp
#include "display.h"
#include <it8951/GxEPD2_it103_1872x1404.h>
#include <GxEPD2_BW.h>
#include <Fonts/FreeSansBold9pt7b.h>

// Panel-/SPI-Pins aus den Global Constraints (Seeed-Wiki, unverifiziert
// ohne Hardware). GxEPD2_it103_1872x1404 (IT8951-Controller, 1872x1404) ist
// die reale, in der installierten GxEPD2@1.5.3 vorhandene Klasse -- ihr
// Header-Kommentar nennt sie fuer "ES103TC1 10.3\" e-paper panel"; der
// Panelname weicht von der Spec ("ED103TC2") geringfuegig ab, aber
// Controller (IT8951) + Aufloesung (1872x1404) + Groesse (10.3") passen;
// naeher kommt man ohne Hardware nicht heran.
#define EPD_SCK_PIN     7
#define EPD_MISO_PIN    8
#define EPD_MOSI_PIN    9
#define EPD_CS_PIN      10
#define EPD_RES_PIN     12
#define EPD_BUSY_PIN    13
#define EPD_TFT_ENABLE  11
#define EPD_ITE_ENABLE  21

// *** UNGEKLAERTE LUECKE: DC-Pin -- vor dem ersten Flash pruefen! ***
// GxEPD2_EPD::GxEPD2_EPD(cs, dc, rst, busy, ...) verlangt einen DC-Pin als
// 2. Konstruktorargument. Die Seeed-Wiki-Pinliste (Quelle der Konstanten
// oben) fuehrt aber KEINEN DC-Pin fuer die E1003-ePaper-Schnittstelle auf.
//
// -1 ist GxEPD2s eigene, in der Bibliothek durchgaengig verwendete
// Konvention fuer "Pin nicht vorhanden/nicht verdrahtet" (GxEPD2_EPD.cpp
// fasst cs/dc/rst/busy jeweils nur mit "if (_pin >= 0) {...}" an -- bei -1
// passiert schlicht nichts). Stuetzender Befund aus GxEPD2_it103_1872x1404.cpp:
// die Membervariable _dc wird dort in KEINER einzigen Methode referenziert
// -- das IT8951-Protokoll unterscheidet Kommando/Daten ueber 16-Bit-
// Praeambel-Worte im SPI-Datenstrom selbst, nicht ueber eine eigene
// DC-Leitung. Starkes Indiz, dass -1 der tatsaechlich korrekte Wert ist --
// aber TROTZDEM vor dem ersten Flash gegen ein echtes E1003-Schaltplan
// oder Seeeds eigene Beispiel-Firmware gegenchecken.
#define EPD_DC_PIN      -1  // UNBEKANNT/UNBESTAETIGT -- s. Kommentar oben

// WLAN-Balken-Rechteck der Kopfzeile (Spec §9: translate(1665,46), 3 Balken).
static const int WIFI_ICON_X = 1665;
static const int WIFI_ICON_Y = 46;
static const int WIFI_ICON_W = 54;
static const int WIFI_ICON_H = 28;

// Seitenhoehe fuer den GxEPD2_BW-Pufferpfad unten (nur fuer Text). 128
// Zeilen reichen locker fuer beide Rechtecke (Banner 70px, Sleep-Icon 28px
// hoch) und halten den RAM-Puffer klein: (1872/8)*128 Byte = 29952 Byte
// statt >300 KB bei voller Panelhoehe.
static const uint16_t TEXT_PAGE_HEIGHT = 128;

// GxEPD2_GFX ist in der installierten GxEPD2@1.5.3 eine rein abstrakte
// Schnittstelle (nur "= 0"-virtuelle Methoden) -- nicht instanziierbar, und
// es gibt in dieser Version keine konkrete GxEPD2_GFX-Unterklasse fuer
// IT8951-Panels (GxEPD2_BW/_3C/_4C/_7C binden per __has_include nur
// Klassen aus epd/ und gdey/ ein, nicht it8951/). Der reale, kompilierbare
// Ersatz mit identischer Text-API (fillScreen/setFont/setCursor/print/
// setPartialWindow/firstPage/nextPage) ist das GxEPD2_BW<...>-Template
// selbst -- bindet rein strukturell, kein Bezug zur epd/-Vorauswahl.
//
// Es existiert genau EIN Panel-Objekt (epd.epd2) -- fuer den reinen
// Pixel-Blit (applyFullFrame/applyPatch) wird direkt darauf zugegriffen,
// ganz ohne die GFX-Zwischenschicht. Fuer die zwei Text-Funktionen wird
// die GFX-Huelle (epd selbst) verwendet. Ein zweites, eigenstaendiges
// Panel-Objekt zu erzeugen waere falsch, weil dann zwei getrennte
// GxEPD2_EPD-Instanzen um dieselbe Hardware konkurrieren wuerden.
static GxEPD2_BW<GxEPD2_it103_1872x1404, TEXT_PAGE_HEIGHT> epd(
    GxEPD2_it103_1872x1404(EPD_CS_PIN, EPD_DC_PIN, EPD_RES_PIN, EPD_BUSY_PIN));

void initDisplay() {
    pinMode(EPD_TFT_ENABLE, OUTPUT);
    digitalWrite(EPD_TFT_ENABLE, HIGH);
    pinMode(EPD_ITE_ENABLE, OUTPUT);
    digitalWrite(EPD_ITE_ENABLE, HIGH);

    // GxEPD2_it103_1872x1404 spricht das SPI-Peripheriegeraet ausschliesslich
    // ueber das globale `SPI`-Objekt an -- GxEPD2_EPD::selectSPI()/_pSPIx
    // wird von dieser Treiberklasse NIE gelesen (anders als bei den meisten
    // anderen GxEPD2-Panelklassen). Die E1003-eigenen SPI-Pins muessen
    // deshalb direkt auf dem globalen SPI-Objekt gebunden werden, bevor
    // epd.init() laeuft (das ruft intern nur noch SPI.begin() ohne
    // Parameter -- auf dem ESP32 ein No-Op, wenn der Bus schon mit
    // expliziten Pins gestartet wurde). ss=-1, weil GxEPD2 den CS-Pin
    // selbst per digitalWrite() manuell steuert.
    SPI.begin(EPD_SCK_PIN, EPD_MISO_PIN, EPD_MOSI_PIN, -1);

    epd.init(0, true, 20, false);
    // Kein setRotation() noetig: GxEPD2_it103_1872x1404 ist bereits nativ
    // WIDTH=1872 x HEIGHT=1404.
}

void applyFullFrame(const uint8_t* packed, int w, int h) {
    // Direkter Pass-Through ueber das rohe Panel-Objekt (epd.epd2), keine
    // GFX-Zwischenschicht -- writeImage()/drawImage() erwarten exakt das
    // Protokollformat (1bpp MSB-first, Zeilenbreite auf 8 aufgerundet).
    //
    // invert-Wert per Quellcode-Analyse hergeleitet (nicht geraten): in
    // GxEPD2_it103_1872x1404::writeImage() gilt
    //   data = bitmap_byte; if (invert) data = ~data; _send8pixel(~data);
    // und in _send8pixel() wird pro gesetztem Bit (bit==1) ein 0x00-Byte
    // (schwarz) ans Panel gesendet, pro geloeschtem Bit (bit==0) ein
    // 0xFF-Byte (weiss). Bei invert=false ist das an _send8pixel
    // uebergebene Byte ~bitmap_byte, d.h. bitmap_byte-Bit 0 -> gesendet 1 ->
    // schwarz, bitmap_byte-Bit 1 -> gesendet 0 -> weiss. Das entspricht
    // exakt der Protokoll-Konvention (Spec §6): 1=weiss, 0=schwarz.
    // invert=false ist also nachweislich richtig, kein Ratewert.
    epd.epd2.drawImage(packed, 0, 0, w, h, /*invert=*/false);
}

void applyPatch(const uint8_t* packed, int x, int y, int w, int h) {
    // Gleiche Bit-Konvention/Herleitung wie applyFullFrame.
    epd.epd2.drawImage(packed, x, y, w, h, /*invert=*/false);
}

void showErrorBanner(ErrorBanner banner, const char* sinceTime) {
    if (banner == ErrorBanner::None) return;

    char text[64];
    if (banner == ErrorBanner::Offline) {
        snprintf(text, sizeof(text), "offline seit %s", sinceTime);
    } else {
        snprintf(text, sizeof(text), "Token ungueltig");
    }

    // Kleines Banner-Rechteck oben links in der Kopfzeile, ueberlagert den
    // Server-Renderzeit-Text nicht (der sitzt zentriert bei x=936).
    const int bannerX = 16, bannerY = 10, bannerW = 700, bannerH = 70;
    epd.setPartialWindow(bannerX, bannerY, bannerW, bannerH);
    epd.firstPage();
    do {
        epd.fillRect(bannerX, bannerY, bannerW, bannerH, GxEPD_WHITE);
        epd.setFont(&FreeSansBold9pt7b);
        epd.setTextColor(GxEPD_BLACK);
        epd.setCursor(bannerX + 8, bannerY + bannerH - 20);
        epd.print(text);
    } while (epd.nextPage());
}

void markSleepIcon() {
    epd.setPartialWindow(WIFI_ICON_X, WIFI_ICON_Y, WIFI_ICON_W, WIFI_ICON_H);
    epd.firstPage();
    do {
        epd.fillRect(WIFI_ICON_X, WIFI_ICON_Y, WIFI_ICON_W, WIFI_ICON_H, GxEPD_WHITE);
        epd.setFont(&FreeSansBold9pt7b);
        epd.setTextColor(GxEPD_BLACK);
        epd.setCursor(WIFI_ICON_X, WIFI_ICON_Y + WIFI_ICON_H - 6);
        epd.print("zzz");
    } while (epd.nextPage());
}

void sleepPanel() {
    // Standby, nicht echtes Deep-Sleep: GxEPD2_it103_1872x1404::hibernate()
    // ruft nur _PowerOff() -- der IT8951-eigene Tiefschlaf ist in der
    // Bibliothek bewusst deaktiviert (Bibliothekskommentar: senkt den
    // Verbrauch nicht, braucht sogar mehr Strom als Standby).
    epd.epd2.hibernate();
}
```

- [ ] **Step 2: Confirm it compiles**

Run: `pio run -e esp32dev` (from `epaper-monitor/`). `display.cpp.o` should
compile cleanly against the already-installed `zinggjm/GxEPD2@1.5.3` (it's
already in `lib_deps` from before this plan started, no Task 8 dependency
needed for this specific file) — confirmed working during real execution.
The overall build will still fail on unrelated missing headers
(`board_model.h` from `main.cpp`/`display.h`'s old callers) until Tasks 8/9
run; that failure is expected and not this task's concern.

- [ ] **Step 3: Self-review**

Confirm: the class name, API shape, `invert` value, and SPI-wiring pattern
above were all independently verified against the real installed library
source during this plan's actual execution (not re-derive them from
scratch) — if implementing this plan fresh in a different environment where
the installed GxEPD2 version differs, re-verify against that environment's
actual headers rather than assuming this exact code is still correct.

- [ ] **Step 4: Commit**

```bash
cd epaper-monitor
git add src/display.h src/display.cpp
git commit -m "feat(firmware): rewrite display driver for GxEPD2/E1003 + sleep icon (verified against installed library source)"
```

---

### Task 6: `src/touch.h/cpp` — GT911 I2C driver

**Files:**
- Create: `epaper-monitor/src/touch.h`
- Create: `epaper-monitor/src/touch.cpp`

**Interfaces:**
- Consumes: `touch_zone.h`'s `TouchZone`, `mapTouchToZone()`,
  `touchZoneToHeaderValue()` (Task 2).
- Produces (consumed by Task 8): `initTouch(): bool` (false = no GT911
  found, non-fatal), `pollTouch(int favoriteCount, int totalPages): TouchZone`.

**Hardware-bound — cannot be runtime-verified without a real device.** The
exact GT911 register protocol below is transcribed from example-sketch
snippets in the memory reference, not from a verified public library API —
treat register addresses/sequencing as the best available starting point,
not as confirmed-correct.

- [ ] **Step 1: Write `touch.h`**

```cpp
#pragma once
#include "touch_zone.h"

// Initialisiert den GT911-Touch-Controller (I2C, Reset ueber GPIO48).
// Gibt false zurueck, wenn kein Controller an einer der beiden bekannten
// Adressen antwortet -- das ist NICHT fatal, die Firmware laeuft ohne
// Touch weiter (physische Tasten bleiben als Fallback, s. buttons.h).
bool initTouch();

// Fragt einen aktuell anliegenden Touch-Punkt ab (nicht blockierend -- gibt
// sofort TouchZone::None zurueck, wenn gerade nichts beruehrt wird) und
// bildet ihn ueber favoriteCount/totalPages (aus der letzten Antwort) auf
// eine Zone ab.
TouchZone pollTouch(int favoriteCount, int totalPages);
```

- [ ] **Step 2: Write `touch.cpp`**

```cpp
#include "touch.h"
#include <Wire.h>

#define TOUCH_SDA_PIN   19
#define TOUCH_SCL_PIN   20
#define TOUCH_INT_PIN   2
#define TOUCH_RESET_PIN 48

#define GT911_ADDR_1 0x5D
#define GT911_ADDR_2 0x14
#define GT911_REG_STATUS 0x814E
#define GT911_REG_POINT0 0x814F
#define GT911_REG_COMMAND 0x8040

static uint8_t s_touchAddr = 0;

static void i2cWriteReg16(uint8_t addr, uint16_t reg, uint8_t value) {
    Wire.beginTransmission(addr);
    Wire.write((uint8_t) (reg >> 8));
    Wire.write((uint8_t) (reg & 0xFF));
    Wire.write(value);
    Wire.endTransmission();
}

static bool i2cReadReg16(uint8_t addr, uint16_t reg, uint8_t* buf, size_t len) {
    Wire.beginTransmission(addr);
    Wire.write((uint8_t) (reg >> 8));
    Wire.write((uint8_t) (reg & 0xFF));
    if (Wire.endTransmission(false) != 0) return false;
    if (Wire.requestFrom((int) addr, (int) len) != (int) len) return false;
    for (size_t i = 0; i < len; i++) buf[i] = Wire.read();
    return true;
}

static bool probeGt911(uint8_t addr) {
    uint8_t status;
    return i2cReadReg16(addr, GT911_REG_STATUS, &status, 1);
}

static void resetTouchController() {
    pinMode(TOUCH_RESET_PIN, OUTPUT);
    digitalWrite(TOUCH_RESET_PIN, LOW);
    delay(10);
    digitalWrite(TOUCH_RESET_PIN, HIGH);
    delay(50);
}

bool initTouch() {
    Wire.begin(TOUCH_SDA_PIN, TOUCH_SCL_PIN);
    pinMode(TOUCH_INT_PIN, INPUT);
    resetTouchController();

    if (probeGt911(GT911_ADDR_1)) {
        s_touchAddr = GT911_ADDR_1;
    } else if (probeGt911(GT911_ADDR_2)) {
        s_touchAddr = GT911_ADDR_2;
    } else {
        s_touchAddr = 0;
        return false;
    }

    i2cWriteReg16(s_touchAddr, GT911_REG_COMMAND, 0x00);
    return true;
}

TouchZone pollTouch(int favoriteCount, int totalPages) {
    if (s_touchAddr == 0) return TouchZone::None;

    uint8_t status;
    if (!i2cReadReg16(s_touchAddr, GT911_REG_STATUS, &status, 1)) {
        return TouchZone::None;
    }
    uint8_t touchCount = status & 0x0F;
    bool bufferReady = (status & 0x80) != 0;
    if (!bufferReady || touchCount == 0) {
        return TouchZone::None;
    }

    uint8_t point[4]; // x_low, x_high, y_low, y_high (erste 4 Byte des Punkt-0-Datensatzes)
    if (!i2cReadReg16(s_touchAddr, GT911_REG_POINT0, point, sizeof(point))) {
        i2cWriteReg16(s_touchAddr, GT911_REG_STATUS, 0x00); // Statusregister quittieren
        return TouchZone::None;
    }
    i2cWriteReg16(s_touchAddr, GT911_REG_STATUS, 0x00);

    int rawX = point[0] | (point[1] << 8);
    int rawY = point[2] | (point[3] << 8);
    // GT911 liefert Rohkoordinaten im nativen Panel-Koordinatensystem
    // (1404x1872 Hochformat); das Display laeuft um 90 Grad gedreht
    // (Spec §3) -- mapTouchToZone() erwartet Querformat-Koordinaten
    // (1872x1404). Rotation unverifiziert ohne Hardware: exaktes
    // Vorzeichen/Achsen-Mapping (rawY->x oder PANEL_HEIGHT-rawY->x usw.)
    // muss am echten Geraet kalibriert werden.
    int x = rawY;
    int y = 1404 - rawX;

    return mapTouchToZone(x, y, favoriteCount, totalPages);
}
```

- [ ] **Step 2: Confirm it compiles**

Run: `pio run -e esp32dev` (after Task 8's lib_deps are in place).

- [ ] **Step 3: Self-review**

Note explicitly: the GT911 register addresses/read sequence are transcribed
from a Seeed wiki example, not verified against a public library header.
The rotation mapping (`rawX`/`rawY` → panel `x`/`y`) is a best guess and
**will very likely need correction against real touch input** — this is
the single highest-risk unverified piece of this whole plan.

- [ ] **Step 4: Commit**

```bash
cd epaper-monitor
git add src/touch.h src/touch.cpp
git commit -m "feat(firmware): add GT911 touch driver (unverified, no hardware yet)"
```

---

### Task 7: `src/buttons.h/cpp` + `src/battery.h/cpp` — GPIO/ADC reads

**Files:**
- Create: `epaper-monitor/src/buttons.h`
- Create: `epaper-monitor/src/buttons.cpp`
- Create: `epaper-monitor/src/battery.h`
- Create: `epaper-monitor/src/battery.cpp`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces (consumed by Task 8): `readPageButtons(): const char*` (returns
  `"page_prev"`, `"page_next"`, or `nullptr`), `isWakeButtonHeld(uint32_t
  ms): bool`, `readBatteryMillivolts(): int`.

**Hardware-bound — cannot be runtime-verified without a real device.**

- [ ] **Step 1: Write `buttons.h`**

```cpp
#pragma once
#include <cstdint>

// Liest die beiden Seiten-Navigationstasten (aktiv-low, ~50ms entprellt).
// Sekundaerer, redundanter Eingabeweg zu Touch (Spec §10) -- gibt
// "page_prev"/"page_next" oder nullptr (keine Taste gedrueckt) zurueck.
const char* readPageButtons();

// Prueft, ob die gruene Wake-/Refresh-Taste (GPIO3/KEY0) beim Aufruf
// mindestens holdMs lang gehalten wird (blockierend) -- fuer die
// Neu-Provisionierung ohne Reflash (Spec §10).
bool isWakeButtonHeld(uint32_t holdMs);
```

- [ ] **Step 2: Write `buttons.cpp`**

```cpp
#include "buttons.h"
#include <Arduino.h>

#define PIN_KEY0 3  // Wake/Refresh
#define PIN_KEY1 4  // page_prev (Rolle unverifiziert, s. Global Constraints)
#define PIN_KEY2 5  // page_next (Rolle unverifiziert, s. Global Constraints)

static const uint32_t DEBOUNCE_MS = 50;

const char* readPageButtons() {
    pinMode(PIN_KEY1, INPUT_PULLUP);
    pinMode(PIN_KEY2, INPUT_PULLUP);

    if (digitalRead(PIN_KEY1) == LOW) {
        delay(DEBOUNCE_MS);
        if (digitalRead(PIN_KEY1) == LOW) return "page_prev";
    }
    if (digitalRead(PIN_KEY2) == LOW) {
        delay(DEBOUNCE_MS);
        if (digitalRead(PIN_KEY2) == LOW) return "page_next";
    }
    return nullptr;
}

bool isWakeButtonHeld(uint32_t holdMs) {
    pinMode(PIN_KEY0, INPUT_PULLUP);
    if (digitalRead(PIN_KEY0) != LOW) return false;

    uint32_t start = millis();
    while (digitalRead(PIN_KEY0) == LOW) {
        if (millis() - start >= holdMs) return true;
        delay(20);
    }
    return false;
}
```

- [ ] **Step 3: Write `battery.h`**

```cpp
#pragma once

// Liest den rohen Akku-Millivolt-Wert (Spannungsteiler ueber ADC GPIO1,
// Enable-Pin GPIO40 -- E1003-spezifisch). Der Server normalisiert diesen
// Rohwert selbst zu Prozent (board_battery_percent_from_mv(), inc/board.php,
// linear 3300mV=0%..4200mV=100%) -- die Firmware schickt einfach den
// Rohwert, keine eigene Umrechnung.
int readBatteryMillivolts();
```

- [ ] **Step 4: Write `battery.cpp`**

```cpp
#include "battery.h"
#include <Arduino.h>

#define BATTERY_ADC_PIN     1
#define BATTERY_ENABLE_PIN  40

int readBatteryMillivolts() {
    pinMode(BATTERY_ENABLE_PIN, OUTPUT);
    digitalWrite(BATTERY_ENABLE_PIN, HIGH);
    delay(5); // Spannungsteiler einschwingen lassen

    analogReadResolution(12);
    analogSetPinAttenuation(BATTERY_ADC_PIN, ADC_11db);
    int raw = analogReadMilliVolts(BATTERY_ADC_PIN);

    digitalWrite(BATTERY_ENABLE_PIN, LOW); // Spannungsteiler-Stromverbrauch nur waehrend der Messung

    // UNVERIFIZIERT (s. Memory reference_reterminal_e1003_arduino): ob der
    // dokumentierte *2-Spannungsteiler-Faktor schon in
    // analogReadMilliVolts() steckt oder hier noch angewendet werden muss.
    // Vorlaeufig OHNE Verdopplung -- am echten Geraet gegen ein Multimeter
    // kalibrieren, dann diese Zeile korrigieren, falls noetig.
    return raw;
}
```

- [ ] **Step 5: Confirm it compiles**

Run: `pio run -e esp32dev` (after Task 8's lib_deps are in place).

- [ ] **Step 6: Self-review**

Note explicitly: KEY1/KEY2's actual page_prev/page_next roles on E1003 are
unconfirmed (memory reference flags this). The battery `*2` factor
uncertainty is called out directly in the code comment rather than guessed
silently — both need real-hardware calibration before this is trusted.

- [ ] **Step 7: Commit**

```bash
cd epaper-monitor
git add src/buttons.h src/buttons.cpp src/battery.h src/battery.cpp
git commit -m "feat(firmware): add button and battery GPIO/ADC readers (unverified, no hardware yet)"
```

---

### Task 8: `src/main.cpp` rewrite + `platformio.ini` update

**Files:**
- Modify (full rewrite): `epaper-monitor/src/main.cpp`
- Modify: `epaper-monitor/platformio.ini`

**Interfaces:**
- Consumes: everything from Tasks 1-7 (`board_client.h`, `display.h`,
  `touch.h`, `buttons.h`, `battery.h`, `error_state.h` [unchanged existing
  file], `board_config.h`, `touch_zone.h`).
- Produces: the firmware's `setup()`/`loop()` entry points — nothing else
  depends on this file.

**Hardware-bound — cannot be runtime-verified without a real device.** This
is the integration point for every other task in this plan; a mistake here
won't show up as a compile error in an already-tested module, only as
wrong end-to-end behavior once hardware exists.

- [ ] **Step 1: Update `platformio.ini`**

> **Update 2026-08-17:** originally this step pointed `lib_deps` at
> `https://github.com/Seeed-Projects/Seeed_GxEPD2.git` (an untested fork
> URL). Task 5's real execution discovered that the mainline, registry-
> published `zinggjm/GxEPD2@^1.5.3` — already what this project's OLD
> firmware used, already proven to install cleanly — has a real, verified
> `GxEPD2_it103_1872x1404` class matching the E1003's IT8951/1872×1404
> panel exactly (see Task 5). No fork needed; the config below now keeps
> the already-working `lib_deps` entry unchanged rather than swapping to
> an unverified alternative.

Replace the entire file content:

```ini
; PlatformIO-Projekt fuer den E-Paper-Abfahrtsmonitor (Seeed reTerminal
; E1003). Spec: docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md

[env:esp32dev]
platform = espressif32
board = seeed_xiao_esp32s3
framework = arduino
monitor_speed = 115200
upload_speed = 115200
board_build.arduino.memory_type = qio_opi
build_flags =
    -DCORE_DEBUG_LEVEL=0
    -DBOARD_HAS_PSRAM
lib_deps =
    zinggjm/GxEPD2@^1.5.3
    adafruit/Adafruit GFX Library@^1.11.9
    tzapu/WiFiManager@^2.0.17

; Isolierter Hardware-Bring-up-Test (s. Task 9): NUR src/hw_bringup.cpp,
; treibt das Panel minimal an, um Verkabelung/Timing zu pruefen, bevor der
; volle main.cpp-Ablauf laeuft. main.cpp bleibt aussen vor (sonst zwei
; setup()/loop()-Definitionen).
[env:bringup]
platform = espressif32
board = seeed_xiao_esp32s3
framework = arduino
monitor_speed = 115200
upload_speed = 115200
board_build.arduino.memory_type = qio_opi
build_flags =
    -DCORE_DEBUG_LEVEL=0
build_src_filter =
    -<*>
    +<hw_bringup.cpp>
lib_deps =
    zinggjm/GxEPD2@^1.5.3
    adafruit/Adafruit GFX Library@^1.11.9

; Host-Umgebung fuer die reine Logik in lib/boardlogic/: kein ESP32-Toolchain
; noetig. src/ (main.cpp, Display, HTTP-Client, Touch/Buttons/Battery --
; alles Hardware-Code) wird hier bewusst NICHT kompiliert.
[env:native]
platform = native
build_src_filter = -<*>
```

- [ ] **Step 2: Write `main.cpp`**

```cpp
#include <Arduino.h>
#include <WiFi.h>
#include <WiFiManager.h>
#include <Preferences.h>
#include <esp_sleep.h>
#include <esp_timer.h>
#include <esp_crt_bundle.h>
#include <time.h>
#include "board_config.h"
#include "board_client.h"
#include "display.h"
#include "touch.h"
#include "buttons.h"
#include "battery.h"
#include "error_state.h"

// Ueberlebt Tiefschlaf (RTC-Speicher, ESP32-intern -- keine externe RTC
// noetig, s. Spec §10 und Global Constraints).
RTC_DATA_ATTR int rtcConsecutiveFailures = 0;
RTC_DATA_ATTR char rtcLastEtag[80] = "";
RTC_DATA_ATTR int rtcLastFavoriteCount = 0;
RTC_DATA_ATTR int rtcLastTotalPages = 1;

static const uint32_t WIFI_TIMEOUT_MS = 15000;
static const uint32_t HTTP_TIMEOUT_MS = 8000;
static const uint32_t WAKE_BUTTON_LONG_PRESS_MS = 3000;

static Preferences prefs;

static String loadToken() {
    prefs.begin("board", true);
    String token = prefs.getString("token", "");
    prefs.end();
    return token;
}

static void saveToken(const String& token) {
    if (token.length() == 0) return;
    prefs.begin("board", false);
    prefs.putString("token", token);
    prefs.end();
}

static bool provisionAndConnect(String& outToken) {
    WiFiManager wm;
    String previousToken = loadToken();
    WiFiManagerParameter tokenParam("token", "API-Token (profil.php)", previousToken.c_str(), 128);
    wm.addParameter(&tokenParam);

    if (isWakeButtonHeld(WAKE_BUTTON_LONG_PRESS_MS)) {
        wm.resetSettings(); // langer Tastendruck -> zurueck in den Access-Point-Modus (Spec §10)
    }

    bool connected = wm.autoConnect("wlmonitor-setup");
    if (!connected) return false;

    outToken = String(tokenParam.getValue());
    saveToken(outToken);
    return true;
}

static void syncTimeForTls() {
    // Best-effort SNTP-Sync (Global Constraints): TLS-Zertifikatspruefung
    // braucht eine ungefaehr richtige Uhr, "kein NTP" aus Spec §10 bezog
    // sich nur auf die Zeitstempel-ANZEIGE, nicht auf TLS. Kurzer Timeout,
    // Fehlschlag ist nicht fatal -- der HTTPS-Request laeuft trotzdem an.
    configTime(0, 0, "pool.ntp.org", "time.nist.gov");
    struct tm timeinfo;
    getLocalTime(&timeinfo, 3000); // max 3s warten, Ergebnis wird nicht ausgewertet
}

static void goToSleep() {
    markSleepIcon();
    sleepPanel();
    WiFi.disconnect(true);
    esp_sleep_enable_ext1_wakeup(1ULL << 3, ESP_EXT1_WAKEUP_ANY_LOW); // GPIO3/KEY0
    esp_sleep_enable_timer_wakeup((uint64_t) POLL_INTERVAL_SEC * 1000000ULL);
    esp_deep_sleep_start();
}

void setup() {
    Serial.begin(115200);
    delay(300);

    initDisplay();
    initTouch(); // Rueckgabewert bewusst ignoriert -- fehlender Touch ist nicht fatal, s. touch.h

    String token;
    if (!provisionAndConnect(token)) {
        // Kein WLAN verbunden und keine Zugangsdaten hinterlegt --
        // WiFiManager-Portal wurde bereits versucht, hier bleibt nur:
        // wie ein WLAN-Ausfall behandeln.
        ErrorState st = nextErrorState(FetchOutcome::NetworkUnavailable, rtcConsecutiveFailures);
        rtcConsecutiveFailures = st.consecutiveFailures;
        if (st.banner != ErrorBanner::None) {
            showErrorBanner(st.banner, "??:??");
        }
        goToSleep();
        return;
    }

    syncTimeForTls();

    const char* touchValue = nullptr;
    TouchZone zone = pollTouch(rtcLastFavoriteCount, rtcLastTotalPages);
    if (zone != TouchZone::None) {
        touchValue = touchZoneToHeaderValue(zone);
    } else {
        // Kein Touch -- sekundaerer Tasten-Weg (Spec §10).
        touchValue = readPageButtons();
    }

    int batteryMv = readBatteryMillivolts();
    int rssi = WiFi.RSSI();

    BoardFetchResult fetch;
    fetchBoard(token.c_str(), touchValue, rtcLastEtag, batteryMv, rssi, HTTP_TIMEOUT_MS, fetch);

    FetchOutcome outcome;
    switch (fetch.outcome) {
        case BoardFetchOutcome::Success:           outcome = FetchOutcome::Success; break;
        case BoardFetchOutcome::Unauthorized:       outcome = FetchOutcome::Unauthorized; break;
        case BoardFetchOutcome::UnreadableResponse: outcome = FetchOutcome::UnreadableResponse; break;
        default:                                    outcome = FetchOutcome::NetworkUnavailable; break;
    }

    ErrorState st = nextErrorState(outcome, rtcConsecutiveFailures);
    rtcConsecutiveFailures = st.consecutiveFailures;

    if (outcome == FetchOutcome::Success) {
        if (fetch.parsed.isPatch) {
            applyPatch(fetch.body.data(), fetch.parsed.x, fetch.parsed.y, fetch.parsed.w, fetch.parsed.h);
        } else {
            applyFullFrame(fetch.body.data(), fetch.parsed.w, fetch.parsed.h);
        }
        strncpy(rtcLastEtag, fetch.parsed.etag.c_str(), sizeof(rtcLastEtag) - 1);
        rtcLastEtag[sizeof(rtcLastEtag) - 1] = '\0';
        rtcLastFavoriteCount = fetch.parsed.favoriteCount;
        rtcLastTotalPages = fetch.parsed.totalPages;
    } else if (st.banner != ErrorBanner::None) {
        showErrorBanner(st.banner, "??:??"); // Firmware fuehrt keine eigene Uhr (Spec §10), kein echter Zeitstempel verfuegbar
    }

    goToSleep();
}

void loop() {
    // Ungenutzt: setup() geht in Tiefschlaf, bevor loop() je aufgerufen wird.
}
```

**Note on the "??:??" placeholder in the offline banner:** the old firmware
used its RTC-anchor estimate to show a real `HH:MM` in the offline banner
text. This plan drops that estimate entirely (Task 8's design, matching the
brainstorming decision that removed the 15-minute staleness check) since
nothing else needs it — but that leaves the offline banner without a real
timestamp. This is a known, deliberate gap: flag it in the commit and
README as a follow-up worth a small decision (either accept "??:??"/omit
the time, or reintroduce a minimal RTC anchor _only_ for this banner) once
real hardware makes the banner actually visible to test against.

- [ ] **Step 3: Confirm it compiles**

Run: `pio run -e esp32dev` (from `epaper-monitor/`)
Expected: successful compile. This is the first point where all of Tasks
1-7's code actually links together — fix any signature mismatches
discovered here before committing.

- [ ] **Step 4: Self-review**

Confirm: every function called in `main.cpp` matches the exact signature
produced by its task (cross-check against each task's "Produces" list
above). Note explicitly that `setup()`'s control flow itself — not just
individual functions — is unverified without hardware.

- [ ] **Step 5: Commit**

```bash
cd epaper-monitor
git add src/main.cpp platformio.ini
git commit -m "feat(firmware): rewrite main.cpp orchestration for E1003 + Board-Protokoll (unverified, no hardware yet)"
```

---

### Task 9: E1003 hardware bring-up smoke sketch + README rewrite

**Files:**
- Create: `epaper-monitor/src/hw_bringup.cpp` (replaces the deleted
  `bw_test.cpp`, referenced by `[env:bringup]` in Task 8's `platformio.ini`)
- Modify (full rewrite): `epaper-monitor/README.md`

**Interfaces:**
- Consumes: nothing (standalone diagnostic sketch, deliberately independent
  of every other module so it can be flashed and checked BEFORE trusting
  the full `main.cpp` integration).
- Produces: nothing consumed elsewhere.

**Hardware-bound — this is explicitly the first thing to flash once
hardware arrives**, before attempting the full firmware — a minimal,
narrowly-scoped smoke test that isolates "does the panel respond at all"
from every other subsystem.

- [ ] **Step 1: Write `hw_bringup.cpp`**

> **Update 2026-08-17:** uses the class/API corrected during Task 5's real
> execution (`GxEPD2_it103_1872x1404`, not the originally-guessed
> `GxEPD2_ED103TC2_1872x1404` — see Task 5's own update note for the full
> reasoning). Same `EPD_DC_PIN=-1` open question applies here too.

```cpp
// Isolierter Hardware-Bring-up-Test fuer das reTerminal E1003 (ersetzt das
// alte bw_test.cpp, das gegen den frueheren Waveshare-7,5"-Panel gebaut
// war). Zeichnet nur "HELLO E1003" auf einen sonst leeren Screen -- prueft
// Verkabelung/SPI-Timing/Panel-Init, unabhaengig von WLAN, Touch, HTTPS
// oder allem anderen aus main.cpp. Nur im Environment [env:bringup]
// kompiliert (siehe platformio.ini), main.cpp ist dort ausgeschlossen
// (sonst zwei setup()/loop()-Definitionen).
#include <Arduino.h>
#include <it8951/GxEPD2_it103_1872x1404.h>
#include <GxEPD2_BW.h>
#include <Fonts/FreeSansBold24pt7b.h>

#define EPD_CS_PIN      10
#define EPD_RES_PIN     12
#define EPD_BUSY_PIN    13
#define EPD_TFT_ENABLE  11
#define EPD_ITE_ENABLE  21
#define EPD_SCK_PIN     7
#define EPD_MISO_PIN    8
#define EPD_MOSI_PIN    9
// UNGEKLAERT (s. Task 5): kein DC-Pin in der Seeed-Wiki-Pinliste, -1 =
// GxEPD2s "nicht verdrahtet"-Konvention -- vor dem ersten Flash pruefen.
#define EPD_DC_PIN      -1

static GxEPD2_BW<GxEPD2_it103_1872x1404, 128> epd(
    GxEPD2_it103_1872x1404(EPD_CS_PIN, EPD_DC_PIN, EPD_RES_PIN, EPD_BUSY_PIN));

void setup() {
    Serial.begin(115200);
    delay(500);
    Serial.println("[bringup] setup() start");

    pinMode(EPD_TFT_ENABLE, OUTPUT);
    digitalWrite(EPD_TFT_ENABLE, HIGH);
    pinMode(EPD_ITE_ENABLE, OUTPUT);
    digitalWrite(EPD_ITE_ENABLE, HIGH);

    // GxEPD2_it103_1872x1404 ignoriert selectSPI() (s. Task 5) -- Pins
    // direkt auf dem globalen SPI-Objekt binden, vor epd.init().
    SPI.begin(EPD_SCK_PIN, EPD_MISO_PIN, EPD_MOSI_PIN, -1);
    epd.init(115200, true, 20, false);
    // Kein setRotation() noetig: die Klasse ist bereits nativ 1872x1404.

    Serial.println("[bringup] init() done, drawing");

    epd.setFullWindow();
    epd.firstPage();
    do {
        epd.fillScreen(GxEPD_WHITE);
        epd.setFont(&FreeSansBold24pt7b);
        epd.setTextColor(GxEPD_BLACK);
        epd.setCursor(100, 200);
        epd.print("HELLO E1003");
    } while (epd.nextPage());

    Serial.println("[bringup] draw complete");
}

void loop() {
}
```

- [ ] **Step 2: Confirm it compiles**

Run: `pio run -e bringup` (from `epaper-monitor/`)
Expected: successful compile, independent of `main.cpp`/Task 8.

- [ ] **Step 3: Rewrite `README.md`**

```markdown
# epaper-monitor/

ESP32-S3-Firmware für den E-Paper-Abfahrtsmonitor (Seeed reTerminal E1003,
1872×1404, 16-Graustufen-fähig — diese Firmware rendert vorerst 1bpp, s.
Spec §6/§13). Spec:
`../docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md`.

## Bauen

```bash
brew install platformio   # einmalig
pio run -e esp32dev               # nur kompilieren
pio test -e native                # reine Logik testen (kein Geraet noetig)
```

## Erstes Flashen — IMMER zuerst den Bring-up-Test

Vor dem vollen Firmware-Flash: prüft `[env:bringup]`, ob das Panel
grundsätzlich anspricht, unabhängig von WLAN/Touch/HTTPS:

```bash
pio run -e bringup -t upload
pio device monitor
```

Zeigt „HELLO E1003" auf dem Panel? Dann weiter mit dem vollen Flash unten.
Zeigt sich nichts: erst Verkabelung/Pins/GxEPD2-Panelklasse prüfen (s.
`src/display.h`s Kommentare), bevor an der restlichen Firmware gesucht wird.

## Konfigurieren — kein config.h mehr

**Keine Geheimnisse im Quellcode.** WLAN-Zugangsdaten und das API-Token
werden beim ersten Boot über einen WiFiManager-Captive-Portal eingegeben:

1. Gerät flashen (unten), erstes Boot ohne gespeichertes WLAN → Gerät
   spannt einen eigenen Access Point `wlmonitor-setup` auf.
2. Mit Handy/Laptop verbinden, das sich automatisch öffnende Formular
   ausfüllen: WLAN-SSID/Passwort **und** ein frisches API-Token aus
   `profil.php` → Abschnitt „API-Token" (auf akadbrain — **nie** ein Token
   wiederverwenden, das schon einmal im Klartext aufgetaucht ist, z. B. in
   einem Chat).
3. Gerät verbindet sich, pollt `https://wlmonitor.eriks.cloud/board.php`
   direkt (kein `fav`-Parameter mehr — Favoriten kommen serverseitig aus
   dem Token).

**Neu-Provisionierung ohne Reflash:** grüne Refresh-/Wake-Taste (GPIO3)
3 Sekunden beim Boot gedrückt halten → Gerät geht zurück in den
Access-Point-Modus, WLAN und Token lassen sich neu eingeben.

`include/board_config.h` ist **committed** (keine Geheimnisse mehr darin —
nur `BOARD_HOST`/`BOARD_PORT`/`POLL_INTERVAL_SEC`, gleich für jedes Gerät).

## Flashen (volle Firmware)

```bash
pio run -e esp32dev -t upload
pio device monitor
```

## Kein Hardware-Zugriff beim Schreiben dieser Firmware

Diese Firmware wurde ohne physisches reTerminal E1003 geschrieben (Plan:
`../docs/superpowers/plans/2026-08-17-epaper-firmware-e1003.md`). Folgende
Annahmen sind **unverifiziert** und müssen beim ersten echten Hardwaretest
geprüft werden:

- Display-Panel-Name (Spec: „ED103TC2"; die reale, installierte GxEPD2-Klasse
  `GxEPD2_it103_1872x1404` nennt ihr Panel „ES103TC1" — Controller (IT8951)
  + Auflösung (1872×1404) + Größe (10,3″) passen exakt, der Namensunterschied
  ist wahrscheinlich eine Waveshare-/Good-Display-Namensvariante derselben
  Panel-Familie, aber ohne Hardware nicht zu 100% auszuschließen).
- **DC-Pin für das ePaper-SPI-Interface** (`src/display.cpp`/`hw_bringup.cpp`,
  `EPD_DC_PIN=-1`) — die Seeed-Wiki-Pinliste kennt keinen DC-Pin für die
  E1003-ePaper-Schnittstelle; Quellcode-Analyse der echten Bibliothek zeigt,
  dass der Treiber `_dc` nirgends referenziert (IT8951 nutzt SPI-Präambel-
  Worte statt einer DC-Leitung) — starkes Indiz für „-1 ist richtig", aber
  vor dem ersten Flash gegen Schaltplan/Seeeds eigene Firmware gegenchecken.
- GT911-Touch-Registerprotokoll (`src/touch.cpp`) — aus Wiki-Beispielcode
  transkribiert, nicht aus verifizierter Bibliotheks-API.
- Touch-Rotation (`rawX`/`rawY` → Panel-Koordinaten in `src/touch.cpp`) —
  höchstes Risiko in diesem Plan, braucht echten Touch-Test.
- KEY1/KEY2-Tastenrollen (`src/buttons.cpp`) — nur KEY0 (Wake) ist bestätigt.
- Batterie-mV-Skalierungsfaktor (`src/battery.cpp`) — `*2`-Divisor-Frage
  ungeklärt, siehe Kommentar dort.

**Bereits gegen echten Bibliotheks-Quellcode verifiziert** (nicht mehr auf
der obigen Liste, s. Task 5): `GxEPD2_it103_1872x1404`-Klassenname und
-API-Form, die `invert=false`-Bit-Konvention für den Pixel-Blit, und dass
`selectSPI()` von diesem Treiber ignoriert wird (SPI-Pins müssen direkt auf
das globale `SPI`-Objekt gebunden werden).
```

- [ ] **Step 4: Commit**

```bash
cd epaper-monitor
git add src/hw_bringup.cpp README.md
git commit -m "feat(firmware): add E1003 hardware bring-up smoke test, rewrite README"
```

---

## Self-Review

**Spec coverage:**
- §3 Hardware (pins, board, PSRAM) → Global Constraints + Tasks 5/6/7/8.
- §4 Architektur (touch/button-triggered polls, token-resolved favorites) → Task 8.
- §5 Bild-Protokoll (request/response headers incl. the two 2026-08-17
  additions, no `fav` param, no classic caching) → Tasks 1, 4, 8.
- §6 Rendering-Pipeline-Anschluss (1bpp path, no dithering assumption on
  the wire — firmware just blits what it receives) → Task 5.
- §9 Layout (touch-bar/pagination pixel geometry, WiFi-icon rect) → Tasks 2, 5.
- §10 Firmware (HTTPS not LAN, GxEPD2, WiFiManager provisioning, sleep
  icon, error escalation, no NTP for display/SNTP-for-TLS distinction) →
  Tasks 3, 4, 5, 8.
- §11 Fehlerfälle (offline/401/503/unreadable escalation, sleep-icon
  staleness replacing timestamp-inversion) → Task 5 (banner rendering),
  Task 8 (escalation wiring), `error_state.h/cpp` (unchanged, reused).
- §12 Tests (`pio test -e native` for fallback-banner logic and
  touch-zone-to-request mapping) → Tasks 1, 2 (the actual native-testable
  scope is now `board_response` + `touch_zone`, since `error_state` was
  already covered before this plan and needs no new tests).

**Placeholder scan:** no TBD/TODO markers; every hardware-bound task
carries complete code plus an explicit, named "unverified without
hardware" disclosure rather than a vague placeholder — that disclosure is
itself the honest documentation this plan needs, not a gap to fill later.

**Type consistency:** `BoardFetchResult`'s `parsed`/`body` fields (Task 4)
match what `main.cpp` (Task 8) reads (`fetch.parsed.isPatch`,
`fetch.parsed.x/y/w/h/etag/favoriteCount/totalPages`, `fetch.body.data()`).
`pollTouch()`'s `favoriteCount`/`totalPages` parameters (Task 6) match the
`rtcLastFavoriteCount`/`rtcLastTotalPages` RTC variables Task 8 threads
through across wake cycles. `touchZoneToHeaderValue()`'s `const char*`
return (Task 2) matches `fetchBoard()`'s `touchValue: const char*`
parameter (Task 4) and `main.cpp`'s handling of a possible `nullptr`
(no header sent) throughout.
