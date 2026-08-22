#pragma once

// Umgebungstemperatur vom SHT4x (I2C 0x44) in Grad Celsius.
//
// Der IT8951 waehlt seine Waveform temperaturabhaengig; Seeed_GFX schickt bei
// JEDEM wake() einen Temperaturwert ans Panel, der ohne Zutun fest auf 16 C
// steht (EPaper-Konstruktor). Bei tatsaechlich abweichender Temperatur passt
// die Waveform nicht, was sich als unsauberes Loeschen/Ghosting zeigen kann.
// Siehe docs/hardware/reterminal-e1003.md §5.5 und §11.
//
// Setzt voraus, dass Wire bereits initialisiert ist (geschieht in
// initTouch(), gleicher Bus GPIO19/20). Liefert NAN, wenn der Sensor nicht
// antwortet oder ein unplausibler Wert herauskommt -- der Aufrufer laesst
// die Panel-Temperatur dann unveraendert.
float readAmbientTemperature();
