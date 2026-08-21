#include "touch.h"
#include <Wire.h>

#define TOUCH_SDA_PIN   19
#define TOUCH_SCL_PIN   20
#define TOUCH_INT_PIN   2
#define TOUCH_RESET_PIN 48

#define GT911_ADDR_1 0x5D
#define GT911_ADDR_2 0x14
#define GT911_REG_STATUS 0x814E
#define GT911_REG_POINT0 0x814F
#define GT911_REG_COMMAND 0x8040

static uint8_t s_touchAddr = 0;

static void i2cWriteReg16(uint8_t addr, uint16_t reg, uint8_t value) {
    Wire.beginTransmission(addr);
    Wire.write((uint8_t) (reg >> 8));
    Wire.write((uint8_t) (reg & 0xFF));
    Wire.write(value);
    Wire.endTransmission();
}

static bool i2cReadReg16(uint8_t addr, uint16_t reg, uint8_t* buf, size_t len) {
    Wire.beginTransmission(addr);
    Wire.write((uint8_t) (reg >> 8));
    Wire.write((uint8_t) (reg & 0xFF));
    if (Wire.endTransmission(false) != 0) return false;
    if (Wire.requestFrom((int) addr, (int) len) != (int) len) return false;
    for (size_t i = 0; i < len; i++) buf[i] = Wire.read();
    return true;
}

static bool probeGt911(uint8_t addr) {
    uint8_t status;
    return i2cReadReg16(addr, GT911_REG_STATUS, &status, 1);
}

static void resetTouchController() {
    pinMode(TOUCH_RESET_PIN, OUTPUT);
    digitalWrite(TOUCH_RESET_PIN, LOW);
    delay(10);
    digitalWrite(TOUCH_RESET_PIN, HIGH);
    delay(50);
}

bool initTouch() {
    Wire.begin(TOUCH_SDA_PIN, TOUCH_SCL_PIN);
    // 400kHz lt. Seeed-Doku ("Initialize I2C at 400 kHz on GPIO19/GPIO20"),
    // ESP32-Arduino-Default waere sonst 100kHz.
    Wire.setClock(400000);
    pinMode(TOUCH_INT_PIN, INPUT);
    resetTouchController();

    if (probeGt911(GT911_ADDR_1)) {
        s_touchAddr = GT911_ADDR_1;
    } else if (probeGt911(GT911_ADDR_2)) {
        s_touchAddr = GT911_ADDR_2;
    } else {
        s_touchAddr = 0;
        return false;
    }

    i2cWriteReg16(s_touchAddr, GT911_REG_COMMAND, 0x00);
    return true;
}

TouchZone pollTouch(int favoriteCount, int totalPages, int* outRawX, int* outRawY) {
    if (s_touchAddr == 0) return TouchZone::None;

    uint8_t status;
    if (!i2cReadReg16(s_touchAddr, GT911_REG_STATUS, &status, 1)) {
        return TouchZone::None;
    }
    uint8_t touchCount = status & 0x0F;
    bool bufferReady = (status & 0x80) != 0;
    if (!bufferReady || touchCount == 0) {
        return TouchZone::None;
    }

    uint8_t point[4]; // x_low, x_high, y_low, y_high (erste 4 Byte des Punkt-0-Datensatzes)
    if (!i2cReadReg16(s_touchAddr, GT911_REG_POINT0, point, sizeof(point))) {
        i2cWriteReg16(s_touchAddr, GT911_REG_STATUS, 0x00); // Statusregister quittieren
        return TouchZone::None;
    }
    i2cWriteReg16(s_touchAddr, GT911_REG_STATUS, 0x00);

    int rawX = point[0] | (point[1] << 8);
    int rawY = point[2] | (point[3] << 8);
    // GT911 liefert Rohkoordinaten im nativen Panel-Koordinatensystem
    // (1404x1872 Hochformat); das Display laeuft um 90 Grad gedreht
    // (Spec §3) -- mapTouchToZone() erwartet Querformat-Koordinaten
    // (1872x1404). Rotation unverifiziert ohne Hardware: exaktes
    // Vorzeichen/Achsen-Mapping (rawY->x oder PANEL_HEIGHT-rawY->x usw.)
    // muss am echten Geraet kalibriert werden.
    int x = rawY;
    int y = 1404 - rawX;

    if (outRawX != nullptr) *outRawX = x;
    if (outRawY != nullptr) *outRawY = y;

    return mapTouchToZone(x, y, favoriteCount, totalPages);
}
