# E-Paper-Abfahrtsmonitor — Firmware Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ESP32-Firmware, die alle 2 Minuten `board.php` abfragt und die nächsten
Abfahrten zweier Favoriten auf einem Waveshare 7.5″ e-Paper HAT (B) zeigt.

**Architecture:** PlatformIO-Projekt in `epaper-monitor/`. Reine Entscheidungslogik
(JSON-Parsing, Darstellungsregeln, Fehlerklassifikation) liegt in
`lib/boardlogic/` — kompiliert und getestet ohne Hardware (`pio test -e
native`, Unity). Hardware-Code (WLAN, HTTP, GxEPD2-Rendering, Tiefschlaf) liegt
in `src/`, kompiliert nur für `esp32dev` (`pio run -e esp32dev`, Compile-Check
ohne Gerät) und wird zuletzt manuell auf echter Hardware verifiziert.

**Tech Stack:** PlatformIO, Arduino-Framework (ESP32), GxEPD2 + Adafruit GFX
(inkl. gebündelter Free-Fonts, siehe Task 8), ArduinoJson v7, Unity
(PlatformIO-Testframework), Pillow/librsvg (Logo-Konvertierung, einmalig,
Python).

## Global Constraints

- **Hardware:** ESP32-WROVER auf dem Waveshare „e-Paper ESP32 Driver Board“,
  Panel Waveshare 7,5″ e-Paper HAT (B), 800×480, schwarz/weiß/rot, **kein
  Partial Refresh** (Vollbild-Refresh 15–25 s).
- **Pins (fest verdrahtet vom Treiberboard):** SCLK 13 · DIN 14 · CS 15 ·
  BUSY 25 · RST 26 · DC 27.
- **Takt:** alle 120 s aufwachen, abfragen, **immer** neu zeichnen (kein
  Partial Refresh möglich), dann Tiefschlaf. Kein Dauerbetrieb mit Timer/loop().
- **Verbindung (Stand 2026-08-02, siehe Spec-Nachtrag §8):** Klartext-HTTP zu
  `http://10.10.10.18:8090/board.php` — LAN-only, **kein TLS, kein
  Root-Zertifikat, kein NTP**. Der Listener gibt ausschließlich diesen einen
  Pfad frei (Details: `wlmonitor/docs/deploy-board-endpunkt.md`).
- **Auth:** `Authorization: Bearer <token>`-Header, Token aus `profil.php` →
  Abschnitt „API-Token“, auf akadbrain ausgestellt.
- **Favoriten:** ID 219 („Arbeit“, linke Spalte) und ID 218 („zur Stadt“,
  rechte Spalte) — `?fav=219,218`.
- **Keine eigene Uhr/NTP.** Der einzige Zeitanker ist `generated` aus einer
  erfolgreichen Antwort, kombiniert mit der seither vergangenen Laufzeit
  (RTC-Speicher, überlebt Tiefschlaf).
- **Farben/Stile (Spec §7):** nächste Abfahrt + Echtzeit → rot, groß. Nächste
  Abfahrt + nur Fahrplan → schwarz, groß, kursiv. Verzögerte Abfahrt (`delayed:
  true`) → **weiß auf rot, invertiert — hat Vorrang vor allem anderen.**
  Folgeabfahrten → klein, schwarz. `"in": 0` → als Symbol, nicht als „0“.
- **Fehleranzeige (Spec §9):** HTTP 401 → sofort „Token ungültig“ im Header.
  WLAN-Fehler / 503 / unlesbare Antwort → Bild stehen lassen, **erst nach 3
  aufeinanderfolgenden Fehlversuchen** „offline“ anzeigen. `generated` älter
  als 15 Minuten → Zeitstempel im Header invertiert.
- **`epaper-monitor/` wird nie deployt** — Ausschluss in beiden Deploy-Pfaden
  (Task 1) ist Voraussetzung für alles Weitere.
- **`config.h` geht nie ins Repo** (gitignored); `config.example.h` ist die
  einzige committete Vorlage.
- **Explizit nicht Teil dieses Plans** (Spec §11): manueller Auslöser
  (Taster/HC-SR04), Akkubetrieb, Umstellung des Web-UI auf Serverfilterung,
  Ursache der doppelten WL-Zeilen.

---

## Task 1: `epaper-monitor/` von beiden Deploy-Pfaden ausschließen

Muss vor jedem weiteren Task stehen: sobald `epaper-monitor/` existiert, darf kein
Deploy es versehentlich auf einen Webserver kopieren.

**Files:**
- Modify: `/Users/erikr/Git/mcp/lib/rsync.sh:42` (generischer Pfad, u. a. für
  akadbrain)
- Modify: `/Users/erikr/Git/wlmonitor/scripts/ssh_deploy.php` (world4you-Pfad,
  Array `$rsyncExcludes`, aktuell Zeilen 96–142)

**Interfaces:** keine — reine Konfiguration, keine Funktionssignaturen.

- [ ] **Step 1: `epaper-monitor/`-Ausschluss in `mcp/lib/rsync.sh` ergänzen**

In `/Users/erikr/Git/mcp/lib/rsync.sh`, direkt nach der Zeile
`--exclude="backlog/"` (aktuell Zeile 42) einfügen:

```bash
    --exclude="backlog/"
    --exclude="epaper-monitor/"
    --exclude="CLAUDE.md"
```

- [ ] **Step 2: `epaper-monitor/`-Ausschluss in `wlmonitor/scripts/ssh_deploy.php` ergänzen**

In `/Users/erikr/Git/wlmonitor/scripts/ssh_deploy.php`, im Array
`$rsyncExcludes`, direkt nach dem Eintrag `'scripts/',` (aktuell Zeile 124)
einfügen:

```php
    'scripts/',
    'epaper-monitor/',
```

- [ ] **Step 3: Ausschluss gegen einen echten Marker beweisen**

```bash
cd /Users/erikr/Git/wlmonitor
mkdir -p firmware && touch epaper-monitor/.exclude-test
rm -rf /tmp/wlmonitor-deploy-test
bash /Users/erikr/Git/mcp/lib/rsync.sh local /Users/erikr/Git/wlmonitor /tmp/wlmonitor-deploy-test
ls /tmp/wlmonitor-deploy-test/firmware 2>&1
rm -rf firmware /tmp/wlmonitor-deploy-test
```

Erwartet für den `ls`-Befehl: `ls: /tmp/wlmonitor-deploy-test/firmware: No such file or directory`
— das beweist, dass der neue Exclude auf einer echten rsync-Ausführung
greift, nicht nur, dass die Zeile im Skript steht. Das `rm -rf firmware`
danach ist wichtig: der Marker-Ordner darf Task 2 nicht im Weg stehen.

- [ ] **Step 4: `$rsyncExcludes`-Eintrag verifizieren**

```bash
grep -n "'epaper-monitor/'" /Users/erikr/Git/wlmonitor/scripts/ssh_deploy.php
```

Erwartet: ein Treffer, direkt nach der `'scripts/',`-Zeile.

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/mcp
git add lib/rsync.sh
git commit -m "$(cat <<'EOF'
fix(deploy): epaper-monitor/ vom Sync ausschliessen (wlmonitor E-Paper-Projekt)

Co-Authored-By: Claude <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Gpt6tdgtR4AFNeHFTLS53U
EOF
)"

cd /Users/erikr/Git/wlmonitor
git add scripts/ssh_deploy.php
git commit -m "$(cat <<'EOF'
fix(deploy): epaper-monitor/ vom world4you-Sync ausschliessen

