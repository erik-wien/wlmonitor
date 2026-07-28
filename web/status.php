<?php
/**
 * web/status.php — Ampel-Statusseite (Chrome\Status, Suite-Policy §5,
 * Design-Spec 2026-07-24, TASK-22).
 *
 * Checks:
 *  - Datenbank: mysqli-Ping auf $con (wie suche/status.php — wlmonitor hat
 *    nur eine Verbindung, auth-Tabellen werden cross-DB über AUTH_DB_PREFIX
 *    auf derselben Connection angesprochen).
 *  - Wiener-Linien-Echtzeit-API: minimaler valider Request gegen
 *    ogd_realtime/monitor (inc/monitor.php dokumentiert die URL) — nutzt eine
 *    echte DIVA aus ogd_haltestellen (nicht bloß "URL erreichbar", sondern
 *    "liefert eine echte Monitor-Antwort"), 3s Timeout, HTTP>=400 oder ein
 *    API-seitiger Fehler-messageCode zählen als fail (§21).
 *  - OGD-Stammdaten (Wiener Linien): Zeilenzahl + Alter des letzten Reloads.
 *    Die STAND-Spalte der WL-CSVs wäre der naheliegende Frische-Indikator,
 *    wird von den Wiener Linien aber nicht befüllt — 2026-07-28 an den echten
 *    Daten nachgemessen: in ogd_haltestellen (1959 Zeilen), ogd_linien (197)
 *    und ogd_steige (7362) ausnahmslos leer/NULL. Statt die Lücke nur zu
 *    dokumentieren, schreibt ogd_update() den Reload-Zeitpunkt seit TASK-24
 *    selbst nach data/ogd_last_reload; der Check liest ihn. Solange die Datei
 *    fehlt (vor dem ersten Reload nach diesem Update), sagt der Detailtext
 *    ausdrücklich, dass das Alter unbekannt ist — er suggeriert keine
 *    Aktualität, die niemand geprüft hat.
 *  - OGD-Datenquelle (data.wien.gv.at): Erreichbarkeit der CSV-Quelle. Ein
 *    völlig eigener Host, der mit wienerlinien.at nichts zu tun hat — ist er
 *    weg, schlägt der nächste Reload fehl, und zwar erst dann, wenn ihn jemand
 *    anstößt. HEAD statt GET: die Haltestellen-CSV ist mehrere hundert kB, und
 *    für die Erreichbarkeit reicht der Header.
 *  - SMTP (Mailversand): Erreichbarkeit des Servers, über den Einladungen und
 *    Passwort-Resets rausgehen (Chrome\Status::smtpCheck, chrome TASK-10) —
 *    verschickt nichts, meldet sich nicht an.
 *
 * "zuletzt ok: …" liefert seit chrome TASK-24 die Library selbst: run() reicht
 * den letzten Erfolgszeitstempel über eine Störung hinweg weiter, sodass eine
 * rote Ampel zeigt, seit wann sie rot ist. Hier ist dafür nichts zu tun.
 *
 * Ampel für alle eingeloggten User; Fehlertexte/Interna nur für Admins
 * (Chrome\Status::render()). Ergebnisse ~60s gecacht (data/, wie
 * data/ratelimit.json — Laufzeitartefakt, nicht versioniert, s. .gitignore).
 *
 * ?format=json liefert {app, generated_ts, checks} ohne Interna — kein
 * status_token-Zugriff: wlmonitor hat (Stand 2026-07-24) kein solches Token
 * in config.yaml, daher nur Session-Auth wie die Seite selbst.
 */

require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/layout.php';
// Liefert OGD_CSV_URLS (Quell-URL für den Erreichbarkeits-Check) und
// OGD_LAST_RELOAD_FILE (Zeitstempel des letzten Reloads).
require_once __DIR__ . '/../inc/ogd.php';

use Erikr\Chrome\Status;

auth_require();

