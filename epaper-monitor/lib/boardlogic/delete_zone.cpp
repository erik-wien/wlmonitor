#include "delete_zone.h"
#include <cstdlib>
#include <cstring>

namespace {

// Liest eine vorzeichenlose Ganzzahl ab *p und schiebt *p dahinter.
// Gibt false zurueck, wenn dort keine Ziffer steht -- der Aufrufer verwirft
// den Eintrag dann, statt mit einer stillen 0 weiterzurechnen (eine Zone bei
// x=0 waere plausibel und der Fehler faellt nie auf).
bool leseZahl(const char*& p, int& out)
{
    if (*p < '0' || *p > '9') {
        return false;
    }
    int wert = 0;
    while (*p >= '0' && *p <= '9') {
        // Deckel gegen einen absurd langen Ziffernstrom im Header: das Panel
        // ist 1872x1404, alles darueber ist ohnehin Unsinn.
        if (wert < 100000) {
            wert = wert * 10 + (*p - '0');
        }
        ++p;
    }
    out = wert;
    return true;
}

} // namespace

int parseDeleteZones(const char* header, DeleteZone* out, int capacity)
{
    if (header == nullptr || out == nullptr || capacity <= 0) {
        return 0;
    }
    if (capacity > MAX_DELETE_ZONES) {
        capacity = MAX_DELETE_ZONES;
    }

    int anzahl = 0;
    const char* p = header;

    while (*p != '\0' && anzahl < capacity) {
        // --- Kennung bis zum ':' ---
        const char* idStart = p;
        while (*p != '\0' && *p != ':' && *p != ';') {
            ++p;
        }
        const size_t idLen = static_cast<size_t>(p - idStart);

        // Nur ein vollstaendiger Eintrag zaehlt: Kennung, Doppelpunkt, vier
        // Zahlen. Alles andere wird bis zum naechsten ';' verworfen.
        bool gueltig = (*p == ':') && idLen > 0 && idLen <= MAX_DELETE_ID_LEN;

        DeleteZone zone;
        if (gueltig) {
            memcpy(zone.id, idStart, idLen);
            zone.id[idLen] = '\0';
            ++p; // ueber ':'

            gueltig = leseZahl(p, zone.x)
                   && *p == ',' && (++p, leseZahl(p, zone.y))
                   && *p == ',' && (++p, leseZahl(p, zone.w))
                   && *p == ',' && (++p, leseZahl(p, zone.h));

            // Eine Zone ohne Flaeche kann nie getroffen werden -- als
            // fehlerhaft behandeln, damit sie keinen Platz belegt.
            if (gueltig && (zone.w <= 0 || zone.h <= 0)) {
                gueltig = false;
            }
        }

        if (gueltig) {
            out[anzahl++] = zone;
        }

        // Zum naechsten Eintrag vorruecken (auch nach einem Fehler).
        while (*p != '\0' && *p != ';') {
            ++p;
        }
        if (*p == ';') {
            ++p;
        }
    }

    return anzahl;
}

int findDeleteZone(const DeleteZone* zones, int count, int x, int y)
{
    if (zones == nullptr) {
        return -1;
    }
    for (int i = 0; i < count; ++i) {
        const DeleteZone& z = zones[i];
        if (x >= z.x && x < z.x + z.w && y >= z.y && y < z.y + z.h) {
            return i;
        }
    }
    return -1;
}
