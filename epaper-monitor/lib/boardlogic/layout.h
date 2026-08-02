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
