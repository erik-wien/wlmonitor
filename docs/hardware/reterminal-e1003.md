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

### ⚠️ Strapping-Pins des ESP32-S3

Der ESP32-S3 wertet beim Reset vier Pins als **Strapping-Pins** aus:

| GPIO | Strapping-Funktion | Belegung am E1003 |
|---|---|---|
| 0 | Boot-Modus | – |
| **3** | JTAG-Quellwahl | **KEY0** (grüne Taste, Weckpin) |
| **45** | `VDD_SPI`-Spannung (0 = 3,3 V, 1 = 1,8 V) | **Buzzer** |
| 46 | Boot-Modus / ROM-Meldungen | – |

Zwei davon sind auf diesem Board mit Bedienelementen belegt. Relevant, weil
der Pegel **im Moment des Resets** zählt:

- **GPIO45 (Buzzer):** liegt der Pin beim Reset HIGH, wählt der Chip 1,8 V
  für `VDD_SPI`. Der externe Flash läuft aber mit 3,3 V — der Chip startet
  dann nicht und bleibt auf UART0 stumm. `tone()` darf den Pin also nicht
  in einem HIGH-Zustand hinterlassen; nach jedem Ton `noTone()` **und** den
  Pin definiert nach LOW ziehen.
- **GPIO3 (KEY0):** beim Reset gedrückt (= LOW) verändert das die
  JTAG-Quellwahl.

Seeeds Referenzschaltung dürfte das über Pulldowns abfangen; verifiziert
ist das hier nicht. Bei einem Board, das plötzlich weder bootet noch
flashbar ist, gehören diese beiden Pins zu den ersten Verdächtigen.

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

## 19. Ehemals offene Punkte — alle erledigt (Stand 2026-08-22)

Die am 2026-08-21 aus dieser Referenz abgeleitete Mängelliste ist vollständig
abgearbeitet; hier nur noch als Kurzprotokoll, weil die Ursachen lehrreich waren:

1. **GT911-Reset zu kurz** (10/50 ms statt 20/120 ms) — behoben. Der eigentliche
   Fehler saß aber woanders, s. Punkt 2.
2. **Touch antwortete gar nicht** → zwei unabhängige Ursachen: (a) die
   Reset-Sequenz setzte INT auf `INPUT_PULLUP` *vor* dem Reset statt ihn
   *während* des Resets LOW zu treiben — dadurch wählte der Controller die
   falsche I²C-Adresse; (b) der Punktdatensatz wurde ab `0x814F` mit **4 Bytes**
   gelesen, wodurch das Track-ID-Byte als X-Low interpretiert wurde. Korrekt
   sind **8 Bytes** mit `rawX = b[1]|b[2]<<8`, `rawY = b[3]|b[4]<<8`.
3. Probe über Produkt-ID `0x8140` — umgesetzt.
4. Touch-Max-X/Y werden gelesen — umgesetzt (liefert 1872×1404).
5. `rtc_gpio_pullup_en()` für die Weckpins — umgesetzt.
6. Panel-Temperatur aus dem SHT4x — umgesetzt (`applyPanelTemperature()`).
7. **Partial-Update-Artefakte** — behoben durch `clearAlignedForPartial()`
   (§5.5). Ein *späterer*, davon unabhängiger Artefakt-Fall ist in §20.3
   beschrieben und war **kein** Ghosting.

---

## 20. Fallen in Seeed_GFX / ESP32-Core (am Gerät verifiziert)

Alles hier wurde gegen den Bibliotheks-/Core-Quellcode geprüft **und** am echten
Board gemessen. Diese Punkte sind nicht dokumentiert und kosten sonst Stunden.

### 20.1 `~HTTPClient()` ruft **bedingungslos** `_client->stop()`

`HTTPClient.cpp` (arduino-esp32), Destruktor:

```c
HTTPClient::~HTTPClient() {
    if(_client) { _client->stop(); }   // ignoriert _reuse / _canReuse komplett
```

**Folge:** `http.setReuse(true)` ist wirkungslos, solange der `HTTPClient`
eine **lokale** Variable ist — sein Destruktor reißt den Socket bei jeder
Rückkehr ab. Für Keep-Alive müssen **beide** Objekte die Funktion überleben,
`WiFiClientSecure` *und* `HTTPClient`.

