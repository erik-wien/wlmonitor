<?php
/**
 * web/board_snapshot.php — Debug-Feature: laesst den E1003 seinen
 * tatsaechlichen Panel-Inhalt hochladen, damit er extern (z.B. von Claude)
 * eingesehen werden kann. Zeigt auch lokale Overlay-Reste (Fehler-Banner,
 * Touch-Blob, Marker), die der normale board.php-Server-Render selbst
 * nicht kennt -- s. board_state.php fuer den Flag-Mechanismus.
 *
 * GET ?request=1   Setzt das Anfrage-Flag; die Firmware laedt beim naechsten
 *                   erfolgreichen Poll hoch (X-Board-Snapshot-Requested-
 *                   Header in board.php).
 * GET ?view=1      Liefert den zuletzt hochgeladenen Schnappschuss als PNG.
 * POST             Geraeteseitiger Upload: roher 1bpp-Puffer im Body,
 *                   Authorization: Bearer <token>. Loescht das Anfrage-Flag
 *                   erst nach erfolgreichem Speichern (Retry-sicher).
 *
 * Auth wie board.php: Authorization: Bearer <token>, X-Auth-Token oder
 * ?token= als Ausweichweg (gleiche Sicherheitsabwaegung, s. board.php-Kopf).
 */
declare(strict_types=1);

if (empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['HTTP_X_AUTH_TOKEN'])
    && !empty($_GET['token'])) {
    $_SERVER['HTTP_X_AUTH_TOKEN'] = (string) $_GET['token'];
}

require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/board_render.php';
require_once __DIR__ . '/../inc/board_state.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function board_snapshot_error_out(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

$userId = auth_api_request_user();
if ($userId === null) {
    if (auth_api_token_presented()) {
        appendLog($con, 'board', 'board_snapshot: Zugriff ohne gueltiges Token');
    }
    board_snapshot_error_out(['error' => 'unauthorized'], 401);
}

$token = auth_api_token_from_request();
$hash = board_state_hash($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = (string) file_get_contents('php://input');
    $expectedBytes = (int) ceil(BOARD_WIDTH / 8) * BOARD_HEIGHT;
    if (strlen($body) !== $expectedBytes) {
        board_snapshot_error_out(['error' => 'unexpected_size'], 400);
    }

    board_state_save_snapshot($hash, $body);
    board_state_clear_snapshot_request($hash);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['saved' => true], JSON_THROW_ON_ERROR);
    exit;
}

if (($_GET['view'] ?? '') === '1') {
    $packed = board_state_load_snapshot($hash);
    if ($packed === null) {
        board_snapshot_error_out(['error' => 'no_snapshot'], 404);
    }

    header('Content-Type: image/png');
    echo board_1bpp_packed_to_png($packed, BOARD_WIDTH, BOARD_HEIGHT);
    exit;
}

if (($_GET['request'] ?? '') === '1') {
    board_state_request_snapshot($hash);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['requested' => true], JSON_THROW_ON_ERROR);
    exit;
}

board_snapshot_error_out(['error' => 'missing_action'], 400);
