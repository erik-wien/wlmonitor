#pragma once

// Liest den rohen Akku-Millivolt-Wert (Spannungsteiler ueber ADC GPIO1,
// Enable-Pin GPIO40 -- E1003-spezifisch). Der Server normalisiert diesen
// Rohwert selbst zu Prozent (board_battery_percent_from_mv(), inc/board.php,
// linear 3300mV=0%..4200mV=100%) -- die Firmware schickt einfach den
// Rohwert, keine eigene Umrechnung.
int readBatteryMillivolts();
