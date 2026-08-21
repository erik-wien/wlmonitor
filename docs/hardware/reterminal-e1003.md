# Seeed reTerminal E1003 — vollständige Hardware-Referenz

**Verbindlich für alle Arbeiten an `epaper-monitor/`. Vor jeder Firmware-Änderung lesen.**

Quellen (alle abgerufen 2026-08-21):

1. <https://wiki.seeedstudio.com/getting_started_with_reterminal_e1003/>
2. <https://wiki.seeedstudio.com/reterminal_e10xx_with_arduino/>
3. <https://wiki.seeedstudio.com/reterminal_e10xx_with_arduino_peripherals/>
4. <https://wiki.seeedstudio.com/reterminal_e10xx_with_arduino_peripherals_2/>

Beispielcode: <https://github.com/Seeed-Projects/OSHW-reTerminal-Series-E-D>

> **Warum diese Datei existiert:** Diese Fakten lagen zuvor nur in einem
> Claude-Memory (`reference_reterminal_e1003_arduino`), auf das
> `docs/superpowers/plans/2026-08-17-epaper-firmware-e1003.md` Zeile 43
> verweist. **Dieses Memory existiert nicht mehr.** Der Plan trug dadurch ein
> lückenhaftes und in einem zentralen Punkt falsches Hardware-Bild in die
> Firmware, und jede Session musste die Fakten neu erfragen. Hardware-Fakten
> gehören ins Repo, nicht in ein Memory.

---

## 1. Geltungsbereich und Modell-Fallen

Die Seeed-Wiki beschreibt **E1001, E1002, E1003, E1004 (teils E1005)
parallel auf denselben Seiten**. Pins und Funktionen unterscheiden sich.
Beispielcode für ein anderes Modell darf **nie** ungeprüft übernommen werden.

### Feature-Matrix

| Feature | E1001 | E1002 | **E1003** | E1004 |
|---|:--:|:--:|:--:|:--:|
| PCF8563 RTC (I²C 0x51) | ✅ | ✅ | **✅** | ✅ |
| Deep/Light Sleep + GPIO-Wake | ✅ | ✅ | **✅** | ✅ |
| PDM-Mikrofon | ✅ | ✅ | **✅** | ❌ |
| Kapazitiver Touch (GT911) | ❌ | ❌ | **✅** | ❌ |

### Pins, die sich je Modell unterscheiden

| Signal | E1001 | E1002 | **E1003** | E1004 |
|---|---|---|---|---|
| LED | GPIO6 | GPIO6 | **GPIO16** | GPIO48 |
| Batterie-Enable | GPIO21 | GPIO21 | **GPIO40** | GPIO21 |
| SD-Enable | GPIO16 | GPIO16 | **GPIO39** | GPIO16 |
| Wake-Taste | GPIO3 | GPIO3 | **GPIO3** | GPIO4 |
| `BOARD_SCREEN_COMBO` | 520 | 521 | **522** | 523 |
| Graustufen-Modus | `GRAY_LEVEL4` | – (6 Farben) | **`GRAY_LEVEL16`** | – (6 Farben) |

> ⚠️ **Fehler in der Wiki selbst:** Die Zusammenfassung „Key Differences by
> Model" auf Seite (3) behauptet für E1003 „SD_EN on GPIO40". Das ist
> **falsch** — GPIO40 ist der Batterie-Enable. Die Pin-Tabellen derselben
> Seite und der PDM-Abschnitt auf Seite (4) nennen übereinstimmend
> **GPIO39** für SD_EN. Wir folgen GPIO39.

> ⚠️ Ebenfalls in der Wiki inkonsistent: Seite (3) nennt das E1003-Panel in
> einem Nebensatz „4.7″". Korrekt sind **10,3″** (Seite 1 und 2).

---

## 2. Gerät

| Merkmal | Wert |
|---|---|
| Display | 10,3″ ePaper, monochrom, 16 Graustufen |
| Auflösung | **1404 × 1872** nativ (Hochformat) — in Setup522 als **1872 × 1404** (Querformat) definiert |
| Panel | ED103TC2 |
| Controller | IT8951 |
| Voll-Refresh | ~3 s (Herstellerangabe) |
| MCU | ESP32-S3, 8 MB OPI-PSRAM |
| Flash | 32 MB |
| microSD | bis 64 GB, **FAT32** (>32 GB kommen ab Werk als exFAT → neu formatieren) |
| Akku | 3000 mAh LiPo |
| Laden | USB-C, 5 V / 1 A |
| Laufzeit | „bis zu 6 Monate" bei einem Refresh pro Tag |
| Funk | WLAN 2,4 GHz b/g/n (**kein 5 GHz**), Bluetooth 5.0 |
| Sensorik | Temperatur/Feuchte (SHT4x), PDM-Mikrofon |
| Ton | Buzzer |
| Maße | 224 × 187 × 18,6 mm |
| Temperaturbereich | 0–40 °C |
| Standfuß | fester Blickwinkel, nicht verstellbar |

### Bedienelemente (physisch)

