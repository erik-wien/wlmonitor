#include "touch.h"
#include <Arduino.h>
#include <Wire.h>

// GT911 kapazitiver Touch, nur auf dem E1003 vorhanden.
// Alle Werte aus docs/hardware/reterminal-e1003.md §7 (Seeed-Wiki
// "Touch Screen (E1003 Only)" + Beispiel E1003_TouchDraw.ino).
#define TOUCH_SDA_PIN   19
#define TOUCH_SCL_PIN   20
#define TOUCH_INT_PIN   2
#define TOUCH_RESET_PIN 48

#define GT911_ADDR_1 0x5D
#define GT911_ADDR_2 0x14

#define GT911_REG_COMMAND    0x8040
#define GT911_REG_MAX_X      0x8048   // 2 Byte, little endian
#define GT911_REG_MAX_Y      0x804A   // 2 Byte, little endian
#define GT911_REG_PRODUCT_ID 0x8140   // 4 Byte ASCII, "911" + NUL
#define GT911_REG_STATUS     0x814E
#define GT911_REG_POINT0     0x814F

// Panelmasse im Querformat, wie board.php rendert.
#define PANEL_WIDTH  1872
#define PANEL_HEIGHT 1404

static uint8_t s_touchAddr = 0;
// Aus dem Controller gelesene Touch-Aufloesung. 0 = nicht gelesen, dann
// wird auf die Panelmasse zurueckgefallen.
static int s_touchMaxX = 0;
static int s_touchMaxY = 0;

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

// Probe ueber die PRODUKT-ID, nicht ueber das Statusregister: ein blosses
// ACK auf dem Bus beweist nicht, dass dort ein GT911 sitzt (auf demselben
// I2C0 haengen laut Doku auch PCF8563 @0x51 und SHT4x @0x44). Der GT911
// liefert an 0x8140 den ASCII-String "911".
static bool probeGt911(uint8_t addr) {
    uint8_t id[4] = {0, 0, 0, 0};
    if (!i2cReadReg16(addr, GT911_REG_PRODUCT_ID, id, sizeof(id))) return false;
    return id[0] == '9' && id[1] == '1' && id[2] == '1';
}

// Reset-Timing exakt nach Seeed-Vorgabe: LOW 20ms, dann HIGH 120ms.
// Vorher standen hier 10ms/50ms -- deutlich zu kurz, der Controller kann
// danach noch nicht antwortbereit sein (haeufigste Ursache dafuer, dass
// Touch gar nicht reagiert).
// Kanonische GT911-Startsequenz. Der Controller waehlt seine I2C-Adresse
// anhand des INT-PEGELS im Moment, in dem RESET losgelassen wird:
//   INT LOW  -> 0x5D     INT HIGH -> 0x14
// Deshalb INT waehrend des Resets aktiv LOW treiben und erst DANACH auf
// Eingang schalten. Vorher wurde INT schon vor dem Reset auf INPUT_PULLUP
// gesetzt -> der Chip meldete sich auf 0x14, lieferte aber weder eine
// Aufloesung ("Bereich 0x0") noch jemals Touch-Daten (am Geraet gemessen
// 2026-08-21). Seeeds Beispiel setzt INT erst nach dem Reset und erhaelt
// entsprechend 0x5D. Timing 20ms/120ms wie in der Doku.
static void resetTouchController() {
    pinMode(TOUCH_INT_PIN, OUTPUT);
    digitalWrite(TOUCH_INT_PIN, LOW);
    pinMode(TOUCH_RESET_PIN, OUTPUT);
    digitalWrite(TOUCH_RESET_PIN, LOW);
    delay(20);
    digitalWrite(TOUCH_RESET_PIN, HIGH);
    delay(120);                            // INT liegt hier noch LOW -> 0x5D
    pinMode(TOUCH_INT_PIN, INPUT_PULLUP);  // erst jetzt als Eingang
    delay(50);
}

bool initTouch() {
    Wire.begin(TOUCH_SDA_PIN, TOUCH_SCL_PIN);
    // 400kHz lt. Doku; ESP32-Arduino-Default waere 100kHz.
    Wire.setClock(400000);
    // INT-Pin wird in resetTouchController() gesetzt -- die Reihenfolge
    // entscheidet ueber die I2C-Adresse, s. dort.
    resetTouchController();

    if (probeGt911(GT911_ADDR_1)) {
        s_touchAddr = GT911_ADDR_1;
    } else if (probeGt911(GT911_ADDR_2)) {
        s_touchAddr = GT911_ADDR_2;
    } else {
        s_touchAddr = 0;
        Serial.println("[touch] kein GT911 gefunden (0x5D/0x14 ohne Produkt-ID 911)");
        return false;
    }

    // Touch-Aufloesung aus dem Controller lesen, statt sie anzunehmen.
    uint8_t res[4];
    if (i2cReadReg16(s_touchAddr, GT911_REG_MAX_X, res, sizeof(res))) {
        s_touchMaxX = res[0] | (res[1] << 8);
        s_touchMaxY = res[2] | (res[3] << 8);
    }
    Serial.printf("[touch] GT911 @0x%02X, Bereich %dx%d, Panel %dx%d\n",
                  s_touchAddr, s_touchMaxX, s_touchMaxY, PANEL_WIDTH, PANEL_HEIGHT);

    i2cWriteReg16(s_touchAddr, GT911_REG_COMMAND, 0x00);
    return true;
}

