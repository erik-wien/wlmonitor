<?php
/**
 * web/board.php — JSON-Abfahrtstafel für Geräte (E-Paper-Display, Home
 * Assistant), token-authentifiziert.
 *
 * GET /board.php?fav=<id>[,<id>…]
 * Authorization: Bearer <token>      (X-Auth-Token als Ausweichheader)
 *
 * Bewusst KEINE Sitzung: nichts wird aus $_SESSION gelesen oder dorthin
 * geschrieben. Alles, was die Antwort bestimmt, hängt am Token-Benutzer.
 * Der Vorgänger monitor_json.php tat das Gegenteil und legte bei jedem
 * anonymen Aufruf eine Session an (4 Tage Lebensdauer).
 *
 * Fehler nennen nach aussen nur eine Kennung; die Ursache geht ins auth_log
 * (Fehler-Regeln §21).
 *
 * Spec: docs/superpowers/specs/2026-08-01-epaper-abfahrtsmonitor-design.md
 */
declare(strict_types=1);

require_once __DIR__ . '/../inc/initialize.php';   // ruft auth_bootstrap(), löst das Token auf
require_once __DIR__ . '/../inc/favorites.php';
require_once __DIR__ . '/../inc/monitor.php';
require_once __DIR__ . '/../inc/board.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Antwort senden und beenden. */
function board_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

$userId = auth_api_request_user();
if ($userId === null) {
    appendLog($con, 'board', 'Zugriff ohne gueltiges Token'
        . (auth_api_token_presented() ? ' (Token vorgelegt, aber ungueltig)' : ' (kein Token)'));
    board_out(['error' => 'unauthorized'], 401);
}

try {
    $favs = board_selected_favorites(favorites_get($con, $userId), (string) ($_GET['fav'] ?? ''));
    if ($favs === []) {
        board_out(['generated' => date('c'), 'favorites' => []]);
    }

    // Zwei Abfahrten je Zeile — genau das, was das Layout zeigt. Mehr waere
    // unbenutzte Nutzlast auf einem Geraet mit wenig Heap.
    $monitor = monitor_get($con, board_all_divas($favs), 2);

    $out = ['generated' => date('c'), 'favorites' => []];
    foreach ($favs as $fav) {
        $out['favorites'][] = board_favorite($fav, $monitor);
    }
    board_out($out);
} catch (RuntimeException | InvalidArgumentException $e) {
    appendLog($con, 'board', 'Upstream-Fehler: ' . $e->getMessage());
    board_out(['error' => 'upstream_unavailable'], 503);
} catch (Throwable $e) {
    appendLog($con, 'board', 'Fehler: ' . get_class($e) . ': ' . $e->getMessage());
    board_out(['error' => 'server_error'], 500);
}
