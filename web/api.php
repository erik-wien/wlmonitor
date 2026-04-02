<?php
// web/api.php
// Single JSON API dispatcher

require_once(__DIR__ . '/../include/initialize.php');
require_once(__DIR__ . '/../inc/monitor.php');
require_once(__DIR__ . '/../inc/stations.php');
require_once(__DIR__ . '/../inc/favorites.php');
require_once(__DIR__ . '/../inc/admin.php');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function api_json(mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    exit;
}

function api_require_login(): void {
    if (empty($_SESSION['loggedin'])) {
        api_json(['error' => 'Not authenticated'], 401);
    }
}

function api_require_admin(): void {
    api_require_login();
    if (($_SESSION['rights'] ?? '') !== 'Admin') {
        api_json(['error' => 'Forbidden'], 403);
    }
}

function api_require_csrf(): void {
    if (!csrf_verify()) {
        api_json(['error' => 'Invalid CSRF token'], 403);
    }
}

try {
    switch ($action) {

        // --- Monitor ---
        case 'monitor':
            $diva = sanitizeDivaInput($_GET['diva'] ?? $_SESSION['diva'] ?? '60200103');
            $_SESSION['diva'] = $diva;
            $maxDep = (int) ($_SESSION['departures'] ?? MAX_DEPARTURES);
            api_json(monitor_get($con, $diva, $maxDep));

        // --- Stations ---
        case 'stations':
            if (isset($_GET['lat'], $_GET['lon'])) {
                $lat = (float) $_GET['lat'];
                $lon = (float) $_GET['lon'];
                if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90.0 || abs($lon) > 180.0) {
                    api_json(['error' => 'Invalid coordinates'], 400);
                }
                api_json(stations_by_distance($con, $lat, $lon));
            }
            api_json(stations_alpha($con));

        case 'position_save':
            api_require_login();
            api_require_csrf();
            $lat = (float) ($_POST['lat'] ?? 0);
            $lon = (float) ($_POST['lon'] ?? 0);
            if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90.0 || abs($lon) > 180.0) {
                api_json(['error' => 'Invalid coordinates'], 400);
            }
            stations_save_position($con, $lat, $lon);
            api_json(['ok' => true]);

        // --- Favorites ---
        case 'favorites':
            api_require_login();
            api_json(favorites_get($con, (int) $_SESSION['id']));

        case 'favorites_check':
            api_require_login();
            $diva = sanitizeDivaInput($_GET['diva'] ?? '');
            api_json(['found' => favorites_check($con, (int) $_SESSION['id'], $diva)]);

        case 'favorites_add':
            api_require_login();
            api_require_csrf();
            $id = favorites_add(
                $con,
                (int) $_SESSION['id'],
                $_POST['title']  ?? '',
                $_POST['diva']   ?? '',
                $_POST['bclass'] ?? 'btn-outline-success',
                (int) ($_POST['sort'] ?? 0)
            );
            api_json(['id' => $id]);

        case 'favorites_edit':
            api_require_login();
            api_require_csrf();
            $ok = favorites_edit(
                $con,
                (int) $_SESSION['id'],
                (int) ($_POST['favId'] ?? 0),
                $_POST['title']  ?? '',
                $_POST['diva']   ?? '',
                $_POST['bclass'] ?? 'btn-outline-success',
                (int) ($_POST['sort'] ?? 0)
            );
            api_json(['ok' => $ok]);

        case 'favorites_delete':
            api_require_login();
            api_require_csrf();
            $ok = favorites_delete($con, (int) $_SESSION['id'], (int) ($_POST['id'] ?? 0));
            api_json(['ok' => $ok]);

        case 'favorites_sort':
            api_require_login();
            api_require_csrf();
            $body = json_decode(file_get_contents('php://input', length: 65536), true);
            if (!is_array($body)) {
                api_json(['error' => 'Invalid JSON body'], 400);
            }
            favorites_save_sort($con, (int) $_SESSION['id'], $body);
            api_json(['ok' => true]);

        // --- Log ---
        case 'log':
            api_require_login();
            $page   = max(1, (int) ($_GET['page']  ?? $_SESSION['logPage']));
            $limit  = max(1, min(100, (int) ($_GET['limit'] ?? $_SESSION['logLimit'])));
            $offset = ($page - 1) * $limit;
            $uid    = (int) $_SESSION['id'];
            $stmt   = $con->prepare(
                'SELECT context, activity, origin, INET_NTOA(ipAdress) AS ip, logTime
                 FROM wl_log WHERE idUser = ? ORDER BY logTime DESC LIMIT ? OFFSET ?'
            );
            $stmt->bind_param('iii', $uid, $limit, $offset);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            api_json($rows);

        // --- Admin ---
        case 'admin_users':
            api_require_admin();
            $page   = max(1, (int) ($_GET['page'] ?? 1));
            $filter = $_GET['filter'] ?? '';
            api_json(admin_list_users($con, $page, 25, $filter));

        case 'admin_user_edit':
            api_require_admin();
            api_require_csrf();
            $ok = admin_edit_user(
                $con,
                (int) ($_POST['id']         ?? 0),
                $_POST['email']             ?? '',
                $_POST['rights']            ?? 'User',
                (int) ($_POST['disabled']   ?? 0),
                (int) ($_POST['departures'] ?? MAX_DEPARTURES),
                (int) ($_POST['debug']      ?? 0)
            );
            api_json(['ok' => $ok]);

        case 'admin_user_reset':
            api_require_admin();
            api_require_csrf();
            $newPass = admin_reset_password($con, (int) ($_POST['id'] ?? 0));
            api_json(['password' => $newPass]);

        case 'admin_user_delete':
            api_require_admin();
            api_require_csrf();
            $ok = admin_delete_user($con, (int) ($_POST['id'] ?? 0), (int) $_SESSION['id']);
            api_json(['ok' => $ok]);

        default:
            api_json(['error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    appendLog($con, 'api', 'Error: ' . $e->getMessage(), 'web');
    api_json(['error' => 'Internal server error'], 500);
}
