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
 * Zeitwert einer rohen Abfahrt (Form von monitor_get()) in Minuten, zum
 * Sortieren/Entdoppeln. '*' ("faehrt jetzt") zaehlt als 0, wie in
 * board_departure().
 */
function board_departure_time(array $dep): int
{
    $t = (string) ($dep['t'] ?? '0');
    return $t === '*' ? 0 : (int) $t;
}

/**
 * Zeilen entdoppeln. Identität ist (Linie, Steig, Ziel); die Abfahrten
 * gleicher Einträge werden zusammengeführt statt der Folgeeintrag verworfen —
 * sonst gehen echte Abfahrten verloren.
 *
 * Am Westbahnhof liefert die Kette "U3 Steig 1 Simmering" zweimal (beobachtet
 * 2026-08-01), und am 2026-08-02 live gemessen: "E3|1|Breitensee S" doppelt,
 * mit der einzigen 66-Minuten-Abfahrt NUR im zweiten Eintrag — ein simples
 * Verwerfen hätte sie verschluckt.
 */
function board_dedupe_lines(array $lines): array
{
    $out = [];
    foreach ($lines as $l) {
        $key = ($l['name'] ?? '') . "\0" . ($l['platform'] ?? '') . "\0" . ($l['towards'] ?? '');
        if (!isset($out[$key])) {
            $out[$key] = $l;
            continue;
        }
        $merged = array_merge($out[$key]['departures'] ?? [], $l['departures'] ?? []);
        usort($merged, static fn (array $a, array $b): int => board_departure_time($a) <=> board_departure_time($b));

        $ohneDubletten = [];
        $gesehen       = [];
        foreach ($merged as $dep) {
            $t = board_departure_time($dep);
            if (isset($gesehen[$t])) continue;
            $gesehen[$t] = true;
            $ohneDubletten[] = $dep;
        }
        $out[$key]['departures'] = $ohneDubletten;
    }
    return array_values($out);
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

// ───────────────────────────────────────────────────────────────────────────
// Antwortform
// ───────────────────────────────────────────────────────────────────────────

/**
 * Verkehrsmittel-Typ der WL-API auf vier Werte normalisieren.
 * Die WL-Kürzel (ptMetro, ptBusCity, ptTramWLB …) sind Aufrufer-Ballast.
 */
function board_type(string $wlType): string
{
    if (str_starts_with($wlType, 'ptMetro')) return 'metro';
    if (str_starts_with($wlType, 'ptTram'))  return 'tram';
    if (str_starts_with($wlType, 'ptBus'))   return 'bus';
    if (str_starts_with($wlType, 'ptTrain')) return 'train';
    return 'other';
}

/**
 * Eine Abfahrt in die schlanke Form bringen.
 *
 * 'in' ist eine Zahl in Minuten; "fährt jetzt" ist 0 (die alte API kodiert
 * das als String '*').
 *
 * 'towards' und 'line' erscheinen NUR bei Abweichung von der Zeile
 * (Kurzführung, Ersatzverkehr). Ohne sie zeigte das Display „U6 →
 * Siebenhirten in 7 min", während der Zug in Alterlaa endet — eine falsche
 * Zeile ist schlimmer als eine fehlende.
 *
 * 'delayed' markiert die Abfahrt, die das Display invertiert darstellt.
 *
 * @return array{in: int, towards?: string, line?: string, delayed?: true}
 */
function board_departure(array $dep): array
{
    $t   = (string) ($dep['t'] ?? '0');
    $out = ['in' => $t === '*' ? 0 : (int) $t];

    if (!empty($dep['towards_override'])) $out['towards'] = (string) $dep['towards_override'];
    if (!empty($dep['name_override']))    $out['line']    = (string) $dep['name_override'];
    if (!empty($dep['jam']))              $out['delayed'] = true;

    return $out;
}

/**
 * Eine Linienzeile in die schlanke Form bringen.
 *
 * Weggelassen: direction, barrier_free, trafficjam — weder das Display noch
 * Home Assistant benutzen sie. platform BLEIBT: es ist der Filterschlüssel
 * und die stabile Identität der Zeile.
 */
function board_line(array $line): array
{
    return [
        'line'       => (string) ($line['name'] ?? ''),
        'platform'   => (string) ($line['platform'] ?? ''),
        'towards'    => (string) ($line['towards'] ?? ''),
        'type'       => board_type((string) ($line['type'] ?? '')),
        'realtime'   => (bool) ($line['realtime_supported'] ?? true),
        'alert'      => (bool) ($line['alert'] ?? false),
        'departures' => array_map('board_departure', $line['departures'] ?? []),
    ];
}

/**
 * Einen Favoriten samt seiner Haltestellen in die Antwortform bringen.
 *
 * $fav ist eine Zeile aus favorites_get(): ['id', 'title', 'diva', 'filter'].
 * $monitor ist die unveränderte Ausgabe von monitor_get().
 */
function board_favorite(array $fav, array $monitor): array
{
    $filter   = is_array($fav['filter'] ?? null) ? $fav['filter'] : [];
    $stations = [];

    $byDiva = [];
    foreach (board_stations_only($monitor) as $station) {
        $byDiva[(string) ($station['diva'] ?? '')] = $station;
    }

    // $monitor is shared across ALL selected favorites (web/board.php fetches
    // one monitor for the union of their DIVAs). Iterate the FAVORITE's own
    // DIVA list, not the shared map — otherwise a sibling favorite's stations
    // leak in here with no filter entry of their own, i.e. unfiltered.
    foreach (explode(',', (string) ($fav['diva'] ?? '')) as $diva) {
        $diva = trim($diva);
        if ($diva === '' || !isset($byDiva[$diva])) continue;

        $gefiltert = board_filter_station($byDiva[$diva], $filter[$diva] ?? null);
        if ($gefiltert === null) continue;

        $stations[] = [
            'diva'  => $diva,
            'name'  => (string) ($gefiltert['station_name'] ?? ''),
            'lines' => array_map('board_line', $gefiltert['lines']),
        ];
    }

    return ['id' => (int) $fav['id'], 'title' => (string) $fav['title'], 'stations' => $stations];
}

// ───────────────────────────────────────────────────────────────────────────
// Auswahl
// ───────────────────────────────────────────────────────────────────────────

/**
 * Favoriten nach dem ?fav=-Parameter auswählen.
 *
 * $alle ist die Liste aus favorites_get() — sie enthält bereits NUR die
 * Favoriten des Token-Benutzers. Unbekannte IDs werden still übergangen:
 * ein Fehler oder eine abweichende Antwort verriete, ob eine fremde ID
 * existiert.
 *
 * Leerer Parameter → alle, in der Reihenfolge von favorites_get() (sort, id).
 */
function board_selected_favorites(array $alle, string $favParam): array
{
    $favParam = trim($favParam);
    if ($favParam === '') return $alle;

    $nachId = [];
    foreach ($alle as $f) $nachId[(int) $f['id']] = $f;

    $out = [];
    foreach (explode(',', $favParam) as $roh) {
        $roh = trim($roh);
        if ($roh === '' || !ctype_digit($roh)) continue;
        $id = (int) $roh;
        if (isset($nachId[$id])) $out[] = $nachId[$id];
    }
    return $out;
}

/**
 * Alle DIVAs der gewählten Favoriten als kommaseparierte Liste, entdoppelt.
 * Eine Haltestelle, die in zwei Favoriten vorkommt, wird bei der WL-API nur
 * einmal angefragt.
 */
function board_all_divas(array $favs): string
{
    $divas = [];
    foreach ($favs as $f) {
        foreach (explode(',', (string) ($f['diva'] ?? '')) as $d) {
            $d = preg_replace('/[^0-9]/', '', $d);
            if ($d !== '' && !in_array($d, $divas, true)) $divas[] = $d;
        }
    }
    return implode(',', $divas);
}

// ───────────────────────────────────────────────────────────────────────────
// Board-Protokoll: Störungen + Gerätesignale (Spec §8, §3, §9)
// ───────────────────────────────────────────────────────────────────────────

/**
 * Filtert monitor_get()['alerts'] auf die Linien des aktiven Favoriten
 * (Spec §8). Kein Normalisieren noetig -- inc/monitor.php kreuzreferenziert
 * relatedLines bereits mit einem blossen Gleichheitsvergleich.
 *
 * @param list<array{title:string,description:string,priority:string,lines:list<string>,stops:list<string>}> $alerts
 * @param array{stations: list<array{lines: list<array{line:string}>}>} $favorite board_favorite()-Form
 * @return list<array{title:string,description:string,priority:string,lines:list<string>,stops:list<string>}>
 */
function board_filter_alerts_for_favorite(array $alerts, array $favorite): array
{
    $favoriteLines = [];
    foreach ($favorite['stations'] ?? [] as $station) {
        foreach ($station['lines'] ?? [] as $line) {
            $favoriteLines[(string) ($line['line'] ?? '')] = true;
        }
    }

    return array_values(array_filter($alerts, static function (array $alert) use ($favoriteLines): bool {
        foreach ($alert['lines'] ?? [] as $line) {
            if (isset($favoriteLines[(string) $line])) {
                return true;
            }
        }
        return false;
    }));
}

/**
 * Roher Akku-Millivolt-Wert -> grober Prozentwert (Spec §3, §13: bewusst
 * keine kalibrierte Fuel-Gauge-Kurve). Lineare Spreizung ueber den ueblichen
 * LiPo-Nutzbereich (3300-4200 mV), geklemmt auf 0-100.
 */
function board_battery_percent_from_mv(int $mv): int
{
    $percent = (int) round(($mv - 3300) / (4200 - 3300) * 100);
    return max(0, min(100, $percent));
}

/**
 * Am USB-Ladekabel liegt die gemessene Spannung ueber dem, was ein
 * entladener/ruhender LiPo bei "echten" 100% haette (Ladeschaltung treibt
 * sie waehrend des Ladevorgangs hoeher) -- ab 95% (Nutzerkalibrierung
 * 2026-08-22, verschaerft von urspruenglich >96%) ist das in Wahrheit
 * "laedt gerade", kein plausibler Ladestand. Schwellwert fuer die
 * Entladeseite (0%/fast leer) noch nicht kalibriert --
 * board_battery_percent_from_mv() bleibt dort unveraendert.
 *
 * $chargingThreshold ist seit TASK-27 ueber die Board-Einstellungen
 * (wl_board_settings, board_settings_load()) admin-konfigurierbar -- der
 * Default bleibt der bisherige hartcodierte Wert, damit bestehende Aufrufer
 * ohne den Parameter unveraendert funktionieren.
 */
function board_battery_is_charging(int $percent, int $chargingThreshold = 95): bool
{
    return $percent >= $chargingThreshold;
}

/**
 * Das lineare mV->%-Mapping unterschaetzt nahe der Vollladung (die LiPo-
 * Spannungskurve flacht dort ab) -- 92-94% roh sind laut Nutzerkalibrierung
 * 2026-08-22 in Wahrheit schon 100% voll (nur noch nicht am Ladekabel, das
 * waere >=95%, s. board_battery_is_charging()). Nur fuer die ANZEIGE
 * (Text + Balkenfuellung); board_battery_percent_from_mv() selbst bleibt
 * der rohe Messwert.
 *
 * $fullThreshold/$chargingThreshold s. board_battery_is_charging() -- der
 * "voll"-Bereich ist [$fullThreshold, $chargingThreshold).
 */
function board_battery_display_percent(int $percent, int $fullThreshold = 92, int $chargingThreshold = 95): int
{
    return ($percent >= $fullThreshold && $percent < $chargingThreshold) ? 100 : $percent;
}

/**
 * WLAN-RSSI (dBm) -> Balkenzahl 0-3 fuer die Kopfzeile (Spec §9). Grobe,
 * uebliche Schwellwerte -- keine praezise Kalibrierung vorgesehen (Spec §13,
 * analog zur Akku-Prozentanzeige).
 */
function board_wifi_bars_from_rssi(int $rssi): int
{
    if ($rssi >= -60) return 3;
    if ($rssi >= -70) return 2;
    if ($rssi >= -80) return 1;
    return 0;
}

/**
 * Kompressionsstufe fuer den Bildrumpf. Am echten Frame (1872x1404, 1bpp)
 * gemessen: roh 328.536 B -> Stufe 1: 21.258 B / 0,8 ms · Stufe 6: 18.753 B /
 * 4,2 ms · Stufe 9: 15.095 B / 18,3 ms. Stufe 9 spart gegenueber 6 nochmal
 * 3,6 KB (~16 ms Transfer bei den gemessenen 224 KB/s), kostet aber 14 ms
 * mehr CPU -- das hebt sich auf. Stufe 6 ist der Punkt mit dem besten
 * Verhaeltnis.
 */
const BOARD_DEFLATE_LEVEL = 6;

/** Unterhalb dieser Groesse lohnt der Aufwand nicht (Patch-Antworten). */
const BOARD_DEFLATE_MIN_BYTES = 1024;

/**
 * Packt den Bildrumpf mit ROHEM Deflate (kein zlib-, kein gzip-Rahmen), damit
 * die Firmware ihn ohne Zusatzflags durch tinfl_decompress_mem_to_mem() aus
 * dem ESP32-S3-ROM schicken kann.
 *
 * BEWUSST NICHT ueber `Content-Encoding: gzip`: zwischen Geraet und Server
 * haengt ein Cloudflare-Tunnel, der standardkonform komprimierte Antworten
 * umpacken oder auspacken darf. Ein eigener Header macht den Rumpf zu
 * undurchsichtigen Bytes, an denen unterwegs niemand dreht.
 *
 * Faellt auf "unkomprimiert" zurueck, wenn das Geraet nichts angekuendigt hat
 * (aeltere Firmware, Browser-Aufruf), der Rumpf zu klein ist oder das Packen
 * nichts bringt -- der Aufrufer muss also immer beide Faelle behandeln.
 *
 * @return array{body: string, encoding: string|null, rawLength: int}
 */
function board_compress_body(string $body, bool $deviceAcceptsDeflate): array
{
    $plain = ['body' => $body, 'encoding' => null, 'rawLength' => strlen($body)];

    if (!$deviceAcceptsDeflate || strlen($body) < BOARD_DEFLATE_MIN_BYTES) {
        return $plain;
    }

    $packed = gzdeflate($body, BOARD_DEFLATE_LEVEL);
    if ($packed === false || strlen($packed) >= strlen($body)) {
        return $plain;
    }

    return ['body' => $packed, 'encoding' => 'deflate', 'rawLength' => strlen($body)];
}

/** Kuendigt das Geraet rohes Deflate an? Header aus board_client.cpp. */
function board_device_accepts_deflate(?string $headerValue): bool
{
    return $headerValue !== null && str_contains($headerValue, 'deflate');
}
