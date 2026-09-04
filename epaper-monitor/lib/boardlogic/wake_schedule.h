#pragma once
#include <cstdint>

// Automatisches (Timer-)Wecken. Die Werte kommen seit 2026-09-04 VOM SERVER
// (wl_board_settings, Header X-Board-Wake-Interval-Sec / X-Board-Quiet-*-Hour,
// s. web/board.php) -- vorher waren es Konstanten, und jede Aenderung kostete
// Neubau und Flashen am Kabel.
//
// Die Konstanten hier sind nur noch der RUECKFALL: erster Start, oder ein
// Abruf, der nie geglueckt ist. Sie geben das Verhalten wieder, das bis dahin
// fest verdrahtet war (Nutzervorgabe 2026-08-23: "Automatisches kann auf 1x
// pro Stunde ab 06:00 Uhr zurueckgefahren werden. Danach wieder einschlafen.").
static const uint32_t WAKE_SCHEDULE_INTERVAL_SECONDS_DEFAULT = 3600;
static const int WAKE_SCHEDULE_QUIET_START_HOUR_DEFAULT = 0;
static const int WAKE_SCHEDULE_QUIET_END_HOUR_DEFAULT = 6;

// Liegt dieser Zeitpunkt (Sekunden seit Mitternacht) in der Ruhezeit?
// start == end heisst KEINE Ruhezeit. start > end laeuft ueber Mitternacht
// (z.B. 22..6).
bool isWithinQuietHours(uint32_t secondsOfDay, int quietStartHour, int quietEndHour);

// Sekunden ab JETZT bis zum naechsten automatischen Aufwachen.
//
// Regel, in dieser Reihenfolge:
//   1. Steckt JETZT schon in der Ruhezeit -> bis zu deren Ende schlafen.
//   2. Sonst waere der naechste Weckpunkt jetzt+interval. Faellt DER in die
//      Ruhezeit, entfaellt er ersatzlos -> ebenfalls bis zum Ruhezeit-Ende.
//      (Deshalb ist bei stuendlichem Intervall und Ruhezeit 0..6 faktisch
//      schon ab 23:00 Ruhe: der Weckpunkt um 00:00 liegt bereits darin.)
//   3. Sonst schlicht das Intervall.
//
// Reine Funktion: keine Zeitzone, kein NTP -- die aufrufende Seite muss die
// lokale Zeit (Europe/Vienna) bereits als hour/minute/second hereinreichen.
uint32_t secondsUntilNextAutomaticWake(
    int hour, int minute, int second,
    uint32_t intervalSeconds = WAKE_SCHEDULE_INTERVAL_SECONDS_DEFAULT,
    int quietStartHour = WAKE_SCHEDULE_QUIET_START_HOUR_DEFAULT,
    int quietEndHour = WAKE_SCHEDULE_QUIET_END_HOUR_DEFAULT);
