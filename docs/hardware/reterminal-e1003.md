# Seeed reTerminal E1003 — Hardware-Referenz

**Verbindliche Hardware-Fakten für `epaper-monitor/`. Vor jeder Firmware-Arbeit lesen.**

Quelle: Seeed-Wiki + Seeed reTerminal-E-Series-Dokumentation
(<https://wiki.seeedstudio.com/getting_started_with_reterminal_e1003/>),
erfasst 2026-08-21.

> **Warum diese Datei existiert:** Diese Fakten lagen zuvor nur in einem
> Claude-Memory (`reference_reterminal_e1003_arduino`), auf das
> `docs/superpowers/plans/2026-08-17-epaper-firmware-e1003.md` Zeile 43
> verweist. **Dieses Memory existiert nicht mehr.** Der Plan hat daher ein
> lückenhaftes Hardware-Bild in die Firmware getragen (u. a. die falsche
> Display-Bibliothek, s. §2) und jede Session musste die Fakten neu vom
> Nutzer erfragen. Hardware-Fakten gehören ins Repo, nicht in ein Memory.

---

## 0. Modell-Abgrenzung (WICHTIG)

Die reTerminal-E-Serie umfasst E1001, E1002, E1003, E1004, E1005. **Pins und
Funktionen unterscheiden sich zwischen den Modellen.** Doku-Beispiele für
andere Modelle dürfen NICHT ungeprüft übernommen werden. Bekannte
Abweichungen für E1003:

| Was | E1003 | E1001/E1002/E1004 |
|---|---|---|
| `BATTERY_ENABLE_PIN` | **GPIO40** | GPIO21 |
| `SD_EN` | **GPIO39** | GPIO16 |
| Touch-Panel | **vorhanden** (GT911) | nicht vorhanden |

---

## 1. Board

- **MCU:** ESP32-S3, 8 MB OPI-PSRAM, 32 MB Flash, microSD-Slot.
  Per `esptool` verifiziert: „ESP32-S3 (QFN56) rev v0.2, 8MB embedded PSRAM".
- **Display:** 10,3″, 1872 × 1404 px, monochrom / 16 Graustufen, IT8951-Controller.
- **USB:** ein einziger USB-C-Port (Laden + Daten). Konsole/Programmierung
  laufen über eine **CH341/CH340K**-USB-Seriell-Brücke → erscheint auf macOS
  als `/dev/cu.wchusbserial*`, **nie** als `cu.usbmodem*`.
- **Akku:** 3000 mAh LiPo.
- **PlatformIO:** `board = seeed_xiao_esp32s3` (gleicher Chip/PSRAM-Aufbau;
  GPIO-Nummern werden im Code direkt referenziert und sind davon unabhängig).

### Serial-Falle: `ARDUINO_USB_CDC_ON_BOOT`

Das Board-Profil `seeed_xiao_esp32s3.json` setzt `ARDUINO_USB_CDC_ON_BOOT=1`.
Damit geht Arduinos `Serial` auf das **native USB-CDC-Peripheriegerät** des
ESP32-S3 statt auf UART0 — und UART0 hängt an der CH340K-Konsolenbrücke.
Folge: Flashen gelingt, `pio device monitor` verbindet, aber es kommt
**gar nichts** an; nur rohe `printf()`-Ausgaben (ROM-Ebene) sind sichtbar.

**Fix (in `platformio.ini`, beide Environments):**
```ini
build_flags =
    -DARDUINO_USB_CDC_ON_BOOT=0
```

Seeeds eigener Beispielcode umgeht das anders — er loggt auf **UART1**
(`Serial1.begin(115200, SERIAL_8N1, 44, 43)`, RX=GPIO44, TX=GPIO43).

---

## 2. Display-Bibliothek — Seeed_GFX, NICHT GxEPD2

**Verbindlich:** `https://github.com/Seeed-Studio/Seeed_GFX.git`

```ini
lib_deps = https://github.com/Seeed-Studio/Seeed_GFX.git
build_flags = -DBOARD_SCREEN_COMBO=522
```

`522` wählt `User_Setups/Setup522_Seeed_reTerminal_E1003.h` (Treiber
`ED103TC2_DRIVER`). API: Klasse `EPaper` (erbt von `TFT_eSprite`),
`begin()`, `update()`, `updataPartial(x,y,w,h)` [sic, Tippfehler in der
Bibliothek], `initGrayMode()`, `sleep()`, `wake()`.

> **Nicht GxEPD2 verwenden.** `GxEPD2_it103_1872x1404` hat VCOM hart auf
> `2330` mV kodiert — den Wert vom Panel-Aufkleber des Bibliotheksautors.
> Am echten E1003 liefen damit alle Refresh-Kommandos sauber durch
> (Busy-Handshake, Timing korrekt), aber **sichtbar änderte sich nichts**.
> Live verifiziert 2026-08-19: nach Umstieg auf Seeed_GFX/Setup522 rendert
> dasselbe Board sofort korrekt.

### Fonts

`LOAD_GFXFF` ist in Setup522 aktiv → `TFT_eSPI.h` zieht **alle**
Adafruit-Free-Fonts automatisch mit ein. Ein eigenes
`#include <Fonts/GFXFF/FreeSansBold9pt7b.h>` führt zu
„redefinition"-Fehlern. Fonts einfach direkt verwenden.

### Partial-Update: 8-px-Rundung

`EPaper::updataPartial()` rundet das X-Fenster intern nach außen auf
8-px-Grenzen (`align_px = 8`), unabhängig davon, ob das übergebene Rechteck
ausgerichtet ist — liest also bis zu 7 px links/rechts über die Anforderung
hinaus direkt aus `_img8`. Nie beschriebener Rand → alter Inhalt wird
mitgezeichnet. Y ist nicht betroffen.

`EPD_UPDATE_PARTIAL()` fährt IT8951-**Modus 0x01 (DU)**, `EPD_UPDATE()`
fährt **Modus 0x02 (GC16)**. DU ist schnell, kennt aber nur Schwarz↔Weiß
ohne vollen Waveform-Durchlauf → Ghosting ist bauartbedingt.

---

## 3. Tasten (User Buttons)

Drei Tasten am ESP32-S3, **alle aktiv-low** (LOW = gedrückt).

| Key | GPIO | E1001 / E1002 / **E1003** | E1004 |
|---|---|---|---|
| KEY0 | **3** | rechte Taste (grün) | rechte Richtungstaste (Front) |
| KEY1 | **4** | mittlere Taste | linke Richtungstaste (Front) |
| KEY2 | **5** | linke Taste | Refresh-Taste (Front links) |

**`pinMode(pin, INPUT)` — NICHT `INPUT_PULLUP`.** Die Hardware hat eigene
Pull-up-Widerstände („Hardware already has pull-up resistors, so use INPUT
mode").

Entprellung: ~50 ms genügt.

### Aufwach-Timing (live verifiziert 2026-08-21)

Nach Deep-Sleep-Wake verbrauchen `Serial.begin()` + `delay(300)` +
`initDisplay()` (SPI/IT8951-Reset) + `initTouch()` (I2C/GT911-Reset)
zusammen **500 ms – 1 s+**. Ein normaler kurzer Tastendruck ist bis dahin
längst losgelassen und wird nie erkannt.

→ **Tasten als allererste Zeilen in `setup()` lesen**, vor
`Serial.begin()`. GPIO-Reads brauchen keine Initialisierung. Touch lässt
sich nicht so früh lesen (braucht I2C-Setup) — das ist eine echte
Einschränkung für Touch.

---

## 4. Batterie

| Was | Wert |
|---|---|
| ADC-Pin | **GPIO1** |
| Enable-Pin | **GPIO40** (E1003; andere Modelle GPIO21!) |
| Spannungsteiler | **×2** |
| Voll (LiPo) | ≈ 4,2 V |
| Leer | ≈ 3,3 V |

```c
pinMode(BATTERY_ENABLE_PIN, OUTPUT);
digitalWrite(BATTERY_ENABLE_PIN, HIGH);
delay(5);                                  // Doku nennt auch 10 ms als "präziser"
analogReadResolution(12);
analogSetPinAttenuation(BATTERY_ADC_PIN, ADC_11db);
int mv = analogReadMilliVolts(BATTERY_ADC_PIN);
digitalWrite(BATTERY_ENABLE_PIN, LOW);     // Teiler nur während der Messung speisen
float batteryVoltage = (mv / 1000.0) * 2;  // ×2 wegen Spannungsteiler
```

Ohne die Verdopplung zeigt die Anzeige dauerhaft ~0 % (live beobachtet).

---

## 5. Touch (nur E1003)

| Was | Wert |
|---|---|
| Controller | GT911 (Goodix) |
| Bus | I2C0, Adresse **0x5D oder 0x14** (auto-detect) |
| SDA | **GPIO19** |
| SCL | **GPIO20** |
| INT | **GPIO2** |
| RESET | **GPIO48** |
| Auflösung | 1872 × 1404 |

- **I2C mit 400 kHz initialisieren** (`Wire.setClock(400000)`) — Arduino-Default
  wären 100 kHz.
- Der Bus wird mit **PCF8563 RTC** und **SHT4x** geteilt.
- Ablauf: Hardware-Reset über GPIO48 → an beiden Adressen proben →
  Touch-Auflösung aus den GT911-max-X/Y-Registern lesen → alle ~30 ms pollen.
- Entprellung im Seeed-Beispiel: neuer Punkt erst nach ≥ 450 ms **oder**
  ≥ 12 px Bewegung.

> **Offen / nicht dokumentiert:** die exakte Rotations-Abbildung von
> Roh-Touch-Koordinaten auf Panel-Koordinaten. Seeeds Beispiel kapselt das in
> `mapTouchToDisplay()`, ohne die Formel zu nennen. Muss am Gerät kalibriert
> werden (bekannte Punkte antippen, Rohwerte protokollieren).

---

## 6. Buzzer

**GPIO45.**

```c
tone(BUZZER_PIN, 1000, 100);   // 1 kHz, 100 ms
noTone(BUZZER_PIN);
```

Signal-Konventionen laut Doku: einfacher Piep = Bestätigung, doppelt =
Warnung, dreifach = Fehler, dauerhaft = kritisch.

---

## 7. Weitere Pins

| Funktion | GPIO |
|---|---|
| EPD SCK | 7 |
| EPD MISO | 8 |
| EPD MOSI | 9 |
| EPD CS | 10 |
| EPD ENABLE (TFT) | 11 |
| EPD RST | 12 |
| EPD BUSY | 13 |
| EPD DC | −1 (nicht vorhanden — IT8951 nutzt SPI-Präambeln) |
| ITE ENABLE | 21 |
| SD CS | 14 |
| SD EN | **39** (E1003; andere Modelle 16) |
| SD DET | 15 |
| Debug-UART RX / TX | 44 / 43 |
| Erweiterungs-Header J2 | 3V3, GND, IO47, IO6, IO20 (SCL), IO19 (SDA) |

---

## 8. Flashen

Kein manueller Bootloader-Eintritt nötig — esptool resettet zuverlässig über
RTS/DTR:

```bash
pio run -e esp32dev -t upload --upload-port /dev/cu.wchusbserial<N>
```

> Die Prozedur aus dem Memory `project_esp32_epaper_flashing` (BOOT+RESET
> gedrückt halten, `esptool --no-stub`) gilt für das **alte Waveshare-
> ESP32-D0WDQ6-Prototypboard**, nicht für das E1003.

Serielle Ausgabe bleibt auch im Deep Sleep sichtbar — die USB-UART-Brücke
hängt an der USB-Versorgung, nicht am ESP32. Das ist kein Fehler.

### Alternative: SenseCraft

Seeeds offiziell dokumentierter Weg ist der browserbasierte **SenseCraft HMI
Firmware Flasher**. Arduino/PlatformIO ist für dieses Board nicht offiziell
dokumentiert (funktioniert aber, s. o.).

---

## 9. Voraussetzungen (Arduino-IDE-Äquivalente)

- PSRAM: **OPI PSRAM** aktivieren — ohne PSRAM lässt sich der
  1872 × 1404-Framebuffer nicht allozieren und `width()` liefert 0.
- Flash Size: **8 MB**
- Board: **XIAO_ESP32S3**