Am Gerät gemessen (wlmonitor, 1872×1404-Vollbild über Cloudflare):

| | ohne Reuse | mit Reuse (beide persistent) |
|---|---|---|
| TCP+TLS connect | 1590 ms | 0 ms (wiederverwendet) |
| GET | 2338 ms | 580–920 ms |
| **gesamt** | **~3930 ms** | **~700 ms** |

Mehrfaches `begin()`/`collectHeaders()` auf demselben Objekt ist unkritisch:
`begin()` setzt nur `_client`+URL neu, `_canReuse` wird pro Antwort neu gesetzt,
`collectHeaders()` gibt das alte Array vorher frei.

### 20.2 `WiFiClient::flush()` leert **nur den lokalen RX-Puffer**

```c
void WiFiClient::flush() { if (_rxBuffer) _rxBuffer->flush(); }
```

`HTTPClient::disconnect()` ruft das zwar auf, wenn noch Daten anliegen — Bytes,
die **noch unterwegs** sind, treffen danach aber weiter ein. Wird ein Body nicht
vollständig gelesen (Timeout, Abbruch, ungelesener Fehler-Body), bleibt der
Socket schmutzig und der **nächste** Request liest die Reste als seine eigenen
HTTP-Header → Müll. Ohne Keep-Alive harmlos (Socket wurde verworfen), mit
Keep-Alive verwurstet es die ganze Session. Gegenmittel: bei unvollständig
gelesenem Body einen Neuaufbau erzwingen (`connectionDirty` in `board_client.cpp`).

### 20.3 Der Server-Diff weiß nichts von lokal gezeichneten Overlays

Kein Ghosting, sondern **State-Drift**: Der Server berechnet Patches als Diff
gegen *sein eigenes* letztes Bild. Alles, was die Firmware lokal aufs Panel malt
(Fehler-Banner, Touch-Blob, Status-/Build-Marker), kennt er nicht. Bleibt so eine
Fläche serverseitig unverändert, nimmt sie **kein künftiger Patch je wieder mit**
— die lokale Zeichnung steht dann dauerhaft, auch wenn ihr Anlass längst weg ist.

Symptome am Gerät: Reste im Logo-Bereich (dort sitzt der Fehler-Banner), halb
verdeckte Stationsnamen, zerschnittene Pagination. Ein Vollbild räumt immer auf.
Gegenmittel in unserer Firmware: `rtcBannerShown` erzwingt ein Vollbild nach
einem Banner, `rtcPatchesSinceFull` alle 6 Patches, plus ein Vollbild vor dem
Einschlafen.

### 20.4 `getPointer()` == `_img8`, Vollbild ist `memcpy`-fähig

`TFT_eSprite::getPointer()` liefert `_img8_1`; bei `createSprite(w,h,1)` gilt
`_img8_1 = _img8` (Frame-Umschaltung gibt es nur mit 2 Frames). `EPaper::
drawBufferPixel(x,y,c,1)` macht exakt `_img8[y*(_width/8) + (x/8)] = c`.

Für ein Vollbild sind Quell- und Ziel-Layout damit **byteweise identisch** →
ein einziges `memcpy` statt 328.536 Einzelaufrufen. Für Patches: ein `memcpy`
pro Zeile (Ziel gestrided mit `_width/8`, Quelle fortlaufend). Voraussetzung ist
`x % 8 == 0`.

> **Grenzprüfung nicht vergessen:** `x/y/w/h` kommen aus Server-Headern und
> werden von `validateBoardResponse()` bewusst nur gegen `≤ 100000` geprüft,
> **nicht** gegen die Panelgröße. Als Einzelbyte-Schleife schon schlecht, als
> `memcpy` ein zusammenhängender Überschreiber hinter dem Puffer → Heap-Korruption,
> Gerät braucht einen Power-Cycle.

### 20.5 Tastenbelegung unserer Firmware (Stand 2026-08-23)

| Taste | GPIO | schlafend | wach, Abfahrten/Störungen | wach, **Schlafschirm** |
|---|---|---|---|---|
| **grün**, rechts | 3 | weckt auf | Vollbild-Update erzwingen | **sofort schlafen** |
| weiß, Mitte | 4 | — | Seite weiter | Seite zurück (letzte Seite) |
| weiß, links | 5 | — | Seite zurück | — |

