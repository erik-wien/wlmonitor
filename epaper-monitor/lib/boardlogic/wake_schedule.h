#pragma once
#include <cstdint>

// Automatisches (Timer-)Wecken (Nutzervorgabe 2026-08-23): "Automatisches
// kann auf 1x pro Stunde ab 06:00 Uhr zurueckgefahren werden. Danach wieder
// einschlafen." -- die Nacht (00:00-05:59) bleibt komplett still, ab 06:00
// stuendlich EIN Abruf ohne Aktiv-Session (s. main.cpp setup()).
//
// Reine Funktion: keine Zeitzone, kein NTP -- die aufrufende Seite muss die
// lokale Zeit (Europe/Vienna) bereits als hour/minute/second hereinreichen.
static const int WAKE_SCHEDULE_START_HOUR = 6;
static const uint32_t WAKE_SCHEDULE_INTERVAL_SECONDS = 3600;

// Sekunden ab JETZT bis zum naechsten automatischen Aufwachen.
//   - vor 06:00: bis exakt 06:00 heute.
//   - 06:00 bis 23:00: die naechste volle Stunde ab jetzt (immer +3600s).
//   - ab 23:00 (naechste +3600s wuerde vor Mitternacht in die Nacht-Zone
//     zurueckfallen): bis 06:00 morgen, nicht nur +3600s.
uint32_t secondsUntilNextAutomaticWake(int hour, int minute, int second);