- **Grüne Taste** (oben) — „Refresh": kurz = manueller Refresh; lang = (künftig) Sprachmodus.
- **Zwei weiße Tasten** (oben) — Seitennavigation links/rechts.
  **Beide 2 s gehalten = WLAN-Reset** (Werksverhalten der SenseCraft-Firmware).
- **Ein/Aus-Schalter** (Rückseite).

### LED

| Zustand | Bedeutung |
|---|---|
| aus | kein USB angeschlossen |
| rot dauerhaft | lädt über USB |
| grün 3 s beim Einschalten | Startanzeige |
| grün dauerhaft | vollständig geladen (USB) |

Ansteuerung per GPIO (**E1003: GPIO16**), Logik **invertiert**: `LOW` = an, `HIGH` = aus.

### Erweiterungs-Header J2

| Pin | Label | GPIO | Funktion |
|---|---|---|---|
| 1 | HEADER_3V3 | – | 3,3 V |
| 2 | GND | – | Masse |
| 3 | ESP_IO47 | 47 | GPIO / ADC |
| 4 | ESP_IO6 | 6 | GPIO / ADC1 |
| 5 | ESP_IO20 | 20 | I²C0 SCL |
| 6 | ESP_IO19 | 19 | I²C0 SDA |

### Werksfirmware (SenseCraft HMI)

Nur relevant, solange die Originalfirmware läuft — unsere Firmware ersetzt sie:

- Touch wird erst ab **v1.1.2** unterstützt, empfohlen **v1.1.4.3**.
- Gerät schläft nach **30 s** Inaktivität; Refresh-Taste weckt.
- Akku-Warnsymbol unter **20 %**.
- ESPHome braucht **2026.7.0+** für den E1003-Displaytreiber.
- „Standard flash" erhält WLAN/Designs/Bilder, „full flash" setzt alles zurück.

---

## 3. Entwicklungsumgebung

| Einstellung | Wert |
|---|---|
| Board | **XIAO_ESP32S3** |
| PSRAM | **OPI PSRAM** (zwingend — ohne PSRAM schlägt die Framebuffer-Allokation fehl und `width()` liefert 0) |
| Flash Size | 8 MB |
| Partition | Default 8 MB |
| ESP32-Core | ≥ 3.0 (nur für PDM-Mikrofon zwingend) |

Unser PlatformIO-Äquivalent (`epaper-monitor/platformio.ini`):

```ini
board = seeed_xiao_esp32s3
board_build.arduino.memory_type = qio_opi
build_flags = -DBOARD_HAS_PSRAM
```

### Treiber für die USB-Seriell-Brücke (CH341/CH340K)

| OS | Bedarf |
|---|---|
| Windows 11 | eingebaut |
| Windows 10 und älter | CH341-Treiber installieren |
| macOS | `CH34xVCPDriver` + Systemerweiterung aktivieren; Prüfung: `ls /dev/tty.wch*` |
| Linux | Ubuntu 22.04+ eingebaut, älter ggf. Modul nachladen |

---

## 4. Konsole / Serial

Das Board hat **einen** USB-C-Port (Laden + Daten). Konsole und Flashen laufen
über eine **CH341/CH340K**-Brücke → auf macOS `/dev/cu.wchusbserial*`,
**nie** `cu.usbmodem*`.

### Seeeds Weg: UART1

Sämtlicher Seeed-Beispielcode loggt auf **`Serial1`**:

```c
Serial1.begin(115200, SERIAL_8N1, /*RX=*/44, /*TX=*/43);
```

### Unser Weg: UART0 mit CDC-Abschaltung

Das PlatformIO-Board-Profil `seeed_xiao_esp32s3.json` erzwingt
`ARDUINO_USB_CDC_ON_BOOT=1`. Damit geht `Serial` auf das **native USB-CDC**
des ESP32-S3 statt auf UART0 — und an der Konsolenbrücke hängt UART0.
Symptom: Flashen klappt, `pio device monitor` verbindet, es kommt aber
**gar nichts**; nur rohe `printf()`-Ausgaben (ROM-Ebene) erscheinen.

```ini
build_flags = -DARDUINO_USB_CDC_ON_BOOT=0
```

**In JEDEM Environment setzen** — ein Environment ohne das Flag ist stumm.

### Serial im Deep Sleep

Dass weiterhin Ausgaben erscheinen, heißt **nicht**, dass der Schlaf
fehlschlug: die USB-UART-Brücke hängt an der USB-Versorgung, nicht am
ESP32. Verlässlicher Nachweis ist Stille **nach** der letzten eigenen
Log-Zeile.

---

## 5. Display

### 5.1 Bibliothek: Seeed_GFX

<https://github.com/Seeed-Studio/Seeed_GFX>

```ini
lib_deps = https://github.com/Seeed-Studio/Seeed_GFX.git
build_flags = -DBOARD_SCREEN_COMBO=522
```

`522` wählt `User_Setups/Setup522_Seeed_reTerminal_E1003.h` (Treiber
`ED103TC2_DRIVER`). In der Arduino-IDE geschieht dasselbe über eine Datei
`driver.h` im Sketch-Ordner:

