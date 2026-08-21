#include "battery.h"
#include <Arduino.h>

// Seeed reTerminal-E-Series "Battery Management System"-Doku (vom Nutzer
// 2026-08-21 direkt zitiert, nicht die allgemeine Getting-Started-Seite):
// BATTERY_ENABLE_PIN unterscheidet sich je Modell -- E1001/E1002/E1004
// nutzen GPIO21, E1003 nutzt GPIO40 (hier bereits korrekt). Referenz-Code
// verwendet delay(5), nicht 10ms.
#define BATTERY_ADC_PIN     1
#define BATTERY_ENABLE_PIN  40

int readBatteryMillivolts() {
    pinMode(BATTERY_ENABLE_PIN, OUTPUT);
    digitalWrite(BATTERY_ENABLE_PIN, HIGH);
    delay(5);

    analogReadResolution(12);
    analogSetPinAttenuation(BATTERY_ADC_PIN, ADC_11db);
    int raw = analogReadMilliVolts(BATTERY_ADC_PIN);

    digitalWrite(BATTERY_ENABLE_PIN, LOW); // Spannungsteiler-Stromverbrauch nur waehrend der Messung

    // Spannungsteiler-Faktor 2x -- dokumentiert in Seeeds reTerminal-E-Series
    // "Battery Management System"-Referenzcode ("batteryVoltage = (mv/1000.0)*2",
    // "2x due to voltage divider"). readBatteryMillivolts() bleibt in mV
    // (geht als X-Device-Battery-mV an board.php), daher *2 hier statt der
    // Volt-Umrechnung aus dem Beispiel.
    return raw * 2;
}