$checks = [
    [
        'name'  => 'Datenbank',
        // SELECT 1 statt ping(): ping() ist seit PHP 8.4 deprecated (die
        // Reconnect-Funktion fiel in 8.2 weg, der Aufruf ist seither
        // wirkungslos) und schriebe bei jedem Cache-Miss eine Deprecated-
        // Meldung ins Log — bei display_errors sogar mitten in die Seite.
        // Ein echter Round-Trip prüft ohnehin mehr. (chrome TASK-11)
        'check' => static fn() => Status::dbCheck(static fn() => $con->query('SELECT 1') !== false, 'Verbindung ok.'),
    ],
    [
        'name'  => 'Wiener-Linien-Echtzeit-API',
        'check' => static function () use ($con) {
            $divaRes = $con->query(
                "SELECT DIVA FROM ogd_haltestellen WHERE DIVA IS NOT NULL AND DIVA <> '' LIMIT 1"
            );
            $diva = $divaRes !== false ? ($divaRes->fetch_assoc()['DIVA'] ?? null) : null;
            if ($diva === null) {
                return [
                    'state'  => 'fail',
                    'detail' => 'Keine DIVA-Nummer aus ogd_haltestellen verfügbar — API-Testabruf nicht möglich.',
                ];
            }

            $url = 'https://www.wienerlinien.at/ogd_realtime/monitor?diva='
                 . urlencode((string) $diva) . '&sender=' . APIKEY;

            $ch = curl_init($url);
            if ($ch === false) {
                return ['state' => 'fail', 'detail' => 'curl_init fehlgeschlagen'];
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body  = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // Kein curl_close(): seit PHP 8.0 wirkungslos, ab 8.5 deprecated —
            // die Deprecation-Meldung landete sonst mitten im Seiten-Output
            // (bzw. im JSON von ?format=json). Gleiche Stelle wie in
            // Energie/web/status.php und inc/ai_client.php.

            if ($errno !== 0) {
                return ['state' => 'fail', 'detail' => $error !== '' ? $error : ('curl-Fehler #' . $errno)];
            }
            // §21: HTTP >= 400 ist immer ein Fehler, nie stillschweigend "nicht gefunden".
            if ($code >= 400 || $code === 0) {
                return ['state' => 'fail', 'detail' => 'HTTP ' . $code];
            }

            $json        = is_string($body) ? json_decode($body, true) : null;
            $messageCode = $json['message']['messageCode'] ?? null;
            // messageCode 1 = OK laut WL-API; alles andere (z. B. 321 „Parameter
            // fehlt") liefert HTTP 200, ist aber ein API-seitiger Fehlerzustand.
            if ($messageCode !== 1) {
                $value = (string) ($json['message']['value'] ?? 'keine message.value in der Antwort');
                return ['state' => 'fail', 'detail' => 'Unerwartete API-Antwort: ' . $value];
            }

            return ['state' => 'ok', 'detail' => 'HTTP ' . $code . ', DIVA ' . $diva];
        },
    ],
    [
        'name'  => 'OGD-Stammdaten (Wiener Linien)',
        'check' => static function () use ($con) {
            $res = $con->query('SELECT COUNT(*) AS n FROM ogd_haltestellen');
            if ($res === false) {
                return ['state' => 'fail', 'detail' => 'DB-Fehler: ' . $con->error];
            }
            $n = (int) ($res->fetch_assoc()['n'] ?? 0);
            if ($n === 0) {
                return [
                    'state'  => 'fail',
                    'detail' => 'ogd_haltestellen ist leer — noch kein OGD-Reload durchgeführt (admin.php).',
                ];
            }

            $ts = is_file(OGD_LAST_RELOAD_FILE)
                ? (int) trim((string) @file_get_contents(OGD_LAST_RELOAD_FILE))
                : 0;

            // Vor dem ersten Reload nach TASK-24 gibt es die Datei noch nicht.
            // Dann wird das Alter ausdrücklich als unbekannt gemeldet, statt
            // eine Aktualität zu behaupten, die niemand geprüft hat.
            if ($ts <= 0) {
                return [
                    'state'  => 'ok',
                    'detail' => $n . ' Haltestellen. Zeitpunkt des letzten Reloads unbekannt — er wird'
                        . ' erst ab dem nächsten Reload (admin.php) festgehalten.',
                ];
            }

            // Die Stammdaten sind Fahrplandaten: sie ändern sich selten, aber
            // ein Stand von über einem halben Jahr heißt, dass neue Haltestellen
            // und Linienänderungen fehlen. 180 Tage ist bewusst großzügig — der
            // Reload läuft ausschließlich manuell, eine engere Schwelle wäre
            // Dauergelb ohne Erkenntnisgewinn.
            $alterTage = (time() - $ts) / 86400;
            if ($alterTage > 180) {
                return [
                    'state'           => 'warn',
                    'detail'          => sprintf('%d Haltestellen, letzter Reload vor %.0f Tagen (Schwelle 180).', $n, $alterTage),
                    'last_success_ts' => $ts,
                ];
            }
            return [
                'state'           => 'ok',
                'detail'          => sprintf('%d Haltestellen, letzter Reload vor %.0f Tagen.', $n, $alterTage),
                'last_success_ts' => $ts,
            ];
        },
    ],
    [
        // Eigener Host, der mit der Echtzeit-API nichts zu tun hat: fällt er
        // aus, merkt man es sonst erst, wenn jemand einen Reload anstößt.
        // HEAD reicht — die Haltestellen-CSV ist mehrere hundert kB groß.
        'name'  => 'OGD-Datenquelle (data.wien.gv.at)',
        'check' => static fn(): array => Status::httpCheck(
            OGD_CSV_URLS['haltestellen'],
            3,
            [CURLOPT_NOBODY => true]
        ),
    ],
    [
        'name'  => 'SMTP (Mailversand)',
        'check' => static fn(): array => Status::smtpCheck(),
    ],
];

$results = Status::run($checks, [
    'cacheFile' => __DIR__ . '/../data/status_cache.json',
    'cacheTtl'  => 60,
]);

if (($_GET['format'] ?? '') === 'json') {
    if (empty($_SESSION['loggedin'])) {
        http_response_code(403);
        exit;
    }
    header('Content-Type: application/json');
    echo Status::json($results, ['app' => APP_CODE]);
    exit;
}

$isAdmin = (($_SESSION['rights'] ?? '') === 'Admin');
?>
<?php render_header(); ?>

<main class="container-md mt-4 mb-4" id="main-content" tabindex="-1">
  <h4 class="mb-3"><?= icon('shield', 'me-2') ?>Status</h4>

  <div class="app-card mb-3">
    <div class="app-card-header">Status</div>
    <div class="app-card-body">
      <?php Status::render($results, $isAdmin, ['cspNonce' => $_cspNonce ?? '', 'cacheTtl' => 60]); ?>
    </div>
  </div>
</main>

<?php render_footer(); ?>