```c
#define BOARD_SCREEN_COMBO 522   // reTerminal E1003 (ED103TC2 / IT8951)
```

> **Konflikt:** Eine vorhandene **TFT_eSPI**-Installation muss entfernt oder
> umbenannt werden — Seeed_GFX ist ein TFT_eSPI-Derivat und kollidiert.

### 5.2 Setup522 im Original

```c
#define ED103TC2_DRIVER
#define EPAPER_ENABLE
#define TFT_WIDTH  1872
#define TFT_HEIGHT 1404
#define EPD_WIDTH  TFT_WIDTH
#define EPD_HEIGHT TFT_HEIGHT
// #define EPD_HORIZONTAL_MIRROR

#define TFT_SCLK    7
#define TFT_MISO    8
#define TFT_MOSI    9
#define TFT_CS     10
#define TFT_DC     -1     // IT8951 nutzt SPI-Präambeln statt DC-Leitung
#define TFT_BUSY   13
#define TFT_RST    12
#define TFT_ENABLE 11
#define ITE_ENABLE 21

#define LOAD_GLCD / FONT2 / FONT4 / FONT6 / FONT7 / FONT8 / GFXFF
#define SMOOTH_FONT
#ifdef CONFIG_IDF_TARGET_ESP32S3
  #define USE_HSPI_PORT
#endif
```

Aus `TFT_Drivers/ED103TC2_Defines.h`:

```c
#define EPD_COLOR_DEPTH 1
#define USE_PARTIAL_EPAPER
#define USE_MUTIGRAY_EPAPER
#define GRAY_LEVEL16 16
```

### 5.3 API

```c
#include "TFT_eSPI.h"
EPaper epaper;          // erbt von TFT_eSprite

epaper.begin(uint8_t wake = 0);
epaper.update();                              // Vollbild-Refresh
epaper.updataPartial(x, y, w, h);             // Teil-Refresh  [Schreibweise sic!]
epaper.update(x, y, w, h, uint16_t* data);
epaper.drawBufferPixel(x, y, color, bpp);     // ein Byte direkt in den Puffer
epaper.initGrayMode(GRAY_LEVEL16);
epaper.deinitGrayMode();
epaper.sleep();
epaper.wake();
epaper.setTemp(cb); epaper.getTemp();
epaper.setHumi(cb); epaper.getHumi();
epaper.getSPIinstance();                      // geteilter SPI-Bus, z. B. für SD
```

> ⚠️ **Namensfalle:** Die Wiki schreibt `epaper.updatePartial()`. Der echte
> Methodenname im Quellcode ist **`updataPartial()`** (Tippfehler der
> Bibliothek). Wiki-Beispiele kompilieren an dieser Stelle nicht.

Geerbte Zeichenfunktionen (TFT_eSprite): `fillScreen`, `fillRect`,
`drawRect`, `drawCircle`, `fillCircle`, `drawLine`, `drawTriangle`,
`fillTriangle`, `drawRoundRect`, `fillRoundRect`, `drawFastHLine`,
`drawPixel`, `pushImage`, `setTextColor`, `setTextSize`, `setFont`,
`setFreeFont`, `setCursor`, `print`, `setRotation`, `width`, `height`.

### 5.4 Graustufen

```c
epaper.initGrayMode(GRAY_LEVEL16);   // E1003
epaper.fillSprite(TFT_GRAY_15);      // 15 = weiß, 0 = schwarz
```

- **E1003: `GRAY_LEVEL16`** → `TFT_GRAY_0` … `TFT_GRAY_15`, 4 bpp,
  Sprite 1872·1404/2 = **1,32 MB** (PSRAM).
- E1001: `GRAY_LEVEL4` → `TFT_GRAY_0` … `TFT_GRAY_3`. **Nicht für E1003.**
- Graustufen-Refresh ist deutlich langsamer als 1 bit (bei 4 Stufen ~4×,
  weil jeder Pixel durch vier statt zwei Zielspannungen gefahren wird).
  Empfehlung: Graustufen für statische Inhalte, 1 bit für schnelle UI.

Unsere Firmware rendert **1 bpp** (`EPD_COLOR_DEPTH 1`), nutzt also den
Graustufenmodus derzeit nicht.

### 5.5 Refresh-Modi und Teil-Refresh

Aus `ED103TC2_Defines.h` — die IT8951-Moduskennung ist der 5. Parameter:

```c
#define EPD_UPDATE()          tconDisplayArea1bpp(..., 0x02, 0x00, 0xff)  // GC16, voll
#define EPD_UPDATE_PARTIAL()  tconDisplayArea1bpp(..., 0x01, 0x00, 0xff)  // DU, schnell
#define EPD_UPDATE_GRAY()     tconDisplayArea   (..., 0x02)
```

**Modus 0x01 (DU)** kennt nur Schwarz↔Weiß ohne vollen Waveform-Durchlauf —
schnell, hinterlässt aber bauartbedingt Ghosting. **Modus 0x02 (GC16)**
fährt alle 16 Stufen und löscht sauber.

