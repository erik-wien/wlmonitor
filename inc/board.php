<?php
/**
 * inc/board.php — Aufbereitung der Monitordaten für den Board-Endpunkt.
 *
 * Ausschliesslich reine Funktionen: Eingabe Daten, Ausgabe Daten. Kein Netz,
 * keine DB, keine Superglobals — damit ist die Filtersemantik ohne laufende
 * Infrastruktur testbar.
 *
 * Bildet web/js/wl-monitor.js:325-330 nach. Solange der Browser-Filter noch
 * existiert, sind das zwei Implementierungen derselben Regel; Abweichungen
 * hier wären auf dem Display unsichtbar falsch.
 */
declare(strict_types=1);

/** Schlüssel, die monitor_get() neben den Stationen in dieselbe Map legt. */
const BOARD_META_KEYS = ['alerts', 'trains', 'update_at', 'api_ping'];

/**
 * Stationen aus der monitor_get()-Map lösen — als Liste, nicht als Map.
 *
 * monitor_get() mischt Stationen (Schlüssel = DIVA) und Metadaten in einer
 * flachen Struktur. Ein Client müsste sonst raten, was eine Station ist.
 *
 * @return list<array{id: string, diva: string, station_name: string, lines: array}>
 */
function board_stations_only(array $monitor): array
{
    $out = [];
    foreach ($monitor as $key => $value) {
        if (in_array($key, BOARD_META_KEYS, true)) continue;
        if (!is_array($value) || !isset($value['lines']) || !is_array($value['lines'])) continue;
        $out[] = $value;
    }
    return $out;
}

/**
 * Zeilen entdoppeln. Identität ist (Linie, Steig, Ziel).
 *
 * Am Westbahnhof liefert die Kette "U3 Steig 1 Simmering" zweimal (beobachtet
 * 2026-08-01). Woher das kommt, ist offen — auf dem Display wäre es eine
 * doppelte Zeile, also wird hier entdoppelt.
 */
function board_dedupe_lines(array $lines): array
{
    $gesehen = [];
    $out     = [];
    foreach ($lines as $l) {
        $key = ($l['name'] ?? '') . "\0" . ($l['platform'] ?? '') . "\0" . ($l['towards'] ?? '');
        if (isset($gesehen[$key])) continue;
        $gesehen[$key] = true;
        $out[] = $l;
    }
    return $out;
}

/**
 * Filter einer Haltestelle anwenden.
 *
 * $stationFilter ist die Positivliste dieser DIVA aus filter_json, oder null,
 * wenn die DIVA dort NICHT vorkommt — dann gilt: kein Filter, alle Linien.
 *
 * Rückgabe null bedeutet: Karte entfällt. Das passiert NUR bei ungefilterten
 * Stationen ohne Linien. Eine gefilterte Station bleibt auch leer stehen —
 * verschwände sie, sähe der Ausfall ihrer einzigen Linie aus wie Normalbetrieb.
 *
 * @param ?list<array{line: string, platform: string|int}> $stationFilter
 */
function board_filter_station(array $station, ?array $stationFilter): ?array
{
    $lines = board_dedupe_lines($station['lines'] ?? []);

    if ($stationFilter !== null) {
        $lines = array_values(array_filter($lines, static function (array $l) use ($stationFilter): bool {
            foreach ($stationFilter as $f) {
                if (($f['line'] ?? null) === ($l['name'] ?? null)
                    && (string) ($f['platform'] ?? '') === (string) ($l['platform'] ?? '')) {
                    return true;
                }
            }
            return false;
        }));
    } elseif ($lines === []) {
        return null;
    }

    $station['lines'] = $lines;
    return $station;
}
