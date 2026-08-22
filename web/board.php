<?php
/**
 * web/board.php — Bild-Protokoll für Geräte (E-Paper-Display), token-
 * authentifiziert. Liefert rohe 1bpp-Pixeldaten statt JSON (Spec §5).
 *
 * GET /board.php
 * Authorization: Bearer <token>      (X-Auth-Token als Ausweichheader)
 * ?token=<token>                     (Ausweich fuer Browser-Aufrufe ohne
 *                                      Header-Kontrolle, z.B. Adresszeile
 *                                      oder SenseCraft; nur Fallback -- ein
 *                                      Header-Token hat Vorrang. Selbe
 *                                      Guelitgkeitspruefung wie der Header,
 *                                      siehe auth_api_token_resolve())
 *
 * ACHTUNG zum ?token=-Weg: ein Token in der URL landet in nginx-/Proxy-
 * Zugriffslogs, im Browserverlauf und potenziell in Referer-Headern -- anders
 * als ein Header-Token. Der Link ist damit ein dauerhaftes Geheimnis wie ein
 * iCal-Feed-Link. Bewusst akzeptiert (Nutzerentscheidung 2026-08-18), weil
 * Geraete/Browser ohne Header-Kontrolle sonst gar nicht zugreifen koennen.
 * Empfehlung: dafuer ein EIGENES, jederzeit widerrufbares Token aus
 * profil.php verwenden, nicht dasselbe wie fuer das E-Paper-Geraet.
 * Der Token-Wert selbst wird nie ins auth_log geschrieben.
 * X-Device-Battery-mV: <n>           (optional)
 * X-Device-RSSI: <n>                 (optional)
 * X-Device-Touch: fav0|fav1|fav2|page_prev|page_next   (optional)
 * If-None-Match: "<letzter ETag>"    (optional)
 *
 * Bewusst KEINE Sitzung -- wie im Vorgänger, alles haengt am Token-Nutzer.
 * Pro-Geraet-Zustand (aktiver Favorit/Seite/letzter Frame) liegt in
 * data/board_state/<sha256(token)>.{json,frame} (inc/board_state.php),
 * kein $_SESSION.
 *
 * Fehler nennen nach aussen nur eine Kennung; die Ursache geht ins auth_log
 * (Fehler-Regeln §21). 401/503/500 haben KEINEN Bildkoerper (Spec §5).
 *
 * ?debug=svg / ?debug=png liefern Zwischenstufen der Rendering-Pipeline
 * (gleiche Auth, aber OHNE Diff-/Patch-/State-Logik, Spec §6).
 * ?part=monitor (nur zusammen mit debug=svg/png) liefert ausschliesslich die
 * Abfahrten-/Stoerungsspalte, ohne Kopf-/Fusszeile, Touch-Leiste oder
 * Wetterkarte -- zugeschnitten auf 1113x1220 (die linke Spalte des
 * Vollbilds). Das reale Geraeteprotokoll ignoriert diesen Parameter.
 *
 * Spec: docs/superpowers/specs/2026-08-15-epaper-monitor-v2-design.md
 */
declare(strict_types=1);

// Query-Token auf den regulaeren Header-Weg heben -- BEVOR initialize.php
// laeuft. Nur so durchlaeuft es exakt denselben Pfad wie ein Header-Token:
//   - auth_bootstrap() erkennt eine Token-Anfrage und startet KEINE Sitzung
//     (sonst bekaeme jeder Query-Token-Abruf eine PHP-Session + Set-Cookie,
//     entgegen dem "bewusst keine Sitzung"-Design dieses Endpunkts).
//   - auth_apply_api_token() aufloest es regulaer, kein Sonderpfad noetig.
//   - auth_api_token_from_request() liefert es weiter unten zurueck, sodass
//     board_state_hash() den RICHTIGEN Zustand pro Token trifft. Ohne das
//     lieferte die Funktion '' und ALLE Query-Token-Geraete teilten sich
//     einen einzigen Zustand (sha256('')) -- gemeinsamer aktiver Favorit,
//     gemeinsame Seite und gemeinsamer Vorgaenger-Frame, gegen den gepatcht
//     wird. Gefunden im Sicherheits-Review 2026-08-21.
// Ein echter Header hat weiterhin Vorrang.
if (empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['HTTP_X_AUTH_TOKEN'])
    && !empty($_GET['token'])) {
    $_SERVER['HTTP_X_AUTH_TOKEN'] = (string) $_GET['token'];
}