> **8-px-Rundung:** `EPaper::updataPartial()` rundet das X-Fenster intern
> nach außen auf 8-px-Grenzen (`align_px = 8`), unabhängig vom übergebenen
> Rechteck, und liest bis zu 7 px links/rechts darüber hinaus direkt aus
> `_img8`. Ein nie beschriebener Rand zeigt dort alten Inhalt. Y ist nicht
> betroffen. Unsere Gegenmaßnahme: `clearAlignedForPartial()` in
> `display.cpp` belegt denselben aufgerundeten Bereich vorher weiß.

Refresh-Zeiten laut Wiki: **kaltes Panel (erster Refresh) bis 2 Minuten**,
danach 15–45 s modellabhängig; für E1003 werden ~1–3 s pro `update()`
genannt (Touch-Beispiel) bzw. „3-Second Full Refresh" (Datenblatt).

### 5.6 Alternative: Seeed_GxEPD2 (Fork)

<https://github.com/Seeed-Projects/Seeed_GxEPD2/> + Adafruit GFX Library.

> Die Wiki sagt ausdrücklich: „We strongly recommend using **this fork**
> instead of the upstream library to ensure full compatibility."

Panelklasse E1003: **`GxEPD2_ED103TC2_1872x1404`** (braucht eine
Zusatz-Header-Datei aus dem Beispielordner). Framebuffer ~321 kB in PSRAM.

> ⚠️ **Genau hier lag unser Fehler.** Der Firmware-Plan nannte
> „Seeed_GxEPD2" — implementiert wurde aber das **Upstream**
> `zinggjm/GxEPD2` mit der Klasse `GxEPD2_it103_1872x1404`. Dieser Treiber
> hat VCOM hart auf `2330` mV kodiert (Panel-Aufkleber des
> Upstream-Autors). Folge am echten E1003: alle Refresh-Kommandos liefen
> sauber durch, sichtbar änderte sich **nichts**. Wir sind auf Seeed_GFX
> (§5.1) gewechselt und bleiben dort.

GxEPD2-Muster (nur zur Einordnung):

```c
display.setRotation(0);
display.setFullWindow();
display.firstPage();
do { /* zeichnen */ } while (display.nextPage());
display.hibernate();
```

### 5.7 Schriften

`LOAD_GFXFF` ist in Setup522 aktiv → `TFT_eSPI.h` zieht über
`Fonts/GFXFF/gfxfont.h` **alle** Adafruit-Free-Fonts automatisch mit ein
(FreeMono, FreeSans, FreeSerif in allen Größen/Stilen).

> Ein eigenes `#include <Fonts/GFXFF/FreeSansBold9pt7b.h>` erzeugt
> „redefinition"-Fehler. Fonts einfach direkt verwenden.

Verfügbar u. a.: `FreeSansBold9pt7b/12/18/24`, `FreeSans9pt7b`,
`FreeMonoBold9pt7b/12`, `FreeMono9pt7b`.

---

## 6. Tasten

Drei Tasten, **alle aktiv-low** (LOW = gedrückt).

| Key | GPIO | E1001 / E1002 / **E1003** | E1004 |
|---|---|---|---|
| KEY0 | **3** | rechte Taste (grün) | rechte Richtungstaste (Front) |
| KEY1 | **4** | mittlere Taste | linke Richtungstaste (Front) |
| KEY2 | **5** | linke Taste | Refresh-Taste (Front links) |

```c
pinMode(BUTTON_KEY0, INPUT);          // NICHT INPUT_PULLUP
bool pressed = (digitalRead(BUTTON_KEY0) == LOW);
```

