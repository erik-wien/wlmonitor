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