Co-Authored-By: Claude <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Gpt6tdgtR4AFNeHFTLS53U
EOF
)"
```

---

## Task 2: PlatformIO-Projektgerüst

**Files:**
- Create: `epaper-monitor/platformio.ini`
- Create: `epaper-monitor/include/config.example.h`
- Create: `epaper-monitor/.gitignore`
- Create: `epaper-monitor/test/test_smoke/test_smoke.cpp`

**Interfaces:**
- Produces: die Verzeichnisse `epaper-monitor/lib/boardlogic/` (reine Logik, ab
  Task 3) und `epaper-monitor/src/` (Hardware-Code, ab Task 6). Die Konstanten aus
  `config.example.h` (`WIFI_SSID`, `WIFI_PASSWORD`, `BOARD_HOST`,
  `BOARD_PORT`, `BOARD_TOKEN`, `BOARD_FAV_IDS`, `POLL_INTERVAL_SEC`) werden
  ab Task 9 (`main.cpp`) verwendet — Namen hier verbindlich für den ganzen
  Plan.

- [ ] **Step 1: PlatformIO installieren**

```bash
brew install platformio
pio --version
```

Erwartet: eine Versionsnummer (kein „command not found“).

- [ ] **Step 2: `platformio.ini` anlegen**

`/Users/erikr/Git/wlmonitor/epaper-monitor/platformio.ini`:

```ini
; PlatformIO-Projekt fuer den E-Paper-Abfahrtsmonitor (ESP32 + Waveshare
; 7.5" e-Paper HAT (B)). Spec:
; docs/superpowers/specs/2026-08-01-epaper-abfahrtsmonitor-design.md

[env:esp32dev]
platform = espressif32
board = esp32dev
framework = arduino
monitor_speed = 115200
build_flags =
    -DCORE_DEBUG_LEVEL=0
lib_deps =
    zinggjm/GxEPD2@^1.5.3
    adafruit/Adafruit GFX Library@^1.11.9
    bblanchon/ArduinoJson@^7.0.4

; Host-Umgebung fuer die reine Logik in lib/boardlogic/: kein ESP32-Toolchain
; noetig. src/ (main.cpp, Display, HTTP-Client -- alles Hardware-Code) wird
; hier bewusst NICHT kompiliert.
[env:native]
platform = native
lib_deps =
    bblanchon/ArduinoJson@^7.0.4
build_src_filter = -<*>
```

- [ ] **Step 3: `.gitignore` anlegen**

`/Users/erikr/Git/wlmonitor/epaper-monitor/.gitignore`:

```
include/config.h
.pio/
```

- [ ] **Step 4: `config.example.h` anlegen**

`/Users/erikr/Git/wlmonitor/epaper-monitor/include/config.example.h`:

```cpp
#pragma once

// Kopieren nach config.h (bleibt lokal, siehe .gitignore) und echte Werte
// eintragen. main.cpp bindet config.h per #include "config.h" ein.

#define WIFI_SSID     "dein-wlan"
#define WIFI_PASSWORD "dein-wlan-passwort"

// LAN-Listener auf akadbrain, gibt ausschliesslich /board.php frei (siehe
// wlmonitor/docs/deploy-board-endpunkt.md). Klartext-HTTP, keine TLS-Kette
// noetig -- das Geraet steht ausschliesslich zu Hause.
#define BOARD_HOST "10.10.10.18"
#define BOARD_PORT 8090

// profil.php -> Abschnitt "API-Token", auf akadbrain ausgestellt. NIE ein
// Token wiederverwenden, das schon einmal im Klartext (Chat, Ticket,
// Repository) aufgetaucht ist -- es gilt als kompromittiert.
#define BOARD_TOKEN "<token einsetzen>"

// Favoriten-IDs aus der URL von editFavorite.php?id=... Reihenfolge =
// Spaltenreihenfolge: erste ID linke Spalte, zweite ID rechte Spalte.
#define BOARD_FAV_IDS "219,218"

#define POLL_INTERVAL_SEC 120
```

- [ ] **Step 5: Smoke-Test fuer die native Testumgebung**

`/Users/erikr/Git/wlmonitor/epaper-monitor/test/test_smoke/test_smoke.cpp`:

```cpp
#include <unity.h>

void test_arithmetic_sanity(void) {
    TEST_ASSERT_EQUAL(4, 2 + 2);
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_arithmetic_sanity);
    return UNITY_END();
}
```

- [ ] **Step 6: Native Testumgebung verifizieren**

```bash
cd /Users/erikr/Git/wlmonitor/firmware
pio test -e native
```

Erwartet: `test_arithmetic_sanity` PASSED, 1 Test insgesamt. Das beweist, dass
Toolchain, `platformio.ini` und Unity-Integration funktionieren, bevor Task 3
echte Logik dazu schreibt.

- [ ] **Step 7: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add epaper-monitor/platformio.ini epaper-monitor/.gitignore epaper-monitor/include/config.example.h epaper-monitor/test/test_smoke/test_smoke.cpp
git commit -m "$(cat <<'EOF'
feat(firmware): PlatformIO-Projektgeruest fuer den E-Paper-Monitor

Co-Authored-By: Claude <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Gpt6tdgtR4AFNeHFTLS53U
EOF
)"
```

---

## Task 3: `board_model` — Antwort von `board.php` parsen

Reine Datenschicht: JSON-Text hinein, `BoardResponse`-Struktur oder ein
Fehlerstatus hinaus. Kein Netz, keine Hardware — vollständig nativ testbar.

**Files:**
- Create: `epaper-monitor/lib/boardlogic/board_model.h`
- Create: `epaper-monitor/lib/boardlogic/board_model.cpp`
- Create: `epaper-monitor/test/test_board_model/test_board_model.cpp`

**Interfaces:**
- Consumes: nichts (erste reine Logik-Datei).
- Produces: `struct Departure { int inMinutes; std::string towards; std::string
  line; bool delayed; }`, `struct Line { std::string name, platform, towards,
  type; bool realtime, alert; std::vector<Departure> departures; }`,
  `struct Station { std::string diva, name; std::vector<Line> lines; }`,
  `struct Favorite { int id; std::string title; std::vector<Station>
  stations; }`, `struct BoardResponse { std::string generated;
  std::vector<Favorite> favorites; }`, `enum class ParseStatus { Ok,
  ErrorUnauthorized, ErrorUpstreamUnavailable, ErrorServerError,
  ErrorUnknownBody, ErrorMalformedJson }`, `ParseStatus
  parseBoardResponse(const std::string& json, BoardResponse& out)`. Werden ab
  Task 7 (board_client), Task 8 (display) und Task 9 (main.cpp) verwendet.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/test/test_board_model/test_board_model.cpp`:

```cpp
#include <unity.h>
#include "board_model.h"

void test_parses_full_response(void) {
    const char* json = R"JSON(
    {
      "generated": "2026-08-02T16:44:43+02:00",
      "favorites": [
        {
          "id": 219,
          "title": "Arbeit",
          "stations": [
            {
              "diva": "60200103",
              "name": "Aßmayergasse",
              "lines": [
                {
                  "line": "59A",
                  "platform": "1",
                  "towards": "Bhf. Meidling S U",
                  "type": "bus",
                  "realtime": true,
                  "alert": false,
                  "departures": [
                    { "in": 4 },
                    { "in": 23, "towards": "Alterlaa" }
                  ]
                }
              ]
            }
          ]
        }
      ]
    }
    )JSON";

    BoardResponse out;
    ParseStatus status = parseBoardResponse(json, out);

    TEST_ASSERT_TRUE(status == ParseStatus::Ok);
    TEST_ASSERT_EQUAL_STRING("2026-08-02T16:44:43+02:00", out.generated.c_str());
    TEST_ASSERT_EQUAL(1, out.favorites.size());

    const Favorite& fav = out.favorites[0];
    TEST_ASSERT_EQUAL(219, fav.id);
    TEST_ASSERT_EQUAL_STRING("Arbeit", fav.title.c_str());
    TEST_ASSERT_EQUAL(1, fav.stations.size());

    const Station& st = fav.stations[0];
    TEST_ASSERT_EQUAL_STRING("60200103", st.diva.c_str());
    TEST_ASSERT_EQUAL(1, st.lines.size());

    const Line& ln = st.lines[0];
    TEST_ASSERT_EQUAL_STRING("59A", ln.name.c_str());
    TEST_ASSERT_EQUAL_STRING("1", ln.platform.c_str());
    TEST_ASSERT_EQUAL_STRING("Bhf. Meidling S U", ln.towards.c_str());
    TEST_ASSERT_EQUAL_STRING("bus", ln.type.c_str());
    TEST_ASSERT_TRUE(ln.realtime);
    TEST_ASSERT_FALSE(ln.alert);
    TEST_ASSERT_EQUAL(2, ln.departures.size());

    TEST_ASSERT_EQUAL(4, ln.departures[0].inMinutes);
    TEST_ASSERT_TRUE(ln.departures[0].towards.empty());
    TEST_ASSERT_FALSE(ln.departures[0].delayed);

    TEST_ASSERT_EQUAL(23, ln.departures[1].inMinutes);
    TEST_ASSERT_EQUAL_STRING("Alterlaa", ln.departures[1].towards.c_str());
}

void test_delayed_flag_is_read(void) {
    const char* json =
        "{\"generated\":\"2026-08-02T16:44:43+02:00\",\"favorites\":[{\"id\":1,\"title\":\"T\","
        "\"stations\":[{\"diva\":\"1\",\"name\":\"N\",\"lines\":[{\"line\":\"U6\",\"platform\":\"1\","
        "\"towards\":\"X\",\"type\":\"metro\",\"realtime\":true,\"alert\":false,"
        "\"departures\":[{\"in\":3,\"delayed\":true}]}]}]}]}";

    BoardResponse out;
    TEST_ASSERT_TRUE(parseBoardResponse(json, out) == ParseStatus::Ok);
    TEST_ASSERT_TRUE(out.favorites[0].stations[0].lines[0].departures[0].delayed);
}

void test_empty_favorites_is_ok(void) {
    BoardResponse out;
    ParseStatus status = parseBoardResponse(
        "{\"generated\":\"2026-08-02T16:44:43+02:00\",\"favorites\":[]}", out);
    TEST_ASSERT_TRUE(status == ParseStatus::Ok);
    TEST_ASSERT_EQUAL(0, out.favorites.size());
}

void test_unauthorized_error_body(void) {
    BoardResponse out;
    ParseStatus status = parseBoardResponse("{\"error\":\"unauthorized\"}", out);
    TEST_ASSERT_TRUE(status == ParseStatus::ErrorUnauthorized);
}

void test_upstream_unavailable_error_body(void) {
    BoardResponse out;
    ParseStatus status = parseBoardResponse("{\"error\":\"upstream_unavailable\"}", out);
    TEST_ASSERT_TRUE(status == ParseStatus::ErrorUpstreamUnavailable);
}

void test_server_error_body(void) {
    BoardResponse out;
    ParseStatus status = parseBoardResponse("{\"error\":\"server_error\"}", out);
    TEST_ASSERT_TRUE(status == ParseStatus::ErrorServerError);
}

void test_malformed_json_is_reported(void) {
    BoardResponse out;
    ParseStatus status = parseBoardResponse("{not json", out);
    TEST_ASSERT_TRUE(status == ParseStatus::ErrorMalformedJson);
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_parses_full_response);
    RUN_TEST(test_delayed_flag_is_read);
    RUN_TEST(test_empty_favorites_is_ok);
    RUN_TEST(test_unauthorized_error_body);
    RUN_TEST(test_upstream_unavailable_error_body);
    RUN_TEST(test_server_error_body);
    RUN_TEST(test_malformed_json_is_reported);
    return UNITY_END();
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

```bash
cd /Users/erikr/Git/wlmonitor/firmware
pio test -e native
```

Erwartet: Fehler beim Kompilieren — `board_model.h` existiert noch nicht
(„fatal error: board_model.h: No such file or directory“).

- [ ] **Step 3: `board_model.h` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/lib/boardlogic/board_model.h`:

```cpp
#pragma once
#include <string>
#include <vector>

struct Departure {
    int inMinutes = 0;
    std::string towards;   // leer = keine Abweichung von Line::towards
    std::string line;      // leer = keine Abweichung von Line::name
    bool delayed = false;
};