> **`INPUT`, nicht `INPUT_PULLUP`** — die Hardware hat eigene Pull-ups
> („Hardware already has pull-up resistors, so use INPUT mode").

Entprellung: 50 ms.

### Aufwach-Timing (bei uns live verifiziert)

Nach Deep-Sleep-Wake verbrauchen `Serial.begin()` + `delay(300)` +
`initDisplay()` (SPI/IT8951-Reset) + `initTouch()` (I²C/GT911-Reset)
zusammen **500 ms – 1 s+**. Ein kurzer Tastendruck ist bis dahin losgelassen
und wird nie erkannt.

→ **Tasten in den allerersten Zeilen von `setup()` lesen**, vor
`Serial.begin()`. GPIO-Reads brauchen keine Initialisierung. Touch geht
das nicht, weil es I²C braucht — dort bleibt die Latenz bestehen.

---

## 7. Touch (nur E1003)

| Parameter | Wert |
|---|---|
| Controller | GT911 (Goodix), kapazitiv |
| Bus | I²C0, **400 kHz** |
| Adresse | **0x5D oder 0x14** (automatisch erkennen) |
| SDA | **GPIO19** |
| SCL | **GPIO20** |
| INT | **GPIO2** |
| RESET | **GPIO48** |
| Auflösung | 1872 × 1404 |
| Geteilter Bus | PCF8563 RTC (0x51), SHT4x (0x44) |

### Registerkarte

| Register | Bedeutung |
|---|---|
| `0x8040` | Kommandoregister |
| `0x8048` | Max-X (2 Byte) |
| `0x804A` | Max-Y (2 Byte) |
| `0x8140` | **Produkt-ID** (liest „911") |
| `0x814E` | Status: Bits 3:0 = Anzahl Punkte, Bit 7 = Puffer bereit |
| `0x814F` | Punkt 1, 8 Byte: Track-ID, X-low, X-high, Y-low, Y-high, … |

### Initialisierung laut Seeed

1. **Reset:** GPIO48 **LOW für 20 ms**, dann **HIGH für 120 ms**.
2. **Proben:** 0x5D, sonst 0x14 — Verifikation über **Produkt-ID `0x8140`**.
3. **Grenzen lesen:** Max-X/Max-Y aus `0x8048`.
4. **Status quittieren:** `0x00` ins Statusregister.
5. **INT:** GPIO2 als **`INPUT_PULLUP`**.

> ⚠️ **Abweichungen unserer `touch.cpp`** (Stand 2026-08-21, mögliche
> Ursachen dafür, dass Touch nicht reagiert):
> - Reset ist **LOW 10 ms / HIGH 50 ms** statt **20 ms / 120 ms** — deutlich
>   zu kurz, der Controller kann noch nicht bereit sein.
> - Proben erfolgt über das **Statusregister** statt über die
>   **Produkt-ID `0x8140`** — ein ACK dort beweist nicht, dass ein GT911
>   antwortet.
> - INT-Pin ist **`INPUT`** statt **`INPUT_PULLUP`**.
> - Max-X/Max-Y werden **nicht gelesen**; die Umrechnung ist fest verdrahtet.

### Polling und Entprellung (Seeed-Beispiel `E1003_TouchDraw.ino`)

```c
TOUCH_POLL_MS     = 30    // alle 30 ms Status lesen
DRAW_MIN_MS       = 450   // frühestens 450 ms nach dem letzten Zeichnen
DRAW_MIN_DELTA_PX = 12    // oder mindestens 12 px Bewegung
DOT_RADIUS        = 10
```

Koordinaten kommen roh vom GT911 und werden über
`mapTouchToDisplay(rawX, rawY, touchMaxX, touchMaxY, dispW, dispH, &out)`
umgerechnet.

> **Offen und nirgends dokumentiert:** die exakte Rotations-/Achsenformel.
> Seeed kapselt sie in `mapTouchToDisplay()`, ohne sie zu nennen. Muss am
> Gerät kalibriert werden (bekannte Punkte antippen, Rohwerte protokollieren).

Erwartete Ausgabe:

```
[touch] GT9xx found at 0x5D, product: 911
[touch] Touch range: 1872 x 1404, display: 1872 x 1404
[touch] raw=(468,302) screen=(468,302)
```

---

## 8. Batterie

| Was | Wert |
|---|---|
| ADC-Pin | **GPIO1** |
| Enable-Pin | **GPIO40** (E1003; E1001/E1002/E1004: GPIO21) |
| Auflösung | 12 bit |
| Dämpfung | `ADC_11db` |
| Spannungsteiler | **×2** |
| Voll (LiPo) | ≈ 4,2 V |
| Leer | ≈ 3,3 V |

```c
pinMode(BATTERY_ENABLE_PIN, OUTPUT);
digitalWrite(BATTERY_ENABLE_PIN, HIGH);
delay(5);                                  // Getting-Started nennt 10 ms als "präziser"
analogReadResolution(12);
analogSetPinAttenuation(BATTERY_ADC_PIN, ADC_11db);
int mv = analogReadMilliVolts(BATTERY_ADC_PIN);
digitalWrite(BATTERY_ENABLE_PIN, LOW);     // Teiler nur während der Messung speisen
float batteryVoltage = (mv / 1000.0) * 2;  // ×2 wegen Spannungsteiler
```

Ohne die Verdopplung zeigt die Anzeige dauerhaft ~0 % (bei uns live
beobachtet). Unser `readBatteryMillivolts()` liefert **Millivolt** (`raw * 2`),
weil der Server daraus rechnet (`board_battery_percent_from_mv()`,
linear 3300 mV = 0 % … 4200 mV = 100 %).

---

## 9. Buzzer

**GPIO45** (alle Modelle).

```c
tone(BUZZER_PIN, 1000, 100);   // 1 kHz, 100 ms
noTone(BUZZER_PIN);
```

Musterfunktion aus der Wiki:

```c
void buzzer_tone(float freq, long duration, int silence) {
  tone(BUZZER_PIN, freq, duration);
  delay(duration);
  noTone(BUZZER_PIN);
  delay(silence);
}
```

Der Beispiel-Sketch definiert 88 Notenkonstanten `NOTE_C0` (16,35 Hz) bis
`NOTE_C8` (4186,01 Hz).

Signal-Konventionen laut Wiki: einfach = Bestätigung, doppelt = Warnung,
dreifach = Fehler, dauerhaft = kritisch.

---

## 10. LED

**E1003: GPIO16.** Logik **invertiert**.

```c
pinMode(LED_PIN, OUTPUT);
digitalWrite(LED_PIN, LOW);   // AN
digitalWrite(LED_PIN, HIGH);  // AUS
```

Von unserer Firmware derzeit nicht genutzt.

---

## 11. Umweltsensor SHT4x

| Was | Wert |
|---|---|
| I²C-Adresse | **0x44** |
| SDA / SCL | GPIO19 / GPIO20 |
| Bibliotheken | „Sensirion I2C SHT4x" + „Sensirion Core" |

```c
Wire.begin(19, 20);
SensirionI2cSht4x sht4x;
sht4x.begin(Wire, 0x44);
uint32_t serialNumber;  sht4x.serialNumber(serialNumber);

float temperature, humidity;
sht4x.measureHighPrecision(temperature, humidity);   // ~8,3 ms
```

Mindestens **5 s** Messabstand empfohlen.

Relevanz für uns: `EPaper::setTemp()`/`setHumi()` nehmen Callbacks — der
IT8951 nutzt die Temperatur zur Waveform-Wahl (`EPD_SET_TEMP` →
`setTconTemp()`). Wir setzen das derzeit nicht; Standardwert der Bibliothek
ist 16 °C. **Bei Ghosting-Problemen einen Versuch wert.**

---

## 12. Echtzeituhr PCF8563

| Was | Wert |
|---|---|
| Chip | PCF8563M/TR (NXP) |
| I²C-Adresse | **0x51** |
| SDA / SCL | GPIO19 / GPIO20, **400 kHz** |
| Quarz | 32,768 kHz |
| Puffer | **CR1220**-Knopfzelle, **nicht im Lieferumfang** |

### Register

```
0x00 CTRL1     Bit5 STOP hält die Uhr an
0x01 CTRL2     Alarm-/Timer-Flags
0x02 SECONDS   Bit7 = VL (Voltage Low), Bits6:0 Sekunden (BCD)
0x03 MINUTES   Bits6:0 (BCD)
0x04 HOURS     Bits5:0 (BCD)
0x05 DAYS      Bits5:0 (BCD)
0x06 WEEKDAYS  Bits2:0 (0 = Sonntag)
0x07 MONTHS    Bit7 = Jahrhundert, Bits4:0 Monat (BCD)
0x08 YEARS     Jahr im Jahrhundert (BCD 00–99)
0x0D CLKOUT    Bit7 FE aktiviert den Ausgang
```

### Ablauf

1. `Wire.begin(19, 20); Wire.setClock(400000UL);`
2. Chip bei `0x51` proben
3. STOP löschen: `reg(0x00) = 0x00`
4. Flags löschen: `reg(0x01) = 0x00`
5. CLKOUT abschalten: `reg(0x0D) = 0x00`
6. VL-Flag prüfen; wenn gesetzt, Zeit schreiben
7. Systemuhr via `settimeofday()` nachziehen

Das **VL-Flag** überlebt Stromausfälle: einmal mit gesunder Zelle gesetzt,
wird die Zeit bei Reboots **nicht** überschrieben.

| Methode | Aktivierung |
|---|---|
| Compile-Zeit (empfohlen) | `#define USE_COMPILE_TIME` → `__DATE__`/`__TIME__` |
| Manuell | auskommentieren, `INITIAL_*`-Konstanten füllen |
| Erzwingen | `#define FORCE_SET_TIME` → überschreibt bei jedem Boot |

Neukalibrierung: `FORCE_SET_TIME` einkommentieren, flashen, sofort wieder
auskommentieren, erneut flashen.

Relevanz für uns: Wir nutzen die RTC **nicht** — die Firmware holt die Zeit
per SNTP für die TLS-Prüfung, und alle angezeigten Zeitstempel kommen vom
Server. Eine bestückte CR1220 könnte den SNTP-Schritt einsparen.

---

## 13. microSD

| Pin | GPIO | Funktion |
|---|---|---|
| CS | 14 | Chip Select |
| MOSI | 9 | geteilt mit ePaper |
| MISO | 8 | geteilt mit ePaper |
| SCK | 7 | geteilt mit ePaper |
| DET | 15 | Kartenerkennung, **LOW = Karte vorhanden** |
| EN | **39** (E1003) | Stromversorgung |

Bus: **HSPI**. Dateisystem **FAT32**, max. 64 GB.

```c
SPIClass spiSD(HSPI);
pinMode(SD_EN_PIN, OUTPUT);
digitalWrite(SD_EN_PIN, HIGH);
spiSD.begin(SD_SCK_PIN, SD_MISO_PIN, SD_MOSI_PIN, SD_CS_PIN);
if (!SD.begin(SD_CS_PIN, spiSD)) { /* Fehler */ }
```

Teilt sich den SPI-Bus mit dem ePaper — Seeeds E1003-Beispiel holt sich die
Instanz über `epaper.getSPIinstance()`, beendet sie und startet sie mit den
SD-Pins neu.

Empfohlenes Prüfintervall für DET: 1 s.

---

## 14. PDM-Mikrofon (E1001/E1002/E1003, **nicht** E1004)

| Signal | GPIO | Funktion |
|---|---|---|
| PDM_CLK | **42** | Takt zum Mikrofon |
| PDM_DATA | **41** | 1-bit-Daten |
| MIC_PWR_EN | **38** | Versorgung, aktiv HIGH (TPS22916CYFPR) |

Braucht **ESP32-Arduino-Core ≥ 3.0** (`driver/i2s_pdm.h`, ESP-IDF 5.x).

- Abtastrate 8000 / 16000 / 44100 Hz, 16 bit PCM, mono, `I2S_NUM_0`
- DMA: 8 Deskriptoren à 512 Frames, Lesen in 1024-Byte-Blöcken
- Ablauf: GPIO38 HIGH → 50 ms → `i2s_new_channel()` →
  `i2s_channel_init_pdm_rx_mode()` → `i2s_channel_enable()` →
  **3 DMA-Puffer verwerfen** (Einschwingen des Dezimationsfilters) →
  `i2s_channel_read()` mit 200 ms Timeout
- Beispiel schreibt WAV (44-Byte-Header, Größe am Ende nachtragen) auf SD

Von uns nicht genutzt.

---

## 15. Deep Sleep / Stromsparen

| Zustand | CPU | Funk | RAM | RTC | Weckquellen |
|---|---|---|---|---|---|
| Aktiv | läuft | an | alles | an | – |
| Light Sleep | pausiert | aus | erhalten | an | GPIO, Timer |
| **Deep Sleep** | aus | aus | verloren (RTC-Bereich bleibt) | an | GPIO, Timer, Touch |

**Typischer Deep-Sleep-Verbrauch: ~14 µA.**

```c
esp_sleep_enable_ext1_wakeup(1ULL << PIN_WAKE_BTN, ESP_EXT1_WAKEUP_ANY_LOW);

// Pull-up für den Weckpin im RTC-Bereich halten:
rtc_gpio_pullup_en(static_cast<gpio_num_t>(PIN_WAKE_BTN));
rtc_gpio_pulldown_dis(static_cast<gpio_num_t>(PIN_WAKE_BTN));

esp_sleep_enable_timer_wakeup(30ULL * 60 * 1000000);   // optional, zusätzlich
esp_deep_sleep_start();                                 // kehrt nie zurück
```

Weckpin: **E1001/E1002/E1003 GPIO3**, E1004 GPIO4. GPIO- und Timer-Wake
dürfen gleichzeitig aktiv sein; der erste Auslöser gewinnt.
`RTC_DATA_ATTR`-Variablen überleben den Schlaf.
Weckursache: `esp_sleep_get_wakeup_cause()`.

> ⚠️ **Abweichung unserer `main.cpp`:** Wir rufen
> `rtc_gpio_pullup_en()` / `rtc_gpio_pulldown_dis()` **nicht** auf. Die
> normalen GPIO-Pull-ups sind im Deep Sleep nicht zwingend aktiv — ohne den
> RTC-Pull-up kann ein Weckpin floaten (Fehlweckungen) oder ein Druck nicht
> zuverlässig erkannt werden. Wir wecken zudem über eine Maske aus vier Pins
> (GPIO2/3/4/5); der RTC-Pull-up wäre für **jeden** davon nötig.

Empfohlener Ablauf für Batteriebetrieb: Wake → RTC lesen → Sensoren →
WLAN → ePaper aktualisieren → Deep Sleep.

---

## 16. Bild-Pipeline SD → ePaper

Fünf fertige Beispiele in Seeed_GFX unter
`examples/ePaper/reTerminal_SDcard_Bitmap/`. Für uns relevant:
**`reTerminal_E1003_SDcard_Gray16`** (1872 × 1404, 4 bit / 16 Graustufen).

Formate: JPEG (Baseline 8 bit), BMP (24 bit BGR oder 4 bit indiziert), PNG.
Erkennung über Magic Bytes (`FF D8`, `BM`, `89 50 4E 47`).

### Dithering

| Enum | Verfahren | Tempo | Qualität | Speicher | Einsatz |
|---|---|---|---|---|---|
| `DITHER_NONE` | Nearest Color | am schnellsten | posterisiert | minimal | Diagnose |
| `DITHER_BAYER8` | 8×8 Bayer | schnell | gut | **keiner** | **sichere Wahl für E1003** |
| `DITHER_FS` | Floyd-Steinberg | mittel | bestes Verhältnis | hoch | Fotos |
| `DITHER_JARVIS` | Jarvis-Judice-Ninke | langsam (3× FS) | weicher | sehr hoch | Höchste Qualität |
| `DITHER_ATKINSON` | Atkinson | mittel | kontrastreich | hoch | Strichzeichnungen |

### Speicherbudget (8 MB OPI-PSRAM, ~7,9 MB nutzbar)

| Panel | RGB888 | FS-Fehlerpuffer | FS möglich? |
|---|---|---|---|
| E1001 800×480 | 1,1 MB | 1,5 MB | ✅ |
| E1002 800×480 | 1,1 MB | 4,6 MB | ✅ |
| **E1003 1872×1404** | **7,5 MB** | **10,1 MB** | ❌ **BAYER8 nutzen** |
| E1004 1200×1600 | 5,5 MB | 22,0 MB | ❌ BAYER8 |

Für E1003 Quellbilder auf **≤ 1200 × 900** vorskalieren, wenn FS gewünscht
ist. Schlägt die Pufferallokation fehl, fällt der Loader automatisch auf
`DITHER_NONE` zurück.

### Layout

Anker (9 Positionen): `ANCHOR_TOP_LEFT` … `ANCHOR_BOTTOM_RIGHT`,
Standard `ANCHOR_CENTER`.
Skalierung: `FIT_ORIGINAL` (sicher), `FIT_CONTAIN` (nur verkleinern),
`FIT_SCALE` (× `DISPLAY_SCALE`).
`DISPLAY_SCALE > 1.0` riskiert auf E1003 einen Speicherüberlauf.

Bei 4 bpp müssen Breite und Höhe **gerade** sein (Nibble-Ausrichtung), und
die X-Position wird auf gerade Werte abgerundet.

---

## 17. Pin-Gesamttabelle E1003

| GPIO | Funktion |
|---|---|
| 1 | Batterie-ADC |
| 2 | Touch INT |
| 3 | KEY0 (grün, rechts) — Wake |
| 4 | KEY1 (Mitte) |
| 5 | KEY2 (links) |
| 6 | J2 Pin 4 (GPIO/ADC1) |
| 7 | SPI SCK (ePaper + SD) |
| 8 | SPI MISO (ePaper + SD) |
| 9 | SPI MOSI (ePaper + SD) |
| 10 | ePaper CS |
| 11 | ePaper ENABLE (TFT_ENABLE) |
| 12 | ePaper RST |
| 13 | ePaper BUSY |
| 14 | SD CS |
| 15 | SD DET (LOW = Karte da) |
| **16** | **LED** (invertiert) |
| 19 | I²C0 SDA (GT911, PCF8563, SHT4x) + J2 Pin 6 |
| 20 | I²C0 SCL + J2 Pin 5 |
| 21 | ITE_ENABLE |
| 38 | Mikrofon-Versorgung (aktiv HIGH) |
| **39** | **SD-Enable** |
| **40** | **Batterie-Enable** |
| 41 | PDM DATA |
| 42 | PDM CLK |
| 43 | UART1 TX (Debug) |
| 44 | UART1 RX (Debug) |
| 45 | Buzzer |
| 47 | J2 Pin 3 (GPIO/ADC) |
| 48 | Touch RESET |
| – | ePaper DC: **nicht vorhanden** (`-1`) |

I²C0-Adressen: GT911 `0x5D`/`0x14`, PCF8563 `0x51`, SHT4x `0x44`.

---

## 18. Flashen

Kein manueller Bootloader-Eintritt nötig — esptool resettet über RTS/DTR:

```bash
pio run -e esp32dev -t upload --upload-port /dev/cu.wchusbserial<N>
```

> Die Prozedur aus dem Memory `project_esp32_epaper_flashing` (BOOT+RESET
> halten, `esptool --no-stub`) gilt für das **alte Waveshare-ESP32-D0WDQ6-
> Prototypboard**, nicht für das E1003.

Stolperstellen:

- Ein offener `pio device monitor` oder ein hängengebliebener Python-Prozess
  blockiert den Port („could not exclusively lock port"). Vorher `lsof
  /dev/cu.wchusbserial*` prüfen.
- Schläft das Gerät, schlägt das Flashen fehl — Refresh-Taste drücken und
  erneut versuchen.

**Alternative:** Seeeds browserbasierter **SenseCraft HMI Firmware Flasher**
ist der offiziell dokumentierte Weg; Arduino/PlatformIO ist für dieses Board
nicht offiziell dokumentiert, funktioniert aber.

---

## 19. Offene Punkte unserer Firmware

Aus dieser Referenz abgeleitet, Stand 2026-08-21 — noch nicht umgesetzt:

1. **GT911-Reset zu kurz** — 10 ms/50 ms statt vorgegebener **20 ms/120 ms**
   (§7). Heißester Kandidat dafür, dass Touch nicht antwortet.
2. **GT911-Probe über Statusregister** statt **Produkt-ID `0x8140`** (§7).
3. **Touch-INT als `INPUT`** statt `INPUT_PULLUP` (§7).
4. **Touch-Max-X/Y werden nicht gelesen**, Umrechnung ist fest verdrahtet (§7).
5. **`rtc_gpio_pullup_en()` fehlt** für alle vier Weckpins (§15).
6. **Panel-Temperatur wird nicht gesetzt** — `EPaper::setTemp()` bleibt beim
   Standard 16 °C, obwohl ein SHT4x verbaut ist (§11). Kandidat für die
   Ghosting-/Artefaktprobleme.
7. **Artefakte an Partial-Update-Rändern** bestehen trotz
   `clearAlignedForPartial()` weiter. Verbleibende Verdachtsmomente:
   DU-Modus-Ghosting (§5.5) und die fehlende Temperaturangabe (Punkt 6).
   Gegenprobe über einen erzwungenen Vollbild-Refresh (linke Taste) steht
   noch aus.