TouchResult pollTouch(int favoriteCount, int totalPages, int* outRawX, int* outRawY) {
    if (s_touchAddr == 0) return {};

    uint8_t status;
    if (!i2cReadReg16(s_touchAddr, GT911_REG_STATUS, &status, 1)) {
        return {};
    }
    // Diagnose: jede Regung des Statusregisters melden. Bleibt das dauerhaft
    // 0x00, waehrend ein Finger auflegt, scannt der Controller nicht bzw.
    // meldet nichts -- dann liegt es nicht am Zeitfenster.
    if (status != 0) Serial.printf("[touch] status=0x%02X\n", status);

    uint8_t touchCount = status & 0x0F;
    bool bufferReady = (status & 0x80) != 0;

    if (!bufferReady) {
        // Kein neuer Datensatz -- INT wird nicht von uns gehalten.
        return {};
    }
    if (touchCount == 0) {
        // "Puffer bereit, aber null Punkte" ist der Loslass-Fall. Auch DAS
        // muss quittiert werden, sonst haelt der GT911 seinen INT (GPIO2)
        // dauerhaft aktiv -- und da GPIO2 in der ext1-Weckmaske steht,
        // weckt das Geraet sofort nach jedem esp_deep_sleep_start() wieder
        // auf. Am Geraet gemessen (2026-08-21): "[sleep] GPIO2=L" +
        // "[wake] ext1-Maske=0x4 -> GPIO2", Zyklus ~9s statt 120s.
        i2cWriteReg16(s_touchAddr, GT911_REG_STATUS, 0x00);
        return {};
    }

    // BUGFIX (2026-08-21): ein Touch-Punktdatensatz ist 8 Byte:
    // Byte0=Track-ID, dann X_low,X_high,Y_low,Y_high,Size_low,Size_high,Res.
    // Vorher wurden nur 4 Byte gelesen und Byte0 (Track-ID) faelschlich als
    // X_low interpretiert -- alle Koordinaten waren um ein Byte verschoben,
    // reiner Datenmuell. Gefunden durch Abgleich mit Nutzers funktionierendem
    // Referenzcode (Seeeds E1003_TouchDraw.ino).
    uint8_t point[8];
    if (!i2cReadReg16(s_touchAddr, GT911_REG_POINT0, point, sizeof(point))) {
        i2cWriteReg16(s_touchAddr, GT911_REG_STATUS, 0x00);
        return {};
    }
    i2cWriteReg16(s_touchAddr, GT911_REG_STATUS, 0x00);

    int rawX = point[1] | (point[2] << 8);
    int rawY = point[3] | (point[4] << 8);

    // KORREKTUR ggue. der vorigen Fassung: dort wurde um 90 Grad gedreht
    // (x = rawY; y = 1404 - rawX), unter der Annahme, der GT911 liefere
    // Hochformat-Rohwerte. Seeeds eigenes Beispiel gibt aber
    // "raw=(468,302) screen=(468,302)" aus -- Rohwerte und Bildschirm-
    // koordinaten sind IDENTISCH, der Controller ist bereits auf das
    // Querformat konfiguriert. Statt einer festen Drehung jetzt eine reine
    // Skalierung ueber die aus dem Controller gelesene Touch-Aufloesung
    // (entspricht Seeeds mapTouchToDisplay()); sind Touch- und Panelmasse
    // gleich, ist das die Identitaet.
    const int maxX = s_touchMaxX > 0 ? s_touchMaxX : PANEL_WIDTH;
    const int maxY = s_touchMaxY > 0 ? s_touchMaxY : PANEL_HEIGHT;
    // ZURUECKGESETZT (2026-08-21): der X-Spiegel-Versuch beruhte auf einer
    // einzelnen Messung und war falsch (Nutzer meldet jetzt spiegelverkehrt).
    // Zurueck auf 1:1 wie in Seeeds Referenz ("raw==screen"). Kalibrierung
    // ab jetzt ueber drawTouchBlob() live am Geraet, nicht mehr geraten.
    int x = (int) ((int32_t) rawX * (PANEL_WIDTH  - 1) / (maxX > 1 ? maxX - 1 : 1));
    int y = (int) ((int32_t) rawY * (PANEL_HEIGHT - 1) / (maxY > 1 ? maxY - 1 : 1));

    if (x < 0) x = 0; else if (x >= PANEL_WIDTH)  x = PANEL_WIDTH  - 1;
    if (y < 0) y = 0; else if (y >= PANEL_HEIGHT) y = PANEL_HEIGHT - 1;

    Serial.printf("[touch] raw=(%d,%d) screen=(%d,%d)\n", rawX, rawY, x, y);

    if (outRawX != nullptr) *outRawX = x;
    if (outRawY != nullptr) *outRawY = y;

    return mapTouchToZone(x, y, favoriteCount, totalPages);
}

void touchClearInterrupt() {
    if (s_touchAddr == 0) return;
    // Vor dem Tiefschlaf sicherstellen, dass der GT911 seinen INT losgelassen
    // hat. Ein spaeterer echter Touch zieht ihn erneut LOW und weckt dann
    // regulaer -- die Weckquelle bleibt also erhalten.
    i2cWriteReg16(s_touchAddr, GT911_REG_STATUS, 0x00);
}
