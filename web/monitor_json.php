<?php
// monitor_json.php
// JSON departure feed — for use with Home Assistant and similar integrations.
// No authentication required. Rate limiting applies via the shared API key.

require_once(__DIR__ . '/../inc/initialize.php');
require_once(__DIR__ . '/../inc/monitor.php');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$diva = sanitizeDivaInput($_GET['diva'] ?? $_SESSION['diva'] ?? '60200103');
if ($diva !== '') {
    $_SESSION['diva'] = $diva;
}

$maxDep = (int) ($_SESSION['departures'] ?? MAX_DEPARTURES);

try {
    $data = monitor_get($con, $diva, $maxDep);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => $e->getMessage()], JSON_HEX_TAG | JSON_HEX_AMP);
}
