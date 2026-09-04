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
 * X-Device-Touch: fav0|fav1|fav2|page_<N>|page_prev|page_next   (optional)
 *                                     page_<N>: Tipp auf die Touch-Pille,
 *                                     springt ABSOLUT zu Seite N (TASK-25).
 *                                     page_prev/page_next: NUR die
 *                                     physischen Tasten (main.cpp) -- eine
 *                                     Taste kann keine Zielseite ausdruecken,
 *                                     bleibt bewusst relativ.
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
require_once __DIR__ . '/../inc/board_sleep.php';
require_once __DIR__ . '/../inc/board_guest_wifi.php';
require_once __DIR__ . '/../inc/board_calendar.php';
require_once __DIR__ . '/../inc/board_mqtt.php';
require_once __DIR__ . '/../inc/board_settings.php';

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

    // Loesch-X auf einer MQTT-Karte (TASK-26, Nutzerwunsch 2026-08-29).
    // VOR board_resolve_touch() und vor dem Laden des Caches weiter unten --
    // so rendert derselbe Request bereits die Seite OHNE die geloeschte
    // Nachricht, statt erst beim naechsten Poll. Der Touch veraendert
    // ausserdem KEINE Navigation: die Seite soll unter dem Finger stehen
    // bleiben, deshalb faellt der Wert danach weg (null).
    if (is_string($touchHeader) && str_starts_with($touchHeader, 'mqtt_del_')) {
        board_mqtt_delete(substr($touchHeader, strlen('mqtt_del_')));
        $touchHeader = null;
    }

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

    // Kalenderseite: Existenz haengt ALLEIN an der Dateipraesenz, nicht am Alter
    // oder Inhalt. Sonst verschoeben sich die Seitenindizes unter dem
    // gespeicherten activePage des Geraets, sobald die Daten veralten. Das
    // Anzeige-Array entsteht weiter unten, sobald $renderedAt existiert --
    // $hasCalendar === ($calendar !== null) haelt beide Seitenrechnungen im
    // Gleichschritt (hier und in board_render_svg()).
    $calendarCache = board_calendar_load($userId);
    $hasCalendar = $calendarCache !== null;
    // MQTT-Seite (TASK-26): dieselbe Regel wie beim Kalender -- Existenz haengt
    // ALLEIN an der Dateipraesenz, nie an Inhalt/Alter.
    $mqttCache = board_mqtt_load();
    $hasMqtt = $mqttCache !== null;

    $totalDeparturePages = board_paginate_departures($activeFavorite, 1)['totalPages'];
    $totalContentPages = $totalDeparturePages + ($filteredAlerts !== [] ? 1 : 0) + ($hasCalendar ? 1 : 0) + ($hasMqtt ? 1 : 0);
    $totalPages = board_total_pages($totalDeparturePages, $filteredAlerts !== [], $hasCalendar, $hasMqtt);
    $requestedPage = max(1, min($totalPages, $resolved['activePage']));

    // Schlafschirm erzwingen (Nutzervorgabe 2026-08-23): der letzte Abruf vor
    // dem Tiefschlaf verlangt ihn ausdruecklich per Header, UNABHAENGIG vom
    // gespeicherten Blaetter-Zustand -- sonst muesste die Firmware erst per
    // page_next dorthin navigieren. BEWUSST wird dafuer NICHT $requestedPage
    // selbst ueberschrieben: persistiert wird unten weiterhin der unforcierte
    // Wert, sonst stuende beim naechsten Aufwachen wieder der Schlafschirm da,
    // obwohl der Nutzer zuletzt ganz woanders geblaettert hatte.
    $forceSleepScreen = ($_SERVER['HTTP_X_DEVICE_SCREEN'] ?? '') === 'sleep';
    $renderPage = $forceSleepScreen ? $totalPages : $requestedPage;

    $weatherCachePath = __DIR__ . '/../data/weather_cache.json';
    $weatherCache = file_exists($weatherCachePath)
        ? json_decode((string) file_get_contents($weatherCachePath), true)
        : null;

    $renderedAt = new DateTimeImmutable();
    $dataStand = $renderedAt; // s. Global Constraints: bewusst kein Reparse von monitor_get()['update_at']
    $weather = weather_select_display(is_array($weatherCache) ? $weatherCache : null, $renderedAt);
    $calendar = $hasCalendar ? board_calendar_select_display($calendarCache, $renderedAt) : null;
    $mqtt = $hasMqtt ? board_mqtt_select_display($mqttCache, $renderedAt) : null;
    // Steht GERADE die MQTT-Seite an? Nur dann gehoeren die Loesch-X der
    // Karten in die Touch-Zonen (s. weiter unten). Dieselbe Seitenreihenfolge
    // wie board_render_svg(): Abfahrten -> Stoerungen -> Kalender -> MQTT.
    $istMqttSeiteFuerZonen = $hasMqtt
        && $requestedPage === $totalDeparturePages
            + ($filteredAlerts !== [] ? 1 : 0)
            + ($hasCalendar ? 1 : 0)
            + 1;

    // TASK-27: einmal pro Request geladen, speist Gaeste-WLAN + Akku-
    // Kalibrierung weiter unten (wie $weather/$mqtt/$calendar bereits).
    $boardSettings = board_settings_load($con);

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

    if ($debug === 'ui') {
        // Browser-Simulator: rendert KEIN Bild selbst, sondern eine HTML-
        // Huelle mit anklickbaren Zonen ueber einem <img>, das auf
        // ?debug=png zeigt -- jeder Klick schickt denselben X-Device-Touch-
        // Header, den die echte Firmware setzen wuerde, gegen genau dieselbe
        // Token-gebundene Zustandsdatei (board_state_hash()). Kein neuer
        // Codepfad fuer Zustand/Touch-Aufloesung, nur eine duenne UI davor.
        board_render_debug_ui($token, count($touchBarFavorites), $totalPages, (string) ($_cspNonce ?? ''));
        exit;
    }

    if ($part === 'monitor' && ($debug === 'svg' || $debug === 'png')) {
        // Nur die Abfahrten-/Stoerungsseite, ohne Kopf-/Fusszeile, Touch-
        // Leiste oder Wetterkarte -- zugeschnitten auf die linke Spalte, die
        // im Vollbild durch die Trennlinien bei x=1113 und y=90..1310
        // definiert ist (board_render_chrome_svg()). Nur fuer debug=svg/png,
        // das reale Geraeteprotokoll bleibt unveraendert das Vollbild.
        // Dieselbe Vierteilung wie in board_render_svg() -- ohne sie wuerde
        // ?part=monitor auf der Kalender- oder MQTT-Seite stillschweigend die
        // Stoerungsseite zeigen.
        $disruptionsPage = $filteredAlerts !== [] ? $totalDeparturePages + 1 : null;
        $calendarPage = $hasCalendar ? $totalDeparturePages + ($filteredAlerts !== [] ? 1 : 0) + 1 : null;
        if ($requestedPage <= $totalDeparturePages) {
            $mainOnlySvg = board_render_departures_svg(
                board_paginate_departures($activeFavorite, $requestedPage, $filteredAlerts)['items']
            );
        } elseif ($requestedPage === $disruptionsPage) {
            $mainOnlySvg = board_render_disruptions_svg(board_layout_disruptions($filteredAlerts));
        } elseif ($requestedPage === $calendarPage) {
            $mainOnlySvg = board_calendar_render_svg(board_calendar_layout($calendar));
        } else {
            // $count wie im echten Rendering (inc/board_template.php) -- ohne
            // ihn zeigte der Debug-Schnitt die Seite OHNE "Nachrichten (N)"
            // und taugte damit nicht mehr zum Vergleich (Audit 2026-09-03).
            $mainOnlySvg = board_mqtt_render_svg(board_mqtt_layout($mqtt), count($mqtt['messages']));
        }
        $pageCategories = board_pagination_categories($totalDeparturePages, $filteredAlerts !== [], $hasCalendar, $hasMqtt);
        $standSvg = board_render_stand_and_pagination_svg($dataStand, $requestedPage, $totalPages, true, $pageCategories);
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
        // Der Schlafschirm (Nutzerwunsch 2026-08-23) ist strukturell die
        // LETZTE Seite ($renderPage bis auf $totalPages geklemmt, board_render_svg()
        // erkennt den Slot selbst) -- entweder weil das Geraet ihn vor dem
        // Tiefschlaf ausdruecklich per Header verlangt ($forceSleepScreen)
        // oder weil der Nutzer bewusst dorthin geblaettert hat.
        //
        // Die Seitenzahlen-Pille zeigt sich NUR im zweiten Fall
        // (Nutzerbefund 2026-08-23: "die paginierung ist jetzt aber leider
        // auch zu sehen, wenn das panel schlaeft") -- $forceSleepScreen IST
        // per Definition der letzte Abruf vor esp_deep_sleep_start(), danach
        // wird der Touch-Controller bis zum naechsten Tastendruck nicht mehr
        // abgefragt. Ausserhalb dieses einen Unterschieds bleibt der Inhalt
        // identisch.
        $svg = board_render_svg(
            $touchBarTitles,
            $resolved['activeFavoriteIndex'],
            $activeFavorite,
            $filteredAlerts,
            $renderPage,
            $weather,
            $dataStand,
            $renderedAt,
            $batteryPercent,
            $wifiBars,
            $firmwareBuild,
            weather_sun_times($renderedAt),
            weather_select_two_days(is_array($weatherCache) ? $weatherCache : null, $renderedAt),
            board_guest_wifi_load($boardSettings),
            !$forceSleepScreen,
            $calendar,
            $mqtt,
            $boardSettings['battery_charging_threshold'],
            $boardSettings['battery_full_threshold']
        );
    }

    if ($debug === 'svg') {
        header('Content-Type: image/svg+xml; charset=utf-8');
        echo $svg;
        exit;
    }

    $png = svg_to_png($svg);

    if ($debug === 'png') {
        // &sim=1: nur vom Browser-Simulator (?debug=ui) gesetzt, NIE vom
        // echten Geraet oder einem einfachen debug=png-Aufruf -- persistiert
        // die gerade aufgeloeste Touch-Navigation, damit ein zweiter Klick
        // vom ANGEZEIGTEN Stand weitermacht statt immer vom letzten
        // echten Geraete-Poll. Absichtlich ohne Frame/Diff/ETag: die
        // 1bpp-Patch-Logik betrifft nur das Geraeteprotokoll. Fehlende Felder
        // (etag, fullRefreshAt) fallen beim naechsten echten Poll auf
        // board_state_default_meta() zurueck -- harmlos, erzwingt hoechstens
        // einmal ein Vollbild statt eines Patches.
        if (($_GET['sim'] ?? '') === '1') {
            board_state_save_meta($metaPath, [
                'activeFavoriteIndex' => $resolved['activeFavoriteIndex'],
                'activePage' => $requestedPage,
            ]);
            // totalPages haengt vom AKTIVEN Favoriten ab (eine Stoerung
            // fuegt eine Seite hinzu, die Pille wird breiter/wandert nach
            // links) -- ohne diesen Header blieben die im Simulator einmalig
            // beim Laden berechneten Zonen nach einem Favoritenwechsel
            // stehen und passten nicht mehr zur tatsaechlich gerenderten
            // Pille (Nutzerbefund 2026-08-27, live auf akadbrain).
            //
            // Auf der MQTT-Seite kommen die Loesch-X der einzelnen Karten
            // dazu. Die sind INHALTSABHAENGIG (Masonry-Umbruch), lassen sich
            // also anders als Favoritenleiste und Pille nicht aus einer festen
            // Formel ableiten -- sie MUESSEN vom Server kommen.
            $zonen = board_touch_zones(count($touchBarFavorites), $totalPages);
            if ($istMqttSeiteFuerZonen && $mqtt !== null) {
                $zonen = array_merge($zonen, board_mqtt_touch_zones(board_mqtt_layout($mqtt)));
            }
            header('X-Board-Touch-Zones: ' . json_encode($zonen, JSON_THROW_ON_ERROR));
        }
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
    // Loesch-X der Nachrichtenkarten (TASK-28). Favoritenleiste und Pille
    // rechnet die Firmware aus den beiden Headern oben selbst nach; DIESE
    // Zonen kann sie nicht nachrechnen, weil die Kartenpositionen erst beim
    // Masonry-Umbruch entstehen -- sie muessen mitgeliefert werden. Bisher
    // ging der Zonen-Header ausschliesslich an den Browser-Simulator
    // (debug=png&sim=1), weshalb das Wegklicken am Geraet gar nicht
    // funktionieren KONNTE (Nutzerbefund 2026-09-04).
    //
    // Nur auf der MQTT-Seite gesetzt: sonst waeren es auf jeder anderen Seite
    // nutzlose Bytes in jeder Antwort.
    //
    // Absichtlich gegen $renderPage geprueft, nicht gegen $requestedPage:
    // beim erzwungenen Schlafschirm (X-Device-Screen: sleep) faellt beides
    // auseinander ($renderPage = $totalPages, s.o.). Die gespeicherte aktive
    // Seite kann dabei durchaus die MQTT-Seite sein -- wir wuerden dann
    // Loeschzonen fuer Karten mitschicken, die gar nicht auf dem Schirm
    // stehen, und ein Tipp loeschte eine unsichtbare Nachricht.
    $mqttSeite = $totalDeparturePages
        + ($filteredAlerts !== [] ? 1 : 0)
        + ($hasCalendar ? 1 : 0)
        + 1;
    if ($hasMqtt && $mqtt !== null && $renderPage === $mqttSeite) {
        $loeschZonen = board_mqtt_delete_zones_header(board_mqtt_layout($mqtt));
        if ($loeschZonen !== '') {
            header('X-Board-Delete-Zones: ' . $loeschZonen);
        }
    }
    // Sagt der Firmware, ob GERADE der Schlafschirm-Slot ausgeliefert wurde --
    // unabhaengig davon, ob per $forceSleepScreen oder durch bewusstes
    // Hinblaettern (Nutzerwunsch 2026-08-23). Traegt main.cpps
    // showingSleepPage-Zustand: nur darauf reagiert die gruene Taste mit
    // "jetzt schlafen" statt "Vollupdate".
    if ($renderPage > $totalContentPages) {
        header('X-Board-Is-Sleep-Page: 1');
    }
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
