<?php
/**
 * web/monitor_json.php — JSON-Abfahrtsfeed für Home Assistant.
 *
 * Die Antwortform ist bewusst UNVERÄNDERT (Home Assistant parst sie); nur die
 * Anfrage hat sich geändert: sie braucht jetzt ein Token.
 *
 * Vorher war dieser Endpunkt anonym erreichbar und damit ein offener Proxy auf
 * die Wiener-Linien-API zu Lasten unseres Kontingents. Der Kopfkommentar
 * behauptete ein Rate-Limit, das es nie gab (RATE_LIMIT_FILE wird definiert,
 * aber nirgends benutzt). Ausserdem legte jeder Aufruf eine PHP-Session an
 * (Cookie 4 Tage) und die Antwort hing an der Session des Aufrufers.
 *
 * Für neue Clients: web/board.php (schlankere Form).
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/monitor.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$userId = auth_api_request_user();
if ($userId === null) {
    // Nur ein VORGELEGTES, aber ungueltiges Token ist die berichtenswerte
    // Anomalie. Fehlt der Header ganz, ist das ein alter Client waehrend der
    // Umstellung auf Token-Pflicht — jeder seiner Polls sonst eine
    // auth_log-Zeile in einer Tabelle, die sich sieben Apps teilen.
    if (auth_api_token_presented()) {
        appendLog($con, 'monitor_json', 'Zugriff ohne gueltiges Token (Token vorgelegt, aber ungueltig)');
    }
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$diva = sanitizeDivaInput((string) ($_GET['diva'] ?? '60200103'));

try {
    // Anzahl der Abfahrten aus den Einstellungen des TOKEN-Benutzers — nicht
    // aus der Sitzung eines zufaelligen Aufrufers. Die Abfrage muss INNERHALB
    // des try laufen: mysqli laeuft im Exception-Modus, ein fehlendes GRANT
    // oder eine fehlende Tabelle waere sonst ein unbehandelter Fatal.
    $maxDep = MAX_DEPARTURES;
    $stmt = $con->prepare('SELECT departures FROM wl_preferences WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        $maxDep = max(1, (int) $row['departures']);
    }
    $stmt->close();

    $data = monitor_get($con, $diva, $maxDep);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    // Klartext geht nur ins Log (§21) — dem Client bleibt nur die Kennung.
    appendLog($con, 'monitor_json', 'Fehler: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'upstream_unavailable']);
}
