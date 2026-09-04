#include "wake_schedule.h"

namespace {
const uint32_t DAY_SECONDS = 24 * 3600;

// Sekunden von $from bis zur naechsten vollen Stunde $targetHour (auch ueber
// Mitternacht hinweg). Nie 0 -- ein "Wecken in 0 Sekunden" waere ein
// Endlos-Weckzyklus.
uint32_t secondsUntilHour(uint32_t from, int targetHour) {
    const uint32_t target = (uint32_t) targetHour * 3600;
    return target > from ? target - from : (DAY_SECONDS - from) + target;
}
}  // namespace

bool isWithinQuietHours(uint32_t secondsOfDay, int quietStartHour, int quietEndHour) {
    if (quietStartHour == quietEndHour) return false;  // keine Ruhezeit

    const uint32_t start = (uint32_t) quietStartHour * 3600;
    const uint32_t end   = (uint32_t) quietEndHour * 3600;

    if (start < end) return secondsOfDay >= start && secondsOfDay < end;
    return secondsOfDay >= start || secondsOfDay < end;  // ueber Mitternacht
}

uint32_t secondsUntilNextAutomaticWake(
    int hour, int minute, int second,
    uint32_t intervalSeconds, int quietStartHour, int quietEndHour) {

    // Ein Intervall von 0 waere Dauerwecken -- der Server laesst das nicht zu
    // (board_settings_save_device_timing()), aber dieser Wert kommt ueber das
    // Netz herein und wird hier nicht noch einmal geprueft, sondern gerettet.
    if (intervalSeconds == 0) intervalSeconds = WAKE_SCHEDULE_INTERVAL_SECONDS_DEFAULT;

    const uint32_t now = (uint32_t) hour * 3600 + (uint32_t) minute * 60 + (uint32_t) second;

    if (isWithinQuietHours(now, quietStartHour, quietEndHour)) {
        return secondsUntilHour(now, quietEndHour);
    }

    const uint32_t next = (now + intervalSeconds) % DAY_SECONDS;
    if (isWithinQuietHours(next, quietStartHour, quietEndHour)) {
        return secondsUntilHour(now, quietEndHour);
    }

    return intervalSeconds;
}
