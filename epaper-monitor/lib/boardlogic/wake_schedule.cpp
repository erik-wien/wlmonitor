#include "wake_schedule.h"

uint32_t secondsUntilNextAutomaticWake(int hour, int minute, int second) {
    const uint32_t DAY_SECONDS = 24 * 3600;
    const uint32_t SIX_AM_SECONDS = (uint32_t) WAKE_SCHEDULE_START_HOUR * 3600;
    const uint32_t nowSeconds = (uint32_t) hour * 3600 + (uint32_t) minute * 60 + (uint32_t) second;

    if (nowSeconds < SIX_AM_SECONDS) {
        // Nacht -- direkt bis 06:00 heute warten, kein Zwischenschritt.
        return SIX_AM_SECONDS - nowSeconds;
    }

    if (nowSeconds + WAKE_SCHEDULE_INTERVAL_SECONDS >= DAY_SECONDS) {
        // Die naechste volle Stunde wuerde nach Mitternacht fallen (immer
        // der Fall ab 23:00, da 23:00+3600s=Mitternacht) -- und damit in die
        // Nacht-Zone. Direkt bis 06:00 morgen durchschlafen statt eines
        // einzelnen verlorenen Wake-Ups kurz nach Mitternacht.
        return (DAY_SECONDS - nowSeconds) + SIX_AM_SECONDS;
    }

    return WAKE_SCHEDULE_INTERVAL_SECONDS;
}