require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/favorites.php';
require_once __DIR__ . '/../inc/monitor.php';
require_once __DIR__ . '/../inc/board.php';
require_once __DIR__ . '/../inc/weather.php';
require_once __DIR__ . '/../inc/board_render.php';
require_once __DIR__ . '/../inc/board_template.php';
require_once __DIR__ . '/../inc/board_state.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Fehlerantwort senden und beenden -- einziger JSON-Zweig dieses Endpunkts. */
function board_error_out(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

$userId = auth_api_request_user();
if ($userId === null) {
    if (auth_api_token_presented()) {
        appendLog($con, 'board', 'Zugriff ohne gueltiges Token (Token vorgelegt, aber ungueltig)');
    }
    board_error_out(['error' => 'unauthorized'], 401);
}

try {
    $token = auth_api_token_from_request();
    $hash = board_state_hash($token);
    $metaPath = board_state_meta_path($hash);
    $framePath = board_state_frame_path($hash);
    $oldMeta = board_state_load_meta($metaPath);

    $touchBarFavorites = array_slice(favorites_get($con, $userId), 0, 3);
    $touchBarTitles = array_map(static fn (array $f): string => (string) $f['title'], $touchBarFavorites);

    $touchHeader = $_SERVER['HTTP_X_DEVICE_TOUCH'] ?? null;
    $resolved = board_resolve_touch($oldMeta, is_string($touchHeader) ? $touchHeader : null, count($touchBarFavorites));

    if ($touchBarFavorites === []) {
        // Frisch provisioniertes Geraet ohne Favoriten -- kein monitor_get()-
        // Aufruf (kein Netz noetig), leeres aber gueltiges Board.
        $activeFavorite = ['id' => 0, 'title' => '', 'stations' => []];
        $filteredAlerts = [];
    } else {
        $activeFavoriteRaw = $touchBarFavorites[$resolved['activeFavoriteIndex']];
        $divas = board_all_divas([$activeFavoriteRaw]);

        try {
            $monitor = monitor_get($con, $divas, 2);
        } catch (RuntimeException $e) {
            // "Keine Abfahrten fuer keine der angefragten DIVAs" ist kein
            // Upstream-Ausfall, sondern ein gueltiger Zustand -- nur DIESE
            // eine RuntimeException wird so behandelt.
            if (!str_contains($e->getMessage(), 'No monitors found')) {
                throw $e;
            }
            $monitor = [];
        }
        $monitor = monitor_inject_missing_stations($con, $monitor, $divas);

        $activeFavorite = board_favorite($activeFavoriteRaw, $monitor);
        $filteredAlerts = board_filter_alerts_for_favorite($monitor['alerts'] ?? [], $activeFavorite);
    }

    $totalDeparturePages = board_paginate_departures($activeFavorite, 1)['totalPages'];
    $totalPages = $totalDeparturePages + ($filteredAlerts !== [] ? 1 : 0);
    $requestedPage = max(1, min($totalPages, $resolved['activePage']));

    $weatherCachePath = __DIR__ . '/../data/weather_cache.json';
    $weatherCache = file_exists($weatherCachePath)
        ? json_decode((string) file_get_contents($weatherCachePath), true)
        : null;

    $renderedAt = new DateTimeImmutable();
    $dataStand = $renderedAt; // s. Global Constraints: bewusst kein Reparse von monitor_get()['update_at']
    $weather = weather_select_display(is_array($weatherCache) ? $weatherCache : null, $renderedAt);

    $batteryMv = $_SERVER['HTTP_X_DEVICE_BATTERY_MV'] ?? null;
    $batteryPercent = is_numeric($batteryMv) ? board_battery_percent_from_mv((int) $batteryMv) : 0;
    $rssi = $_SERVER['HTTP_X_DEVICE_RSSI'] ?? null;
    $wifiBars = is_numeric($rssi) ? board_wifi_bars_from_rssi((int) $rssi) : 0;
    // Firmware-Marke: seit 2026-08-22 serverseitig gerendert statt lokal aufs
    // Panel gezeichnet -- das lokale 256x50-Overlay kostete gemessene 1104 ms,
    // mehr als ein komplettes Vollbild. Fehlt der Header (Browser-Aufruf,
    // aeltere Firmware), bleibt die Marke einfach weg.
    $firmwareRaw = $_SERVER['HTTP_X_DEVICE_FIRMWARE'] ?? null;
    $firmwareBuild = is_numeric($firmwareRaw) ? (int) $firmwareRaw : null;

    $debug = (string) ($_GET['debug'] ?? '');
    $part = (string) ($_GET['part'] ?? '');

    if ($part === 'monitor' && ($debug === 'svg' || $debug === 'png')) {
        // Nur die Abfahrten-/Stoerungsseite, ohne Kopf-/Fusszeile, Touch-
        // Leiste oder Wetterkarte -- zugeschnitten auf die linke Spalte, die
        // im Vollbild durch die Trennlinien bei x=1113 und y=90..1310
        // definiert ist (board_render_chrome_svg()). Nur fuer debug=svg/png,
        // das reale Geraeteprotokoll bleibt unveraendert das Vollbild.
        $items = $requestedPage <= $totalDeparturePages
            ? board_paginate_departures($activeFavorite, $requestedPage)['items']
            : null;
        $mainOnlySvg = $items !== null
            ? board_render_departures_svg($items)
            : board_render_disruptions_svg(board_layout_disruptions($filteredAlerts));
        $standSvg = board_render_stand_and_pagination_svg($dataStand, $requestedPage, $totalPages);
        $defs = board_svg_defs();

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1113" height="1220" viewBox="0 90 1113 1220">
<defs>
{$defs}
</defs>
<rect x="0" y="90" width="1113" height="1220" fill="white"/>
{$mainOnlySvg}
{$standSvg}
</svg>
SVG;
    } else {
        $svg = board_render_svg(
            $touchBarTitles,
            $resolved['activeFavoriteIndex'],
            $activeFavorite,
            $filteredAlerts,
            $requestedPage,
            $weather,
            $dataStand,
            $renderedAt,
            $batteryPercent,
            $wifiBars,
            $firmwareBuild
        );
    }

    if ($debug === 'svg') {
        header('Content-Type: image/svg+xml; charset=utf-8');
        echo $svg;
        exit;
    }

    $png = svg_to_png($svg);

    if ($debug === 'png') {
        header('Content-Type: image/png');
        echo $png;
        exit;
    }

    $newPacked = png_to_1bpp_packed($png, BOARD_WIDTH, BOARD_HEIGHT);
    $newEtag = '"' . hash('sha256', $newPacked) . '"';

    $oldFrame = board_state_load_frame($framePath);
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $stateChanged = $resolved['activeFavoriteIndex'] !== (int) ($oldMeta['activeFavoriteIndex'] ?? -1)
        || $requestedPage !== (int) ($oldMeta['activePage'] ?? -1);
    $fullRefreshAt = $oldMeta['fullRefreshAt'] ?? null;
    $recentFullRefresh = $fullRefreshAt !== null && (time() - (int) $fullRefreshAt) < 1800;

    $canPatch = $oldFrame !== null
        && $ifNoneMatch !== ''
        && $ifNoneMatch === ($oldMeta['etag'] ?? null)
        && $recentFullRefresh
        && !$stateChanged;

    $diff = $canPatch ? board_frame_diff($oldFrame, $newPacked, BOARD_WIDTH, BOARD_HEIGHT) : null;

    if ($diff !== null) {
        $mode = 'patch';
        $body = board_crop_and_pack($png, $diff['x'], $diff['y'], $diff['w'], $diff['h']);
        $x = $diff['x'];
        $y = $diff['y'];
        $w = $diff['w'];
        $h = $diff['h'];
    } else {
        // Voll-Frame: entweder kann nicht gepatcht werden (ETag-Mismatch,
        // 30-Min-Grenze, Favoriten-/Seitenwechsel, erster Poll ueberhaupt),
        // oder der neue Frame ist byte-identisch zum alten (board_frame_diff()
        // liefert dann null) -- in beiden Faellen ist ein Vollbild die
        // korrekte, immer gueltige Antwort.
        $mode = 'full';
        $body = $newPacked;
        $x = 0;
        $y = 0;
        $w = BOARD_WIDTH;
        $h = BOARD_HEIGHT;
        $fullRefreshAt = time();
    }

    try {
        board_state_save_meta($metaPath, [
            'activeFavoriteIndex' => $resolved['activeFavoriteIndex'],
            'activePage' => $requestedPage,
            'etag' => $newEtag,
            'fullRefreshAt' => $fullRefreshAt,
        ]);
        board_state_save_frame($framePath, $newPacked);
    } catch (RuntimeException $e) {
        // Lokales Platten-/Berechtigungsproblem, kein Upstream-Ausfall --
        // eigener Fehlerpfad, damit das Geraet nicht faelschlich 503
        // (upstream_unavailable) statt 500 (server_error) sieht.
        appendLog($con, 'board', 'Zustand konnte nicht gespeichert werden: ' . $e->getMessage());
        board_error_out(['error' => 'server_error'], 500);
    }

    header('X-Board-Mode: ' . $mode);
    header('X-Board-ETag: ' . $newEtag);
    header('X-Board-Generated: ' . $renderedAt->format(DATE_ATOM));
    header('X-Board-X: ' . $x);
    header('X-Board-Y: ' . $y);
    header('X-Board-W: ' . $w);
    header('X-Board-H: ' . $h);
    // Die Touch-Leiste teilt sich dynamisch durch die tatsaechliche
    // Favoritenzahl (board_render_touch_bar_svg(), 1-3 Buttons) -- ohne
    // diesen Header muesste die Firmware raten, wie breit jede Zone ist.
    header('X-Board-Favorite-Count: ' . count($touchBarFavorites));
    // Analog: die Pagination-Pille (board_render_stand_and_pagination_svg())
    // ist nur sichtbar, wenn totalPages > 1, und ihre Breite haengt von
    // totalPages ab -- die Firmware braucht das, um die Touch-Zonen fuer
    // die Pfeile korrekt nachzurechnen.
    header('X-Board-Total-Pages: ' . $totalPages);
    // Debug-Feature (s. web/board_snapshot.php): ein per ?request=1 gesetztes
    // Flag laesst die Firmware ihren tatsaechlichen Panel-Inhalt hochladen --
    // zeigt auch lokale Overlay-Reste (Fehler-Banner, Marker), die dieser
    // Server-Render selbst nicht kennt.
    if (board_state_snapshot_requested($hash)) {
        header('X-Board-Snapshot-Requested: 1');
    }
    // Rumpf packen, wenn das Geraet es ankuendigt. Am echten Frame: 328.536 B
    // -> 18.753 B (5,7%) fuer 4,2 ms Server-CPU, gegen gemessene ~1470 ms
    // Download bei 224 KB/s. Siehe board_compress_body() und
    // docs/hardware/reterminal-e1003.md §20.8.
    $encoded = board_compress_body(
        $body,
        board_device_accepts_deflate($_SERVER['HTTP_X_DEVICE_ACCEPT_ENCODING'] ?? null)
    );
    if ($encoded['encoding'] !== null) {
        header('X-Board-Encoding: ' . $encoded['encoding']);
        header('X-Board-Raw-Length: ' . $encoded['rawLength']);
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($encoded['body']));
    echo $encoded['body'];
    exit;
} catch (RuntimeException | InvalidArgumentException $e) {
    appendLog($con, 'board', 'Upstream-Fehler: ' . $e->getMessage());
    board_error_out(['error' => 'upstream_unavailable'], 503);
} catch (Throwable $e) {
    appendLog($con, 'board', 'Fehler: ' . get_class($e) . ': ' . $e->getMessage());
    board_error_out(['error' => 'server_error'], 500);
}
