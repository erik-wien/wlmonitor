#include "sensor.h"
#include <Arduino.h>
#include <Wire.h>
#include <math.h>

// SHT4x laut docs/hardware/reterminal-e1003.md §11: I2C 0x44, gleicher Bus
// wie GT911 und PCF8563. Bewusst OHNE die Sensirion-Bibliothek -- das
// Protokoll ist ein Kommandobyte plus sechs Antwortbytes, eine zusaetzliche
// Abhaengigkeit lohnt dafuer nicht.
#define SHT4X_ADDR            0x44
#define SHT4X_CMD_MEASURE_HP  0xFD  // hohe Genauigkeit, ~8,3ms

float readAmbientTemperature() {
    Wire.beginTransmission(SHT4X_ADDR);
    Wire.write(SHT4X_CMD_MEASURE_HP);
    if (Wire.endTransmission() != 0) return NAN;

    delay(10); // Messdauer ~8,3ms

    uint8_t buf[6]; // t_msb, t_lsb, t_crc, rh_msb, rh_lsb, rh_crc
    if (Wire.requestFrom((int) SHT4X_ADDR, (int) sizeof(buf)) != (int) sizeof(buf)) {
        return NAN;
    }
    for (size_t i = 0; i < sizeof(buf); i++) buf[i] = Wire.read();

    uint16_t ticks = ((uint16_t) buf[0] << 8) | buf[1];
    float celsius = -45.0f + 175.0f * ((float) ticks / 65535.0f);

    // Plausibilitaet: der Sensorbereich ist -40..125 C, das Geraet ist fuer
    // 0..40 C spezifiziert. Alles klar ausserhalb deutet auf einen Lese-
    // fehler hin -- dann lieber gar keinen Wert setzen.
    if (celsius < -40.0f || celsius > 85.0f) return NAN;

    return celsius;
}