struct Line {
    std::string name;
    std::string platform;
    std::string towards;
    std::string type;      // "metro" | "tram" | "bus" | "train" | "other"
    bool realtime = true;
    bool alert = false;
    std::vector<Departure> departures;
};

struct Station {
    std::string diva;
    std::string name;
    std::vector<Line> lines;
};

struct Favorite {
    int id = 0;
    std::string title;
    std::vector<Station> stations;
};

struct BoardResponse {
    std::string generated;   // ISO 8601 mit Zone, z. B. "2026-08-02T16:44:43+02:00"
    std::vector<Favorite> favorites;
};

enum class ParseStatus {
    Ok,
    ErrorUnauthorized,        // Koerper war {"error":"unauthorized"}
    ErrorUpstreamUnavailable, // {"error":"upstream_unavailable"}
    ErrorServerError,         // {"error":"server_error"}
    ErrorUnknownBody,         // gueltiges JSON, aber weder Antwort noch bekannter Fehler
    ErrorMalformedJson,       // das JSON selbst liess sich nicht parsen
};

ParseStatus parseBoardResponse(const std::string& json, BoardResponse& out);
```

- [ ] **Step 4: `board_model.cpp` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/lib/boardlogic/board_model.cpp`:

```cpp
#include "board_model.h"
#include <ArduinoJson.h>

ParseStatus parseBoardResponse(const std::string& json, BoardResponse& out) {
    JsonDocument doc;
    DeserializationError err = deserializeJson(doc, json);
    if (err) {
        return ParseStatus::ErrorMalformedJson;
    }

    if (doc["error"].is<const char*>()) {
        std::string code = doc["error"].as<const char*>();
        if (code == "unauthorized") return ParseStatus::ErrorUnauthorized;
        if (code == "upstream_unavailable") return ParseStatus::ErrorUpstreamUnavailable;
        if (code == "server_error") return ParseStatus::ErrorServerError;
        return ParseStatus::ErrorUnknownBody;
    }

    if (!doc["generated"].is<const char*>() || !doc["favorites"].is<JsonArray>()) {
        return ParseStatus::ErrorUnknownBody;
    }

    out.generated = doc["generated"].as<const char*>();
    out.favorites.clear();

    for (JsonObject favJson : doc["favorites"].as<JsonArray>()) {
        Favorite fav;
        fav.id = favJson["id"] | 0;
        fav.title = std::string((const char*) (favJson["title"] | ""));

        for (JsonObject stJson : favJson["stations"].as<JsonArray>()) {
            Station st;
            st.diva = std::string((const char*) (stJson["diva"] | ""));
            st.name = std::string((const char*) (stJson["name"] | ""));

            for (JsonObject lnJson : stJson["lines"].as<JsonArray>()) {
                Line ln;
                ln.name = std::string((const char*) (lnJson["line"] | ""));
                ln.platform = std::string((const char*) (lnJson["platform"] | ""));
                ln.towards = std::string((const char*) (lnJson["towards"] | ""));
                ln.type = std::string((const char*) (lnJson["type"] | "other"));
                ln.realtime = lnJson["realtime"] | true;
                ln.alert = lnJson["alert"] | false;

                for (JsonObject depJson : lnJson["departures"].as<JsonArray>()) {
                    Departure dep;
                    dep.inMinutes = depJson["in"] | 0;
                    dep.towards = std::string((const char*) (depJson["towards"] | ""));
                    dep.line = std::string((const char*) (depJson["line"] | ""));
                    dep.delayed = depJson["delayed"] | false;
                    ln.departures.push_back(dep);
                }
                st.lines.push_back(ln);
            }
            fav.stations.push_back(st);
        }
        out.favorites.push_back(fav);
    }

    return ParseStatus::Ok;
}
```

- [ ] **Step 5: Test laufen lassen, Erfolg bestätigen**

```bash
cd /Users/erikr/Git/wlmonitor/firmware
pio test -e native
```

Erwartet: alle 7 Tests PASSED.

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add epaper-monitor/lib/boardlogic/board_model.h epaper-monitor/lib/boardlogic/board_model.cpp epaper-monitor/test/test_board_model/test_board_model.cpp
git commit -m "$(cat <<'EOF'
feat(firmware): board_model parst die board.php-Antwort

Co-Authored-By: Claude <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Gpt6tdgtR4AFNeHFTLS53U
EOF
)"
```

---

## Task 4: `layout` — Darstellungsentscheidungen

Reine Funktionen für alles, was Spec §7/§9 über Farbe, Stil, Kürzung und
"veraltet" festlegt. Enthält auch die Zeitrechnung ohne eigene Uhr
(`parseIso8601`/`estimateNow`/`isStale`, siehe Global Constraints).

**Files:**
- Create: `epaper-monitor/lib/boardlogic/layout.h`
- Create: `epaper-monitor/lib/boardlogic/layout.cpp`
- Create: `epaper-monitor/test/test_layout/test_layout.cpp`

**Interfaces:**
- Consumes: nichts (unabhängig von `board_model`).
- Produces: `bool parseIso8601(const std::string& iso, time_t& outEpoch)`,
  `time_t estimateNow(time_t anchorGeneratedEpoch, uint64_t anchorUptimeMs,
  uint64_t currentUptimeMs)`, `bool isStale(time_t estimatedNow, time_t
  generatedEpoch, int staleMinutes)`, `enum class DepartureStyle { RedLive,
  BlackItalic, Inverted, BlackSmall }`, `DepartureStyle departureStyle(bool
  isFirst, bool realtime, bool delayed)`, `bool isDepartingNow(int
  inMinutes)`, `size_t utf8Length(const std::string& s)`, `std::string
  utf8Prefix(const std::string& s, size_t codepoints)`, `std::string
  truncateToWidth(const std::string& text, int maxWidthPx, int
  avgCharWidthPx)`. Werden ab Task 8 (display) und Task 9 (main.cpp)
  verwendet.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/test/test_layout/test_layout.cpp`:

```cpp
#include <unity.h>
#include "layout.h"

// ── Zeit ─────────────────────────────────────────────────────────────────

void test_parseIso8601_epoch_zero(void) {
    time_t epoch = -1;
    TEST_ASSERT_TRUE(parseIso8601("1970-01-01T00:00:00+00:00", epoch));
    TEST_ASSERT_EQUAL(0, (long) epoch);
}

void test_parseIso8601_positive_offset(void) {
    time_t epoch = -1;
    TEST_ASSERT_TRUE(parseIso8601("2026-08-02T16:44:43+02:00", epoch));
    TEST_ASSERT_EQUAL(1785681883L, (long) epoch);
}

void test_parseIso8601_negative_offset(void) {
    time_t epoch = -1;
    TEST_ASSERT_TRUE(parseIso8601("2026-08-02T16:44:43-05:00", epoch));
    TEST_ASSERT_EQUAL(1785707083L, (long) epoch);
}

void test_parseIso8601_rejects_malformed(void) {
    time_t epoch = -1;
    TEST_ASSERT_FALSE(parseIso8601("not-a-date", epoch));
}

void test_estimateNow_adds_elapsed_uptime(void) {
    time_t now = estimateNow((time_t) 1000, (uint64_t) 5000, (uint64_t) 65000);
    TEST_ASSERT_EQUAL(1060L, (long) now);
}

void test_isStale_false_below_threshold(void) {
    TEST_ASSERT_FALSE(isStale((time_t) (14 * 60), (time_t) 0, 15));
}

void test_isStale_true_at_threshold(void) {
    TEST_ASSERT_TRUE(isStale((time_t) (15 * 60), (time_t) 0, 15));
}

void test_isStale_false_when_fresh(void) {
    TEST_ASSERT_FALSE(isStale((time_t) 0, (time_t) 0, 15));
}

// ── Abfahrten ────────────────────────────────────────────────────────────

void test_departureStyle_first_realtime_is_red(void) {
    TEST_ASSERT_TRUE(departureStyle(true, true, false) == DepartureStyle::RedLive);
}

void test_departureStyle_first_schedule_only_is_black_italic(void) {
    TEST_ASSERT_TRUE(departureStyle(true, false, false) == DepartureStyle::BlackItalic);
}

void test_departureStyle_following_is_black_small(void) {
    TEST_ASSERT_TRUE(departureStyle(false, true, false) == DepartureStyle::BlackSmall);
}

void test_departureStyle_delayed_wins_when_first(void) {
    TEST_ASSERT_TRUE(departureStyle(true, true, true) == DepartureStyle::Inverted);
}

void test_departureStyle_delayed_wins_when_following(void) {
    TEST_ASSERT_TRUE(departureStyle(false, true, true) == DepartureStyle::Inverted);
}

void test_isDepartingNow(void) {
    TEST_ASSERT_TRUE(isDepartingNow(0));
    TEST_ASSERT_TRUE(isDepartingNow(-1));
    TEST_ASSERT_FALSE(isDepartingNow(1));
}

// ── Text ─────────────────────────────────────────────────────────────────

void test_utf8Length_counts_codepoints_not_bytes(void) {
    // A-sz-m-a-y-e-r-g-a-s-s-e = 12 Codepoints, aber 13 Bytes (sz ist 2 Bytes).
    TEST_ASSERT_EQUAL(12, (int) utf8Length("A\xc3\x9fmayergasse"));
}

void test_truncateToWidth_leaves_short_text_unchanged(void) {
    TEST_ASSERT_EQUAL_STRING("Arbeit", truncateToWidth("Arbeit", 400, 20).c_str());
}

void test_truncateToWidth_cuts_at_codepoint_boundary(void) {
    // "Flurschuetzstrasse" (16 Codepoints) mit ue als Multibyte-Zeichen genau
    // an der Schnittstelle -- darf nicht mitten im Zeichen kappen.
    std::string input = "Flurschu\xc3\x9ftzstra\xc3\x9fe"; // "Flurschützstraße"
    std::string result = truncateToWidth(input, 100, 10);
    // maxChars = 100/10 = 10 -> 9 Zeichen + Ellipse.
    TEST_ASSERT_EQUAL_STRING("Flurschu\xc3\x9f\xe2\x80\xa6", result.c_str());
}

void test_truncateToWidth_tiny_budget_returns_ellipsis_only(void) {
    TEST_ASSERT_EQUAL_STRING("\xe2\x80\xa6", truncateToWidth("Arbeit", 5, 10).c_str());
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_parseIso8601_epoch_zero);
    RUN_TEST(test_parseIso8601_positive_offset);
    RUN_TEST(test_parseIso8601_negative_offset);
    RUN_TEST(test_parseIso8601_rejects_malformed);
    RUN_TEST(test_estimateNow_adds_elapsed_uptime);
    RUN_TEST(test_isStale_false_below_threshold);
    RUN_TEST(test_isStale_true_at_threshold);
    RUN_TEST(test_isStale_false_when_fresh);
    RUN_TEST(test_departureStyle_first_realtime_is_red);
    RUN_TEST(test_departureStyle_first_schedule_only_is_black_italic);
    RUN_TEST(test_departureStyle_following_is_black_small);
    RUN_TEST(test_departureStyle_delayed_wins_when_first);
    RUN_TEST(test_departureStyle_delayed_wins_when_following);
    RUN_TEST(test_isDepartingNow);
    RUN_TEST(test_utf8Length_counts_codepoints_not_bytes);
    RUN_TEST(test_truncateToWidth_leaves_short_text_unchanged);
    RUN_TEST(test_truncateToWidth_cuts_at_codepoint_boundary);
    RUN_TEST(test_truncateToWidth_tiny_budget_returns_ellipsis_only);
    return UNITY_END();
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

```bash
cd /Users/erikr/Git/wlmonitor/firmware
pio test -e native
```

Erwartet: Kompilierfehler, `layout.h` fehlt.

- [ ] **Step 3: `layout.h` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/lib/boardlogic/layout.h`:

```cpp
#pragma once
#include <string>
#include <ctime>
#include <cstdint>
#include <cstddef>

// ── Zeit ─────────────────────────────────────────────────────────────────
//
// Der ESP32 hat keine eigene Uhr (kein NTP, siehe Spec §8 "Keine Uhr
// noetig"). Jede erfolgreich geladene Antwort liefert mit "generated" einen
// echten Zeitstempel vom Server; estimateNow() rechnet die seither
// vergangene Laufzeit (RTC-Speicher, ueberlebt Tiefschlaf, siehe main.cpp)
// dazu.

// Parst "YYYY-MM-DDTHH:MM:SS+HH:MM" (PHP date('c')) zu Unix-Epoche (UTC).
// false, wenn die Form nicht passt.
bool parseIso8601(const std::string& iso, time_t& outEpoch);

// anchorGeneratedEpoch: Epoche aus der letzten erfolgreichen Antwort.
// anchorUptimeMs / currentUptimeMs: Millisekunden seit Boot (monoton,
// esp_timer_get_time()/1000) zum Anker- bzw. jetzigen Zeitpunkt.
time_t estimateNow(time_t anchorGeneratedEpoch, uint64_t anchorUptimeMs, uint64_t currentUptimeMs);

bool isStale(time_t estimatedNow, time_t generatedEpoch, int staleMinutes);

// ── Abfahrten ────────────────────────────────────────────────────────────

enum class DepartureStyle { RedLive, BlackItalic, Inverted, BlackSmall };

// isFirst: diese Abfahrt ist die naechste der Zeile (Position 0). delayed
// hat Vorrang vor allem anderen (Spec §7).
DepartureStyle departureStyle(bool isFirst, bool realtime, bool delayed);

// "in" <= 0 heisst "faehrt jetzt" -> als Symbol statt Zahl zeichnen.
bool isDepartingNow(int inMinutes);

// ── Text ─────────────────────────────────────────────────────────────────

// UTF-8-Codepoints (nicht Bytes) -- Umlaute/scharfes S sind mehrere Bytes.
size_t utf8Length(const std::string& s);
std::string utf8Prefix(const std::string& s, size_t codepoints);

// Kuerzt text mit "…", wenn er (ueber avgCharWidthPx geschaetzt) breiter als
// maxWidthPx waere. Naeherung: die echte Pixelbreite braucht
// Adafruit_GFX::getTextBounds() mit einem Font-Objekt (Hardware, siehe
// display.cpp) und wird hier bewusst nicht nachgebildet.
std::string truncateToWidth(const std::string& text, int maxWidthPx, int avgCharWidthPx);
```

- [ ] **Step 4: `layout.cpp` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/lib/boardlogic/layout.cpp`:

```cpp
#include "layout.h"
#include <cstdio>

// ── Zeit ─────────────────────────────────────────────────────────────────

static bool isLeapYear(int y) {
    return (y % 4 == 0 && y % 100 != 0) || (y % 400 == 0);
}

static long daysSinceEpoch(int year, int month, int day) {
    static const int cumDays[] = {0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334};
    long days = 0;
    for (int y = 1970; y < year; y++) days += isLeapYear(y) ? 366 : 365;
    days += cumDays[month - 1];
    if (month > 2 && isLeapYear(year)) days += 1;
    days += (day - 1);
    return days;
}

bool parseIso8601(const std::string& iso, time_t& outEpoch) {
    int year, month, day, hour, minute, second, offHour = 0, offMinute = 0;
    char signChar = '+';

    int n = std::sscanf(iso.c_str(), "%d-%d-%dT%d:%d:%d%c%d:%d",
                         &year, &month, &day, &hour, &minute, &second,
                         &signChar, &offHour, &offMinute);
    if (n != 9) return false;

    int offSign = (signChar == '-') ? -1 : 1;
    long days = daysSinceEpoch(year, month, day);
    long localSeconds = days * 86400L + hour * 3600L + minute * 60L + second;
    long offsetSeconds = offSign * (offHour * 3600L + offMinute * 60L);
    outEpoch = (time_t) (localSeconds - offsetSeconds);
    return true;
}

time_t estimateNow(time_t anchorGeneratedEpoch, uint64_t anchorUptimeMs, uint64_t currentUptimeMs) {
    uint64_t elapsedMs = currentUptimeMs - anchorUptimeMs;
    return anchorGeneratedEpoch + (time_t) (elapsedMs / 1000);
}

bool isStale(time_t estimatedNow, time_t generatedEpoch, int staleMinutes) {
    return (estimatedNow - generatedEpoch) >= (staleMinutes * 60);
}

// ── Abfahrten ────────────────────────────────────────────────────────────

DepartureStyle departureStyle(bool isFirst, bool realtime, bool delayed) {
    if (delayed) return DepartureStyle::Inverted;
    if (isFirst) return realtime ? DepartureStyle::RedLive : DepartureStyle::BlackItalic;
    return DepartureStyle::BlackSmall;
}

bool isDepartingNow(int inMinutes) {
    return inMinutes <= 0;
}

// ── Text ─────────────────────────────────────────────────────────────────

size_t utf8Length(const std::string& s) {
    size_t count = 0;
    for (unsigned char c : s) {
        if ((c & 0xC0) != 0x80) count++;   // kein Fortsetzungsbyte
    }
    return count;
}

std::string utf8Prefix(const std::string& s, size_t codepoints) {
    size_t seen = 0;
    for (size_t i = 0; i < s.size(); i++) {
        unsigned char c = (unsigned char) s[i];
        if ((c & 0xC0) != 0x80) {
            if (seen == codepoints) return s.substr(0, i);
            seen++;
        }
    }
    return s;
}

std::string truncateToWidth(const std::string& text, int maxWidthPx, int avgCharWidthPx) {
    size_t len = utf8Length(text);
    if ((int) (len * (size_t) avgCharWidthPx) <= maxWidthPx) return text;

    int maxChars = maxWidthPx / avgCharWidthPx;
    if (maxChars <= 1) return "\xe2\x80\xa6";   // "…"
    return utf8Prefix(text, (size_t) (maxChars - 1)) + "\xe2\x80\xa6";
}
```

- [ ] **Step 5: Test laufen lassen, Erfolg bestätigen**

```bash
cd /Users/erikr/Git/wlmonitor/firmware
pio test -e native
```

Erwartet: alle 18 Tests (7 aus Task 3 + 18 hier — insgesamt läuft die ganze
`native`-Suite) PASSED. Kein Test aus Task 3 darf durch diesen Task rot
werden.

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add epaper-monitor/lib/boardlogic/layout.h epaper-monitor/lib/boardlogic/layout.cpp epaper-monitor/test/test_layout/test_layout.cpp
git commit -m "$(cat <<'EOF'
feat(firmware): layout - Farb-/Stilregeln, Kuerzung, Uhr ohne NTP

Co-Authored-By: Claude <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Gpt6tdgtR4AFNeHFTLS53U
EOF
)"
```

---

## Task 5: `error_state` — Fehlerklassifikation über mehrere Zyklen

Reine Zustandsübergangsfunktion für Spec §9: aus dem Ergebnis eines Zyklus
und dem Fehlerzähler des vorigen Zyklus wird der neue Zähler und die
anzuzeigende Kopfzeilen-Meldung.

**Files:**
- Create: `epaper-monitor/lib/boardlogic/error_state.h`
- Create: `epaper-monitor/lib/boardlogic/error_state.cpp`
- Create: `epaper-monitor/test/test_error_state/test_error_state.cpp`

**Interfaces:**
- Consumes: nichts (unabhängig von `board_model`/`layout`).
- Produces: `enum class FetchOutcome { Success, NetworkUnavailable,
  Unauthorized, UnreadableResponse }`, `enum class ErrorBanner { None,
  Offline, TokenInvalid }`, `struct ErrorState { int consecutiveFailures = 0;
  ErrorBanner banner = ErrorBanner::None; }`, `ErrorState
  nextErrorState(FetchOutcome outcome, int previousConsecutiveFailures)`.
  Werden ab Task 8 (display, `ErrorBanner`) und Task 9 (main.cpp) verwendet.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/test/test_error_state/test_error_state.cpp`:

```cpp
#include <unity.h>
#include "error_state.h"