Langer Druck (≥3 s) auf grün **beim Boot** → WLAN/Token-Reset (WiFiManager-Portal).

> **Doppelbelegung der grünen Taste** (Nutzerwunsch 2026-08-23: „wie man die
> Tasten doppelt belegen kann um ein bewusstes ‚geh schlafen‘ zu veranlassen"):
> auf dem Schlafschirm ist „Vollbild-Update" bedeutungslos — es gibt keine
> Abfahrten zu aktualisieren. Ein Druck bricht dort stattdessen sofort in den
> Tiefschlaf ab (`break` aus der Eingabeschleife in `runActiveSession()`),
> statt den Rest von `ACTIVE_IDLE_TIMEOUT_MS` zu warten. Erkannt wird der
> Zustand über `showingSleepPage` (`X-Board-Is-Sleep-Page`-Header, aktualisiert
> sich nach jedem erfolgreichen Abruf), nicht über einen eigenen Tastenmodus.

> **Falle:** Weil Wecken und Vollbild-Update auf demselben Pin liegen, darf
> `isFullUpdateButtonHeld()` **nicht** beim Boot geprüft werden — jeder normale
> Weck-Druck würde sonst die Session mit einem erzwungenen Vollbild starten.
> Die Taste zählt erst innerhalb der Aktiv-Session als „Vollupdate".

### 20.6 Touch-Entprellung: Press/Release statt Zeit/Distanz

Ein Zeitfenster (z. B. 3 s ab Erkennung) funktioniert **nicht**: allein der Fetch
dauert 0,7–4 s, das Fenster läuft ab, während der Finger noch aufliegt → zweiter
Piep + Blob bei jedem Refresh. Richtig ist ein echter Press/Release-Zustand: eine
Berührung zählt erst wieder als neu, wenn der Sensor mehrmals in Folge (gegen
Flackern, real als vereinzelte `status=0x80` beobachtet) **keinen** Touch meldete.

Die Trefferzone der Favoritenleiste reicht bis **y=1404** (physischer
Bildschirmrand), nicht nur bis zur sichtbaren Buttonkante bei y=1394 — echte
Finger-Taps landen sonst knapp darunter im Leeren.

### 20.7 Statusleiste: geteiltes Raster, Symbole zweimal gezeichnet

Die Statusleiste am Fuß der Wetterspalte wird **von beiden Seiten** beschrieben:
der Server rendert den Ruhezustand in jeden Frame, die Firmware übermalt sie
lokal für Zustände, die der Server nicht kennen kann. Beide müssen deshalb
dasselbe Raster benutzen — Konstanten `BOARD_STATUS_*` in
`inc/board_template.php` gegen `STATUS_*` in `src/display.cpp`.

| Band (y 1256–1306) | x | Inhalt | wer zeichnet |
|---|---|---|---|
| Status | 1150–1600 | Piktogramm + kurzes Wort (24px) | Server (`Bereit`), Firmware (`Lade …`, `Vollbild`, `Schlaf`) |
| Marker | 1608–1864 | `fw<N>` + Modus-Kästchen | nur Firmware |

Das Modus-Kästchen ist **gefüllt** nach einem Vollbild und **hohl** nach einem
Patch — die schnellste Antwort auf „ist das gerade ein sauberes Bild?".

> **Falle:** `clearAlignedForPartial()` rundet x **nach außen** auf 8-px-Grenzen
> (s. §5.5, „8-px-Rundung"), aus x=1150 wird also 1144. Der Bereich muss frei bleiben —
> die Spaltentrennlinie liegt bei x=1113, das geht sich aus, aber wer die
> Statusleiste nach links schiebt, radiert sie mit.

Die Symbole existieren **doppelt**: als SVG-Grundformen
(`board_render_status_icon_svg()`) und als GFX-Primitiven (`drawStatusIcon()`).
Bewusst keine Bitmap-Assets — aber wer eine Form ändert, muss beide ändern,
sonst springt das Piktogramm sichtbar, sobald die Firmware nach einem Patch
lokal übermalt. Aus demselben Grund liegt die Firmware-Schrift bei
`FreeSans12pt7b`/`setTextSize(1)` (≈17px Versalhöhe ≈ Atkinson 24px) und nicht
mehr bei `FreeSansBold9pt7b`/`setTextSize(2)` (≈24px — das war der „zu wuchtig"-
Befund vom 2026-08-22).

### 20.8 Jeder Panel-Schreibvorgang kostet ~500 ms Grundgebühr

**Am Gerät gemessen (fw43/fw45, 2026-08-22).** Die Dauer eines Updates hängt
weit weniger von der Fläche ab, als man annehmen würde:

| Fläche | Modus | Dauer |
|---|---|---|
| 40×448 px | DU (Patch) | 557 ms |
| 960×1255 | DU | 642 ms |
| 1640×1255 | DU | 755 ms |
| **1872×1404 (Vollbild)** | GC16 | **1024 ms** |
| 450×50 (Status-Overlay) | DU | 490 ms |
| 256×50 (Firmware-Marke) | DU | **1104 ms** |

Ein 256×50-Rechteck kostete also **mehr als das ganze Panel**. Die Fixkosten
stecken in `wake()`/`sleep()` und dem Controller-Handshake, die jedes
`updataPartial()` mitbezahlt — nicht in den Pixeln.

**Konsequenz 1: Teilaktualisierungen abgeschafft.** Sie waren die Ursache der
Darstellungsfehler (doppelte Zeichen, angefressene Buchstaben — DU kennt keinen
vollen Waveform-Durchlauf) und sparten dabei fast nichts: höchstens ~470 ms
Panel-Zeit, während ein Patch ein *zweites* Status-Overlay erzwingt (nur das
Vollbild bringt „Bereit" serverseitig mit). Am Netz war ebenfalls nichts zu
holen — der GET dauert 600–820 ms weitgehend unabhängig von 2 KB oder 257 KB,
weil die Server-Renderzeit dominiert, und die Patches deckten typisch 150–257 KB
von 328 KB ab.

**Konsequenz 2: lokale Overlays sind teuer, nicht billig.** Alles, was ohnehin
im Server-Frame stehen kann, gehört dorthin. Die Firmware-Marke wandert per
Header `X-Device-Firmware` in `board_render_firmware_marker_svg()` und kostet im
Bild exakt nichts. Übrig bleibt ein einziges lokales Overlay („Lade …"), weil es
*während* des Requests sichtbar sein muss.

Zykluszeiten über diesen Umbau (Aktiv-Session, 25-s-Refresh):

| Stand | Zyklus | Artefakte |
|---|---|---|
| Patches (fw42) | 2,9–6,2 s | ja |
| nur Vollbilder (fw44) | 4,77 s | nein |
| + Marke serverseitig (fw46) | **3,64 s** | nein |

Aufschlüsselung fw46: Overlay „Lade …" 494 · GET 598 · Body lesen (328 KB)
1490 · Panel 1024 ms. Der größte verbliebene Posten war der **Body-Download mit
~224 KB/s** — behoben in §20.9.

### 20.9 Rumpf-Kompression: rohes Deflate, Entpacker auf den Heap

1bpp-Bilddaten sind fast leer und lassen sich extrem gut packen. Am echten
Frame gemessen (`gzdeflate`, 1872×1404):

| Stufe | Größe | Anteil | Server-CPU |
|---|---|---|---|
| 1 | 21.258 B | 6,5 % | 0,8 ms |
| **6** | **18.753 B** | **5,7 %** | **4,2 ms** |
| 9 | 15.095 B | 4,6 % | 18,3 ms |

Stufe 6 gewählt: Stufe 9 spart nochmal 3,6 KB (≈16 ms Transfer), kostet aber
14 ms mehr CPU — das hebt sich auf.

**Rohes Deflate, kein `Content-Encoding`.** Zwischen Gerät und Server hängt ein
Cloudflare-Tunnel, der standardkonform komprimierte Antworten umpacken oder
auspacken darf. Eigene Header (`X-Device-Accept-Encoding` hin,
`X-Board-Encoding` / `X-Board-Raw-Length` zurück) machen den Rumpf zu opaken
Bytes, an denen unterwegs niemand dreht. Roh (statt zlib-/gzip-Rahmen), weil
tinfl es so ohne Zusatzflags nimmt.

> ⚠️ **`tinfl_decompress_mem_to_mem()` sprengt den Stack.** Sie legt ihren
> `tinfl_decompressor` als lokale Variable an — der ist mit drei
> `tinfl_huff_table` à 3488 B rund **10,9 KB** groß, der Arduino-`loopTask` hat
> **8 KB**. Ergebnis am Gerät (fw47): sofort `Guru Meditation Error … Stack
> canary watchpoint triggered (loopTask)` und Dauer-Reboot. Richtig ist die
> Low-Level-API `tinfl_decompress()` mit einem selbst per `malloc()`
> beschafften Zustand. Der Entpacker steckt im **ESP32-S3-ROM** und kostet
> deshalb kein Flash.

> ⚠️ **`Content-Length` beschreibt danach den falschen Puffer.** Sie meldet den
> *gepackten* Rumpf (~24 KB), `validateBoardResponse()` prüft aber gegen den
> *entpackten* (328.536 B) — ohne Korrektur wird jede komprimierte Antwort als
> unvollständig verworfen (fw48: `outcome=3, mode=full 0x0`).

Ergebnis: Body lesen **1490 → 93 ms**, Entpacken **27 ms**.

### Zykluszeit über den gesamten Umbau

| Stand | Zyklus | Artefakte |
|---|---|---|
| Patches (fw42) | 2,9–6,2 s | ja |
| nur Vollbilder (fw44) | 4,77 s | nein |
| + Marke serverseitig (fw46) | 3,64 s | nein |
| + Rumpf gepackt (fw49) | **2,30 s** | nein |

Aufschlüsselung fw49: Overlay „Lade …" 495 · GET 627 · Body lesen 93 ·
Entpacken 27 · Panel 1024 ms. Damit ist das **Panel-Schreiben selbst mit 45 %
der größte Posten** und eine harte Untergrenze; danach käme das verbliebene
lokale Overlay.

### 20.10 Schlafschirm als strukturell letzte Seite (2026-08-23)

Der Schlafschirm (§20.9-Nachbarn: eigenes Layout in `inc/board_sleep.php`,
Wetter heute/morgen + Gäste-WLAN-QR) war zunächst nur über einen eigenen
Geräte-Header (`X-Device-Screen: sleep`) erreichbar — ausschließlich der
erzwungene Abruf vor dem Tiefschlaf konnte ihn zeigen. Nutzerwunsch
2026-08-23: „der Schlafschirm ist immer die letzte Seite zum Blättern, damit
ich den Schirm auch absichtlich aufrufen kann."

**Ein Slot, zwei Zugänge.** `board_render_svg()` zählt seither IMMER einen
Slot mehr als Abfahrten+Störungen (`board_total_pages()`), und `$requestedPage
> $totalContentPages` rendert dort `board_sleep_render_svg()` statt der
Abfahrten-/Störungsseite. Landet man dort per bewusstem `page_next` ODER per
erzwungenem Header, kommt **pixelidentischer** Inhalt heraus — ein
Integrationstest rendert beide Wege und vergleicht sie.

> **Falle: Persistenz des erzwungenen Abrufs.** `web/board.php` speichert
> `activePage` für den nächsten Poll. Würde der erzwungene Vorschlaf-Abruf
> naiv `$requestedPage = $totalPages` setzen und DAS persistieren, stünde
> beim nächsten Aufwachen wieder der Schlafschirm da — unabhängig davon, wo
> der Nutzer zuletzt tatsächlich war. Gegenmittel: eine zweite Variable
> (`$renderPage`) nur fürs Rendern, `$requestedPage` bleibt unangetastet und
> wird gespeichert. Ein Integrationstest fährt genau dieses Szenario (Abruf 1
> normal, Abruf 2 erzwungen, Abruf 3 wieder normal → muss wieder Abfahrten
> zeigen).

> **Falle: `$totalContentPages` in der falschen Datei referenziert.** Die
> Variable existierte zunächst nur *innerhalb* von `board_render_svg()`
> (`inc/board_template.php`) — `web/board.php` bezog sich beim Setzen des
> `X-Board-Is-Sleep-Page`-Headers versehentlich auf eine in dieser Datei nie
> definierte gleichnamige Variable. PHP wertet den Vergleich mit einer
> undefinierten Variable trotzdem aus (Warning, Wert `null`), `$renderPage >
> null` ist für jede Seite ≥ 1 wahr — der Header stand also auf **jeder**
> Seite, nicht nur auf dem Schlafschirm. Sichtbar wurde es nur, weil
> `display_errors` unter `php-cgi` die Warnung in den Bildkörper schrieb und
> ein Content-Length-Test genau darauf anschlug. Lehre: Variablen, die eine
> Funktion lokal berechnet, nicht stillschweigend im Aufrufer als vorhanden
> annehmen — grep bei jeder neuen Kreuzreferenz zwischen `board.php` und
> `board_template.php`.

Damit steht `X-Board-Total-Pages` nie mehr auf `1` — mindestens Abfahrten +
Schlafschirm. Ältere Firmware-/Testannahmen, die `totalPages == 1` erwarten,
sind bewusst nicht mehr gültig (s. `board_render_stand_and_pagination_svg()`s
`totalPages <= 1`-Zweig: praktisch tot, als Schutz für Direktaufrufe stehen
gelassen).

**Doppelbelegung der grünen Taste** für „jetzt schlafen" auf dem
Schlafschirm: s. §20.5. Erkannt über den neuen Header `X-Board-Is-Sleep-Page`
(analog zu `X-Board-Snapshot-Requested`: außerhalb des strikt geprüften
`ParsedBoardResponse`-Protokolls, ein optionaler Seitenkanal).

### 20.11 Seitenzahlen-Pille ohne Pfeile, rechtsbündig verankert (2026-08-23)

Nutzerwunsch: „Paginierungs-Schaltfläche bitte 50% größer, aber ohne weitere
Pfeile. Nur Seitennummern." Manche Maße konnten die volle Vorgabe umsetzen,
andere nicht — dokumentiert, damit niemand später „50 %" wörtlich
nachrechnet und einen Bug vermutet:

| Maß | vorher | nachher | Faktor |
|---|---|---|---|
| Slotbreite | 58px | 87px | **1,5×** (voll) |
| Schrift | 24px | 30px | 1,25× |
| Kreisradius | 20px | 24px | 1,2× |
| Pillenhöhe | 48px | 56px | 1,17× |

Slotbreite und Schrift bekommen die vollen 50 % (bzw. 25 %), weil der
Pfeil-Wegfall echten Platz freimacht. Höhe und Radius sind **hart** durch das
60-px-Band begrenzt, in dem die Pille sitzt (`BOARD_DEPARTURES_MAX_Y=1250`
bis zur Trennlinie bei y=1310) — ein wortwörtlich 50 % größerer Kreis
(r=30, Ø 60) würde das Band randlos ausfüllen, ohne jeden Rand zur
Trennlinie oder zum letzten Abfahrten-Badge.

**Rechtsbündig statt linksbündig.** Seit §20.10 ist `totalPages` nie mehr 1,
oft 3–5 (Abfahrten + evtl. Störungen + Schlafschirm). Eine linksbündig ab
x=793 wachsende Pille wäre bei 4+ Seiten und 87px-Slots über die
Spaltentrennlinie (x=1113) hinausgewachsen. Die Pille verankert deshalb ihr
**rechtes** Ende an x=1083 (rechter Rand der Abfahrtenspalte) und wächst bei
mehr Seiten nach **links** — Formel `pillWidth = totalPages*87+20`,
`pillStartX = 1083-pillWidth`.

> **Geteiltes Raster, wie immer bei diesem Gerät.** Die Geometrie steht in
> `BOARD_PAGINATION_*`-Konstanten (`inc/board_template.php`) UND in
> `PAGINATION_*`-Konstanten (`touch_zone.cpp`) — identische Formel an beiden
> Stellen, sonst tippt der Nutzer auf eine Zahl, die visuell woanders sitzt
> als die Tipp-Zone der Firmware sie berechnet. Kein Renderer-seitiges
> Tippen auf einzelne Zahlen: der linke/rechte Pillenhalbraum sendet weiter
> unsichtbar `page_prev`/`page_next` — die physischen weißen Tasten bleiben
> der primäre, klar beschriftete Navigationsweg.
