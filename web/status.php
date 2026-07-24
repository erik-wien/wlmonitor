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
 *  - OGD-Stammdaten (Wiener Linien): ogd_haltestellen/_linien/_steige werden
 *    per DELETE+INSERT komplett neu geladen (inc/ogd.php) — es gibt keine
 *    Lauf-Zeitstempel-Spalte und keinen Log-Eintrag pro Reload. Die
 *    STAND-Spalte (Schema-Kommentar: "Stand" laut WL-CSV) wäre der naheliegende
 *    Kandidat, ist aber in der lokalen Praxis leer/NULL (empirisch geprüft,
 *    s. Report) — die Wiener-Linien-CSVs befüllen sie nicht zuverlässig.
 *    Also: Zeilenzahl>0 als Minimal-Check (§Fallback lt. TASK-22), kein
 *    Alters-Warn-Zustand möglich.
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

use Erikr\Chrome\Status;

auth_require();

$checks = [
    [
        'name'  => 'Datenbank',
        'check' => static fn() => Status::dbCheck(static fn() => $con->ping(), 'Verbindung ok.'),
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
            curl_close($ch);

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
            return [
                'state'  => 'ok',
                'detail' => $n . ' Haltestellen (Zeilenzahl-Check — die STAND-Spalte wird von den'
                    . ' Wiener-Linien-CSVs nicht zuverlässig befüllt, daher kein Reload-Zeitstempel verfügbar).',
            ];
        },
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