void test_success_resets_counter_and_banner(void) {
    ErrorState st = nextErrorState(FetchOutcome::Success, 5);
    TEST_ASSERT_EQUAL(0, st.consecutiveFailures);
    TEST_ASSERT_TRUE(st.banner == ErrorBanner::None);
}

void test_unauthorized_shows_immediately_on_first_failure(void) {
    ErrorState st = nextErrorState(FetchOutcome::Unauthorized, 0);
    TEST_ASSERT_EQUAL(1, st.consecutiveFailures);
    TEST_ASSERT_TRUE(st.banner == ErrorBanner::TokenInvalid);
}

void test_unauthorized_stays_shown_after_a_streak(void) {
    ErrorState st = nextErrorState(FetchOutcome::Unauthorized, 5);
    TEST_ASSERT_EQUAL(6, st.consecutiveFailures);
    TEST_ASSERT_TRUE(st.banner == ErrorBanner::TokenInvalid);
}

void test_network_unavailable_stays_silent_below_threshold(void) {
    ErrorState st1 = nextErrorState(FetchOutcome::NetworkUnavailable, 0);
    TEST_ASSERT_EQUAL(1, st1.consecutiveFailures);
    TEST_ASSERT_TRUE(st1.banner == ErrorBanner::None);

    ErrorState st2 = nextErrorState(FetchOutcome::NetworkUnavailable, 1);
    TEST_ASSERT_EQUAL(2, st2.consecutiveFailures);
    TEST_ASSERT_TRUE(st2.banner == ErrorBanner::None);
}

void test_network_unavailable_shows_offline_at_third_failure(void) {
    ErrorState st = nextErrorState(FetchOutcome::NetworkUnavailable, 2);
    TEST_ASSERT_EQUAL(3, st.consecutiveFailures);
    TEST_ASSERT_TRUE(st.banner == ErrorBanner::Offline);
}

void test_network_unavailable_stays_offline_after_threshold(void) {
    ErrorState st = nextErrorState(FetchOutcome::NetworkUnavailable, 6);
    TEST_ASSERT_EQUAL(7, st.consecutiveFailures);
    TEST_ASSERT_TRUE(st.banner == ErrorBanner::Offline);
}

void test_unreadable_response_behaves_like_network_unavailable(void) {
    ErrorState st = nextErrorState(FetchOutcome::UnreadableResponse, 2);
    TEST_ASSERT_EQUAL(3, st.consecutiveFailures);
    TEST_ASSERT_TRUE(st.banner == ErrorBanner::Offline);
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_success_resets_counter_and_banner);
    RUN_TEST(test_unauthorized_shows_immediately_on_first_failure);
    RUN_TEST(test_unauthorized_stays_shown_after_a_streak);
    RUN_TEST(test_network_unavailable_stays_silent_below_threshold);
    RUN_TEST(test_network_unavailable_shows_offline_at_third_failure);
    RUN_TEST(test_network_unavailable_stays_offline_after_threshold);
    RUN_TEST(test_unreadable_response_behaves_like_network_unavailable);
    return UNITY_END();
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

```bash
cd /Users/erikr/Git/wlmonitor/firmware
pio test -e native
```

Erwartet: Kompilierfehler, `error_state.h` fehlt.

- [ ] **Step 3: `error_state.h` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/lib/boardlogic/error_state.h`:

```cpp
#pragma once

enum class FetchOutcome {
    Success,
    NetworkUnavailable,   // WLAN-Verbindung fehlgeschlagen, HTTP-Zeitueberschreitung, oder 503
    Unauthorized,          // HTTP 401 bzw. Antwortkoerper {"error":"unauthorized"}
    UnreadableResponse,    // Antwort kam an, liess sich aber nicht als BoardResponse parsen
};

enum class ErrorBanner {
    None,
    Offline,        // "offline seit HH:MM"
    TokenInvalid,   // "Token ungueltig"
};

struct ErrorState {
    int consecutiveFailures = 0;
    ErrorBanner banner = ErrorBanner::None;
};

// Reiner Zustandsuebergang: aus dem Ergebnis EINES Zyklus und dem Zaehler
// des vorigen Zyklus wird der naechste Zaehler und die anzuzeigende
// Kopfzeile (Spec §9).
ErrorState nextErrorState(FetchOutcome outcome, int previousConsecutiveFailures);
```

- [ ] **Step 4: `error_state.cpp` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/lib/boardlogic/error_state.cpp`:

```cpp
#include "error_state.h"

static const int OFFLINE_THRESHOLD = 3;

ErrorState nextErrorState(FetchOutcome outcome, int previousConsecutiveFailures) {
    ErrorState state;

    if (outcome == FetchOutcome::Success) {
        state.consecutiveFailures = 0;
        state.banner = ErrorBanner::None;
        return state;
    }

    if (outcome == FetchOutcome::Unauthorized) {
        // Behebt sich nicht von selbst -> sofort anzeigen, unabhaengig vom Zaehler.
        state.consecutiveFailures = previousConsecutiveFailures + 1;
        state.banner = ErrorBanner::TokenInvalid;
        return state;
    }

    // NetworkUnavailable und UnreadableResponse verhalten sich wie ein
    // WLAN-Ausfall: Bild stehen lassen, erst nach 3 Fehlversuchen melden.
    state.consecutiveFailures = previousConsecutiveFailures + 1;
    state.banner = (state.consecutiveFailures >= OFFLINE_THRESHOLD)
        ? ErrorBanner::Offline
        : ErrorBanner::None;
    return state;
}
```

- [ ] **Step 5: Test laufen lassen, Erfolg bestätigen**

```bash
cd /Users/erikr/Git/wlmonitor/firmware
pio test -e native
```

Erwartet: alle Tests aus Task 3, 4 und 5 zusammen PASSED (32 insgesamt).

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add epaper-monitor/lib/boardlogic/error_state.h epaper-monitor/lib/boardlogic/error_state.cpp epaper-monitor/test/test_error_state/test_error_state.cpp
git commit -m "$(cat <<'EOF'
feat(firmware): error_state - Offline-/Token-Erkennung ueber mehrere Zyklen

Co-Authored-By: Claude <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Gpt6tdgtR4AFNeHFTLS53U
EOF
)"
```

---

## Task 6: Logo konvertieren

Das Wiener-Linien-Logo (SVG, von Erik verlinkt) als zweifarbiges
(Schwarz/Rot) Bitmap-Paar für `GxEPD2::drawBitmap()`. Die Bit-Pack- und
Farbklassifikationslogik ist pur und wird mit einem synthetischen
Test-Bild geprüft — unabhängig davon, ob das echte Logo gerade erreichbar
ist.

**Files:**
- Create: `epaper-monitor/tools/convert_logo.py`
- Create: `epaper-monitor/tools/test_convert_logo.py`
- Create: `epaper-monitor/src/logo.h` (generiert, aber committet — das Zielgerät
  soll keine SVG-Toolchain brauchen)

**Interfaces:**
- Consumes: nichts.
- Produces: `epaper-monitor/src/logo.h` mit `#define LOGO_WIDTH`, `#define
  LOGO_HEIGHT`, `const unsigned char LOGO_BLACK[] PROGMEM`, `const unsigned
  char LOGO_RED[] PROGMEM` — 1-Bit-pro-Pixel, zeilenweise auf Byte-Grenzen
  aufgefüllt, MSB zuerst (Standardform für `Adafruit_GFX::drawBitmap`).
  Verwendet ab Task 8 (display).

- [ ] **Step 1: librsvg installieren**

```bash
brew install librsvg
rsvg-convert --version
```

Erwartet: eine Versionsnummer.

- [ ] **Step 2: Fehlschlagenden Test schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/tools/test_convert_logo.py`:

```python
import unittest
from convert_logo import nearest, classify_pixel, pack_planes, RED, BLACK_ISH

CANDIDATES = {"white": (255, 255, 255), "red": RED, "black": BLACK_ISH}


class TestNearest(unittest.TestCase):
    def test_pure_red_classifies_as_red(self):
        self.assertEqual(nearest(RED, CANDIDATES), "red")

    def test_pure_black_classifies_as_black(self):
        self.assertEqual(nearest(BLACK_ISH, CANDIDATES), "black")

    def test_white_classifies_as_white(self):
        self.assertEqual(nearest((255, 255, 255), CANDIDATES), "white")


class TestClassifyPixel(unittest.TestCase):
    def test_transparent_pixel_is_white(self):
        self.assertEqual(classify_pixel((0, 0, 0, 0), CANDIDATES), "white")

    def test_opaque_red_is_red(self):
        self.assertEqual(classify_pixel((*RED, 255), CANDIDATES), "red")


class TestPackPlanes(unittest.TestCase):
    def test_2x1_red_then_black(self):
        pixels = [(*RED, 255), (*BLACK_ISH, 255)]
        black, red = pack_planes(pixels, 2, 1, CANDIDATES)
        # Ein Byte pro Zeile bei Breite 2. Pixel 0 = MSB (0x80), Pixel 1 = 0x40.
        self.assertEqual(red[0], 0x80)
        self.assertEqual(black[0], 0x40)

    def test_row_padding_to_byte_boundary(self):
        # Breite 9 -> 2 Bytes pro Zeile (9 Bit runden auf 16 auf).
        pixels = [(255, 255, 255, 255)] * 9
        black, red = pack_planes(pixels, 9, 1, CANDIDATES)
        self.assertEqual(len(black), 2)
        self.assertEqual(len(red), 2)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 3: Test laufen lassen, Fehlschlag bestätigen**

```bash
cd /Users/erikr/Git/wlmonitor/epaper-monitor/tools
python3 -m unittest test_convert_logo.py
```

Erwartet: `ModuleNotFoundError: No module named 'convert_logo'`.

- [ ] **Step 4: `convert_logo.py` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/tools/convert_logo.py`:

```python
#!/usr/bin/env python3
"""Wandelt eine gerasterte Wiener-Linien-Logo-PNG in ein zweifarbiges
(Schwarz/Rot) Bitmap-Arraypaar fuer GxEPD2::drawBitmap() um.

Nutzung:
    rsvg-convert -w 960 -h 224 logo.svg -o logo.png
    python3 convert_logo.py logo.png ../src/logo.h
"""
import sys
from PIL import Image

TARGET_W, TARGET_H = 240, 56
# Aus der Spec (§7): Logo-Farben #e3000f (rot) / #240c4b (schwarz gerendert).
RED = (0xe3, 0x00, 0x0f)
BLACK_ISH = (0x24, 0x0c, 0x4b)


def nearest(rgb, candidates):
    r, g, b = rgb
    best, best_dist = None, None
    for name, (cr, cg, cb) in candidates.items():
        d = (r - cr) ** 2 + (g - cg) ** 2 + (b - cb) ** 2
        if best_dist is None or d < best_dist:
            best, best_dist = name, d
    return best


def classify_pixel(rgba, candidates):
    r, g, b, a = rgba
    if a < 128:
        return "white"
    return nearest((r, g, b), candidates)


def pack_planes(pixels, width, height, candidates):
    """pixels: Liste von RGBA-Tupeln, zeilenweise, Laenge == width*height."""
    bytes_per_row = (width + 7) // 8
    black_plane = bytearray(bytes_per_row * height)
    red_plane = bytearray(bytes_per_row * height)

    for y in range(height):
        for x in range(width):
            color = classify_pixel(pixels[y * width + x], candidates)
            byte_index = y * bytes_per_row + (x // 8)
            bit = 0x80 >> (x % 8)
            if color == "black":
                black_plane[byte_index] |= bit
            elif color == "red":
                red_plane[byte_index] |= bit

    return bytes(black_plane), bytes(red_plane)


def emit_array(name, data):
    lines = [f"const unsigned char {name}[] PROGMEM = {{"]
    for i in range(0, len(data), 16):
        row = ", ".join(f"0x{b:02x}" for b in data[i:i + 16])
        lines.append(f"    {row},")
    lines.append("};")
    return "\n".join(lines)


def main():
    if len(sys.argv) != 3:
        print("Usage: convert_logo.py <input.png> <output.h>", file=sys.stderr)
        sys.exit(1)

    img = Image.open(sys.argv[1]).convert("RGBA")
    img = img.resize((TARGET_W, TARGET_H), Image.LANCZOS)
    pixels = list(img.getdata())

    candidates = {"white": (255, 255, 255), "red": RED, "black": BLACK_ISH}
    black_plane, red_plane = pack_planes(pixels, TARGET_W, TARGET_H, candidates)

    with open(sys.argv[2], "w") as f:
        f.write("#pragma once\n")
        f.write("#include <pgmspace.h>\n\n")
        f.write("// Erzeugt aus dem Wiener-Linien-Logo per tools/convert_logo.py.\n")
        f.write(f"#define LOGO_WIDTH {TARGET_W}\n")
        f.write(f"#define LOGO_HEIGHT {TARGET_H}\n\n")
        f.write(emit_array("LOGO_BLACK", black_plane))
        f.write("\n\n")
        f.write(emit_array("LOGO_RED", red_plane))
        f.write("\n")


if __name__ == "__main__":
    main()
```

- [ ] **Step 5: Test laufen lassen, Erfolg bestätigen**

```bash
cd /Users/erikr/Git/wlmonitor/epaper-monitor/tools
python3 -m unittest test_convert_logo.py
```

Erwartet: alle 5 Tests OK.

- [ ] **Step 6: Echtes Logo herunterladen und konvertieren**

```bash
curl -sL "https://upload.wikimedia.org/wikipedia/commons/5/59/Wiener_Linien_logo.svg" -o /tmp/wl-logo.svg
rsvg-convert -w 960 -h 224 /tmp/wl-logo.svg -o /tmp/wl-logo.png
cd /Users/erikr/Git/wlmonitor/epaper-monitor/tools
python3 convert_logo.py /tmp/wl-logo.png ../src/logo.h
```

- [ ] **Step 7: Erzeugte Datei prüfen**

```bash
head -8 /Users/erikr/Git/wlmonitor/epaper-monitor/src/logo.h
grep -c "0x" /Users/erikr/Git/wlmonitor/epaper-monitor/src/logo.h
```

Erwartet: `#define LOGO_WIDTH 240` / `#define LOGO_HEIGHT 56` in den ersten
Zeilen, und eine dreistellige Zahl an Zeilen mit `0x`-Bytes (nicht 0 — sonst
ist die Datei leer geblieben).

- [ ] **Step 8: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add epaper-monitor/tools/convert_logo.py epaper-monitor/tools/test_convert_logo.py epaper-monitor/src/logo.h
git commit -m "$(cat <<'EOF'
feat(firmware): Wiener-Linien-Logo als Schwarz/Rot-Bitmap fuer GxEPD2

Co-Authored-By: Claude <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Gpt6tdgtR4AFNeHFTLS53U
EOF
)"
```

---

## Task 7: `board_client` — HTTP-Abfrage von `board.php`

Hardware-Code (WiFiClient/HTTPClient aus dem ESP32-Arduino-Core) — nicht
nativ testbar. Verifikation: Cross-Compile für `esp32dev` (kein Gerät
nötig, prüft aber echte API-Nutzung/Includes/Linker).

**Files:**
- Create: `epaper-monitor/src/board_client.h`
- Create: `epaper-monitor/src/board_client.cpp`

**Interfaces:**
- Consumes: nichts direkt (liefert rohen JSON-Text als `std::string`, den
  Task 3s `parseBoardResponse` weiterverarbeitet — Verdrahtung in Task 9).
- Produces: `enum class BoardFetchResult { Ok, Unauthorized, Unavailable,
  ServerError }`, `BoardFetchResult fetchBoard(const char* host, uint16_t
  port, const char* favIds, const char* token, uint32_t timeoutMs,
  std::string& outBody)`. Verwendet ab Task 9 (main.cpp).

- [ ] **Step 1: `board_client.h` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/src/board_client.h`:

```cpp
#pragma once
#include <string>
#include <cstdint>

enum class BoardFetchResult {
    Ok,                 // HTTP 200, Koerper in outBody
    Unauthorized,       // HTTP 401
    Unavailable,        // HTTP 503, Verbindungsfehler oder Zeitueberschreitung
    ServerError,        // HTTP 500 oder sonstiger unerwarteter Status
};

// Fuehrt GET http://host:port/board.php?fav=favIds aus, mit
// "Authorization: Bearer <token>". timeoutMs begrenzt Verbindungsaufbau UND
// Antwortwartezeit.
BoardFetchResult fetchBoard(const char* host, uint16_t port, const char* favIds,
                             const char* token, uint32_t timeoutMs, std::string& outBody);
```

- [ ] **Step 2: `board_client.cpp` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/src/board_client.cpp`:

```cpp
#include "board_client.h"
#include <WiFiClient.h>
#include <HTTPClient.h>

BoardFetchResult fetchBoard(const char* host, uint16_t port, const char* favIds,
                             const char* token, uint32_t timeoutMs, std::string& outBody) {
    WiFiClient client;
    HTTPClient http;
    http.setConnectTimeout(timeoutMs);
    http.setTimeout(timeoutMs);

    String url = String("http://") + host + ":" + port + "/board.php?fav=" + favIds;
    if (!http.begin(client, url)) {
        return BoardFetchResult::Unavailable;
    }
    http.addHeader("Authorization", String("Bearer ") + token);

    int status = http.GET();
    if (status <= 0) {
        // Negative HTTPClient-Codes: Verbindungsfehler oder Zeitueberschreitung.
        http.end();
        return BoardFetchResult::Unavailable;
    }

    outBody = http.getString().c_str();
    http.end();

    if (status == 200) return BoardFetchResult::Ok;
    if (status == 401) return BoardFetchResult::Unauthorized;
    if (status == 503) return BoardFetchResult::Unavailable;
    return BoardFetchResult::ServerError;
}
```

- [ ] **Step 3: Cross-Compile verifizieren**

```bash
cd /Users/erikr/Git/wlmonitor/firmware
pio run -e esp32dev
```

Erwartet: `SUCCESS` — das prüft nur, dass der Code für den ESP32 übersetzt
(Includes, API-Nutzung, Linker), **nicht** das Laufzeitverhalten. Ein
Netzwerkaufruf lässt sich ohne Gerät nicht sinnvoll isoliert testen; die
echte Verifikation läuft in Task 9 gebündelt mit `main.cpp` auf echter
Hardware.

- [ ] **Step 4: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add epaper-monitor/src/board_client.h epaper-monitor/src/board_client.cpp
git commit -m "$(cat <<'EOF'
feat(firmware): board_client - HTTP-GET gegen den LAN-Endpunkt

Co-Authored-By: Claude <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Gpt6tdgtR4AFNeHFTLS53U
EOF
)"
```

---

## Task 8: `display` — Rendering auf dem e-Paper-Panel

Hardware-Code (GxEPD2 + Adafruit GFX) — nicht nativ testbar. Verifikation:
Cross-Compile für `esp32dev`. Nutzt ausschließlich die reine Logik aus
Task 3–6 für alle Entscheidungen (Farbe, Kürzung, Stale-Erkennung) — hier
steht nur noch das Zeichnen.

**Files:**
- Create: `epaper-monitor/src/panel_select.h`
- Create: `epaper-monitor/src/display.h`
- Create: `epaper-monitor/src/display.cpp`

