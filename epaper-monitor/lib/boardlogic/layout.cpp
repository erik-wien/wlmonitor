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