**Interfaces:**
- Consumes: `BoardResponse`/`Favorite`/`Station`/`Line`/`Departure` (Task 3),
  `departureStyle`/`isDepartingNow`/`truncateToWidth`/`isStale` (Task 4),
  `ErrorBanner` (Task 5), `LOGO_BLACK`/`LOGO_RED`/`LOGO_WIDTH`/`LOGO_HEIGHT`
  (Task 6).
- Produces: `void initDisplay()`, `void renderBoard(const BoardResponse&
  board, time_t generatedEpoch, time_t estimatedNow, ErrorBanner banner)`.
  Verwendet ab Task 9 (main.cpp).

- [ ] **Step 1: Panel-Treiber bestätigen**

**Wichtig, vor dem Weiterarbeiten:** Es gibt mehrere GxEPD2-Treiberklassen
für ähnlich benannte Waveshare-7,5″-B-Panel-Revisionen (unterschiedliche
Controller). Auf der Waveshare-Wiki-Seite des eigenen Moduls (oder der
Beschriftung auf der Panel-Rückseite) nachsehen, welche GxEPD2-Klasse
dokumentiert ist. Dieser Plan geht von `GxEPD2_750c_Z08` aus (SSD1677,
800×480, B-Variante) — falls das Modul einen anderen Controller hat, in
Schritt 2 die Klasse tauschen.

`/Users/erikr/Git/wlmonitor/epaper-monitor/src/panel_select.h`:

```cpp
#pragma once
// Waveshare 7.5" e-Paper HAT (B), 800x480, schwarz/weiss/rot.
//
// WICHTIG: Es gibt mehrere GxEPD2-Treiberklassen fuer aehnlich benannte
// Waveshare-7.5"-B-Panels (unterschiedliche Controller-Revisionen). Vor dem
// ersten Flashen gegen die Waveshare-Wiki-Seite des eigenen Moduls bzw. die
// Beschriftung auf der Ruecktseite des Panels pruefen und bei Abweichung
// hier tauschen (siehe GxEPD2-Bibliothek, Ordner src/, Dateien
// GxEPD2_750c_*.h fuer die unterstuetzten Panels).
#include <GxEPD2_750c_Z08.h>
#define PANEL_DRIVER GxEPD2_750c_Z08

// Treiberboard-Pins (Waveshare e-Paper ESP32 Driver Board, fest verdrahtet).
// SCLK 13 / DIN 14 laufen ueber das Standard-SPI (VSPI) des ESP32.
#define EPD_CS    15
#define EPD_DC    27
#define EPD_RST   26
#define EPD_BUSY  25
```

- [ ] **Step 2: `display.h` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/src/display.h`:

```cpp
#pragma once
#include "board_model.h"
#include "error_state.h"
#include <ctime>

void initDisplay();

// Voller Refresh (kein Partial Refresh, Spec §3). generatedEpoch/estimatedNow
// steuern die "Stand HH:MM"-Invertierung (isStale(), Spec §9); banner
// ueberlagert die Kopfzeile bei Verbindungs-/Token-Problemen.
void renderBoard(const BoardResponse& board, time_t generatedEpoch, time_t estimatedNow, ErrorBanner banner);
```

- [ ] **Step 3: `display.cpp` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/src/display.cpp`:

```cpp
#include "display.h"
#include "layout.h"
#include "logo.h"
#include "panel_select.h"
#include <GxEPD2_3C.h>
#include <Fonts/FreeSans9pt7b.h>
#include <Fonts/FreeSansBold9pt7b.h>
#include <Fonts/FreeSansBold24pt7b.h>
#include <Fonts/FreeSansBoldOblique24pt7b.h>

static GxEPD2_3C<PANEL_DRIVER, PANEL_DRIVER::HEIGHT / 2> epd(PANEL_DRIVER(EPD_CS, EPD_DC, EPD_RST, EPD_BUSY));

static const int16_t SCREEN_W = 800;
static const int16_t SCREEN_H = 480;
static const int16_t HEADER_H = 44;
static const int16_t COLUMN_PAD = 12;
static const int16_t AVG_CHAR_WIDTH_PX = 11;   // grobe Naeherung, siehe layout.h::truncateToWidth

void initDisplay() {
    epd.init(115200);
    epd.setRotation(0);
}

static void drawHeader(time_t generatedEpoch, time_t estimatedNow, ErrorBanner banner) {
    epd.drawBitmap(16, (HEADER_H - LOGO_HEIGHT) / 2, LOGO_BLACK, LOGO_WIDTH, LOGO_HEIGHT, GxEPD_BLACK);
    epd.drawBitmap(16, (HEADER_H - LOGO_HEIGHT) / 2, LOGO_RED, LOGO_WIDTH, LOGO_HEIGHT, GxEPD_RED);

    struct tm t;
    localtime_r(&generatedEpoch, &t);
    char stamp[16];
    snprintf(stamp, sizeof(stamp), "Stand %02d:%02d", t.tm_hour, t.tm_min);

    bool stale = isStale(estimatedNow, generatedEpoch, 15);
    epd.setFont(&FreeSansBold9pt7b);
    int16_t bx, by;
    uint16_t bw, bh;
    epd.getTextBounds(stamp, 0, 0, &bx, &by, &bw, &bh);
    int16_t sx = SCREEN_W - bw - 16;
    int16_t sy = HEADER_H / 2 + bh / 2;

    if (stale) {
        epd.fillRect(sx - 6, sy - bh - 4, bw + 12, bh + 10, GxEPD_RED);
        epd.setTextColor(GxEPD_WHITE);
    } else {
        epd.setTextColor(GxEPD_BLACK);
    }
    epd.setCursor(sx, sy);
    epd.print(stamp);

    if (banner == ErrorBanner::Offline || banner == ErrorBanner::TokenInvalid) {
        const char* msg = (banner == ErrorBanner::Offline) ? "offline" : "Token ungueltig";
        epd.setFont(&FreeSans9pt7b);
        epd.setTextColor(GxEPD_RED);
        epd.setCursor(SCREEN_W / 2 - 40, HEADER_H - 6);
        epd.print(msg);
    }
}

static void drawDeparture(int16_t x, int16_t y, const Departure& dep, DepartureStyle style) {
    bool big = (style == DepartureStyle::RedLive || style == DepartureStyle::BlackItalic);
    epd.setFont(big
        ? (style == DepartureStyle::BlackItalic
            ? (const GFXfont*) &FreeSansBoldOblique24pt7b
            : (const GFXfont*) &FreeSansBold24pt7b)
        : (const GFXfont*) &FreeSans9pt7b);

    uint16_t color = (style == DepartureStyle::RedLive) ? GxEPD_RED : GxEPD_BLACK;

    if (style == DepartureStyle::Inverted) {
        char probe[8];
        snprintf(probe, sizeof(probe), "%d", dep.inMinutes);
        int16_t bx, by;
        uint16_t bw, bh;
        epd.getTextBounds(probe, x, y, &bx, &by, &bw, &bh);
        epd.fillRect(bx - 3, by - 3, bw + 6, bh + 6, GxEPD_RED);
        color = GxEPD_WHITE;
    }

    epd.setTextColor(color);
    epd.setCursor(x, y);
    if (isDepartingNow(dep.inMinutes)) {
        epd.print("*");
    } else {
        epd.print(dep.inMinutes);
    }
}

static void drawColumn(int16_t colX, const Favorite& fav) {
    int16_t y = HEADER_H + 24;
    const int16_t colWidth = SCREEN_W / 2 - 16 - COLUMN_PAD;

    epd.setFont(&FreeSansBold9pt7b);
    epd.setTextColor(GxEPD_BLACK);
    epd.setCursor(colX, y);
    String title = fav.title.c_str();
    title.toUpperCase();
    epd.print(title);
    y += 26;

    for (const Station& st : fav.stations) {
        epd.setFont(&FreeSans9pt7b);
        epd.setTextColor(GxEPD_BLACK);
        epd.setCursor(colX, y);
        epd.print(truncateToWidth(st.name, colWidth, AVG_CHAR_WIDTH_PX).c_str());
        y += 22;

        for (const Line& ln : st.lines) {
            epd.setFont(&FreeSansBold9pt7b);
            epd.setTextColor(GxEPD_BLACK);
            epd.setCursor(colX, y);
            char prefix[24];
            snprintf(prefix, sizeof(prefix), "%s %s ", ln.name.c_str(), ln.platform.c_str());
            epd.print(prefix);
            epd.print(truncateToWidth(ln.towards, colWidth - 90, AVG_CHAR_WIDTH_PX).c_str());
            y += 20;

            int16_t depX = colX;
            for (size_t i = 0; i < ln.departures.size(); i++) {
                const Departure& dep = ln.departures[i];
                DepartureStyle style = departureStyle(i == 0, ln.realtime, dep.delayed);
                drawDeparture(depX, y, dep, style);
                bool big = (style == DepartureStyle::RedLive || style == DepartureStyle::BlackItalic);
                depX += big ? 60 : 36;
            }
            if (!ln.departures.empty()) y += 30;
        }
    }
}

void renderBoard(const BoardResponse& board, time_t generatedEpoch, time_t estimatedNow, ErrorBanner banner) {
    epd.setFullWindow();
    epd.firstPage();
    do {
        epd.fillScreen(GxEPD_WHITE);
        drawHeader(generatedEpoch, estimatedNow, banner);
        epd.drawFastVLine(SCREEN_W / 2, HEADER_H, SCREEN_H - HEADER_H, GxEPD_BLACK);

        if (board.favorites.size() > 0) drawColumn(16, board.favorites[0]);
        if (board.favorites.size() > 1) drawColumn(SCREEN_W / 2 + 16, board.favorites[1]);
    } while (epd.nextPage());
}
```

- [ ] **Step 4: Cross-Compile verifizieren**

```bash
cd /Users/erikr/Git/wlmonitor/firmware
pio run -e esp32dev
```

Erwartet: `SUCCESS`. Prüft Includes/API-Nutzung/Linker (GxEPD2, Adafruit
GFX, Fonts, `logo.h` aus Task 6) — **nicht** das tatsächliche Bild auf dem
Panel. Exakte Pixelpositionen/Abstände sind ein erster Entwurf und werden
in Task 9 am echten Gerät visuell nachjustiert, falls nötig.

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add epaper-monitor/src/panel_select.h epaper-monitor/src/display.h epaper-monitor/src/display.cpp
git commit -m "$(cat <<'EOF'
feat(firmware): display - GxEPD2-Rendering der zwei Spalten

Co-Authored-By: Claude <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Gpt6tdgtR4AFNeHFTLS53U
EOF
)"
```

---

## Task 9: `main.cpp` — Ablaufsteuerung, Tiefschlaf, README

Letzter Task: verdrahtet WLAN → HTTP-Abfrage → Parsen → Fehlerklassifikation
→ Zeichnen → Tiefschlaf. Erstes Mal, dass ein komplettes, lauffähiges Abbild
existiert — hier findet die einzige manuelle Hardware-Verifikation des
gesamten Plans statt.

**Files:**
- Create: `epaper-monitor/src/main.cpp`
- Create: `epaper-monitor/README.md`

**Interfaces:**
- Consumes: alles aus Task 2–8 (`config.h`-Konstanten, `fetchBoard`,
  `parseBoardResponse`, `parseIso8601`, `estimateNow`, `nextErrorState`,
  `initDisplay`, `renderBoard`).
- Produces: nichts weiter — Endpunkt des Plans.

- [ ] **Step 1: `main.cpp` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/src/main.cpp`:

```cpp
#include <Arduino.h>
#include <WiFi.h>
#include <esp_sleep.h>
#include <esp_timer.h>
#include "config.h"
#include "board_client.h"
#include "board_model.h"
#include "layout.h"
#include "error_state.h"
#include "display.h"

// Ueberlebt Tiefschlaf (RTC-Speicher). Anker fuer estimateNow() -- siehe
// layout.h und Spec §8 "Keine Uhr noetig": der Zeitstempel kommt aus der
// letzten erfolgreichen Serverantwort, nicht aus einer eigenen RTC/NTP-Uhr.
RTC_DATA_ATTR time_t rtcAnchorGeneratedEpoch = 0;
RTC_DATA_ATTR uint64_t rtcAnchorUptimeMs = 0;
RTC_DATA_ATTR int rtcConsecutiveFailures = 0;

static const uint32_t WIFI_TIMEOUT_MS = 15000;
static const uint32_t HTTP_TIMEOUT_MS = 8000;

static uint64_t uptimeMs() {
    return (uint64_t) (esp_timer_get_time() / 1000);
}

static bool connectWifi() {
    WiFi.mode(WIFI_STA);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    uint32_t start = millis();
    while (WiFi.status() != WL_CONNECTED) {
        if (millis() - start > WIFI_TIMEOUT_MS) return false;
        delay(200);
    }
    return true;
}

static void goToSleep() {
    WiFi.disconnect(true);
    esp_sleep_enable_timer_wakeup((uint64_t) POLL_INTERVAL_SEC * 1000000ULL);
    esp_deep_sleep_start();
}

void setup() {
    uint64_t now = uptimeMs();
    time_t estimatedNow = (rtcAnchorGeneratedEpoch == 0)
        ? 0
        : estimateNow(rtcAnchorGeneratedEpoch, rtcAnchorUptimeMs, now);

    initDisplay();

    if (!connectWifi()) {
        ErrorState st = nextErrorState(FetchOutcome::NetworkUnavailable, rtcConsecutiveFailures);
        rtcConsecutiveFailures = st.consecutiveFailures;
        if (st.banner != ErrorBanner::None && rtcAnchorGeneratedEpoch != 0) {
            BoardResponse empty;
            renderBoard(empty, rtcAnchorGeneratedEpoch, estimatedNow, st.banner);
        }
        goToSleep();
        return;
    }

    std::string body;
    BoardFetchResult fetch = fetchBoard(BOARD_HOST, BOARD_PORT, BOARD_FAV_IDS, BOARD_TOKEN, HTTP_TIMEOUT_MS, body);

    FetchOutcome outcome;
    BoardResponse board;

    if (fetch == BoardFetchResult::Ok) {
        ParseStatus parseStatus = parseBoardResponse(body, board);
        outcome = (parseStatus == ParseStatus::Ok) ? FetchOutcome::Success : FetchOutcome::UnreadableResponse;
    } else if (fetch == BoardFetchResult::Unauthorized) {
        outcome = FetchOutcome::Unauthorized;
    } else {
        // Unavailable und ServerError: wie WLAN-Ausfall, Spec §9.
        outcome = FetchOutcome::NetworkUnavailable;
    }

    ErrorState st = nextErrorState(outcome, rtcConsecutiveFailures);
    rtcConsecutiveFailures = st.consecutiveFailures;

    if (outcome == FetchOutcome::Success) {
        time_t generatedEpoch;
        if (parseIso8601(board.generated, generatedEpoch)) {
            rtcAnchorGeneratedEpoch = generatedEpoch;
            rtcAnchorUptimeMs = now;
            estimatedNow = generatedEpoch;
        }
        renderBoard(board, rtcAnchorGeneratedEpoch, estimatedNow, ErrorBanner::None);
    } else if (st.banner != ErrorBanner::None && rtcAnchorGeneratedEpoch != 0) {
        // Nur neu zeichnen, wenn es etwas zu melden gibt (Spec §9: "Bild
        // bleiben lassen" unterhalb der Fehlerschwelle).
        BoardResponse empty;
        renderBoard(empty, rtcAnchorGeneratedEpoch, estimatedNow, st.banner);
    }

    goToSleep();
}

void loop() {
    // Ungenutzt: setup() geht in Tiefschlaf, bevor loop() je aufgerufen wird.
}
```

- [ ] **Step 2: Cross-Compile verifizieren**

```bash
cd /Users/erikr/Git/wlmonitor/firmware
pio run -e esp32dev
```

Erwartet: `SUCCESS`.

- [ ] **Step 3: `README.md` schreiben**

`/Users/erikr/Git/wlmonitor/epaper-monitor/README.md`:

```markdown
# epaper-monitor/

ESP32-Firmware für den E-Paper-Abfahrtsmonitor (Waveshare 7.5″ e-Paper HAT
(B), 800×480). Spec:
`../docs/superpowers/specs/2026-08-01-epaper-abfahrtsmonitor-design.md`.

## Bauen

```bash
brew install platformio librsvg   # einmalig
pio run -e esp32dev               # nur kompilieren
pio test -e native                # reine Logik testen (kein Geraet noetig)
```

## Konfigurieren

```bash
cp include/config.example.h include/config.h
```

Dann in `config.h` eintragen: WLAN-Zugangsdaten, ein frisches API-Token aus
`profil.php` → Abschnitt „API-Token" (auf akadbrain — **nie** ein Token
wiederverwenden, das schon einmal im Klartext aufgetaucht ist, z. B. in
einem Chat), die Favoriten-IDs (aus der URL von `editFavorite.php?id=…`,
Reihenfolge = Spaltenreihenfolge).

`config.h` ist gitignored und geht nie ins Repo.

## Flashen

```bash
pio run -e esp32dev -t upload
pio device monitor
```

## Logo neu erzeugen

Nur nötig, wenn sich das Wiener-Linien-Logo ändert:

```bash
curl -sL "https://upload.wikimedia.org/wikipedia/commons/5/59/Wiener_Linien_logo.svg" -o /tmp/wl-logo.svg
rsvg-convert -w 960 -h 224 /tmp/wl-logo.svg -o /tmp/wl-logo.png
python3 tools/convert_logo.py /tmp/wl-logo.png src/logo.h
```

## Panel-Treiber

`src/panel_select.h` legt die GxEPD2-Treiberklasse fest. Vor dem ersten
Flashen gegen die Waveshare-Wiki-Seite des eigenen Moduls bzw. die
Rückseiten-Beschriftung prüfen — es gibt mehrere GxEPD2-Klassen für ähnlich
benannte 7,5″-B-Panel-Revisionen.
```

- [ ] **Step 4: Manuelle Verifikation auf echter Hardware**

Diese Schritte brauchen das physische Gerät und lassen sich nicht
automatisieren. Konkrete, beobachtbare Erwartungen (kein „prüfen, ob es
funktioniert"):

1. `config.h` mit echten Werten befüllen (Step 3 in diesem Task), flashen,
   Gerät in WLAN-Reichweite mit Strom versorgen.
   **Erwartet:** nach einem Vollbild-Refresh (15–25 s) zeigt das Display
   zwei Spalten „ARBEIT" und „ZUR STADT" mit je Linie, Steig, Ziel und einer
   großen Abfahrtszahl — rot, wenn die Zeile `realtime: true` meldet.
2. In `config.h` ein falsches WLAN-Passwort eintragen, neu flashen.
   **Erwartet:** die ersten beiden Zyklen (0–4 min) ändern das Bild nicht;
   ab dem dritten Fehlversuch (~6 min) erscheint „offline" rechts im Header.
3. `config.h` mit einem ungültigen/widerrufenen Token neu flashen.
   **Erwartet:** bereits im ersten Zyklus erscheint „Token ungültig" — nicht
   erst nach drei Versuchen.
4. `pio device monitor` während mindestens 3 Zyklen laufen lassen.
   **Erwartet:** kein Absturz/Reboot-Loop; jeder Zyklus endet mit dem Log
   des Tiefschlafeintritts.

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add epaper-monitor/src/main.cpp epaper-monitor/README.md
git commit -m "$(cat <<'EOF'
feat(firmware): main.cpp - Ablaufsteuerung, RTC-Zeitanker, Tiefschlaf

Co-Authored-By: Claude <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Gpt6tdgtR4AFNeHFTLS53U
EOF
)"
```
