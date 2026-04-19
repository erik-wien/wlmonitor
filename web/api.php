<?php
/**
 * web/api.php
 *
 * Unified JSON API dispatcher.
 *
 * All AJAX requests from wl-monitor.js and admin.php are routed through this
 * single file. The action to perform is passed as the `action` parameter
 * (GET or POST).
 *
 * Authentication / authorisation model
 * ─────────────────────────────────────
 * - api_require_login()  : aborts with HTTP 401 if the session is not logged in.
 * - api_require_admin()  : aborts with HTTP 403 if the role is not 'Admin'.
 * - api_require_csrf()   : aborts with HTTP 403 if the CSRF token is invalid.
 *
 * Admin actions prefixed `admin_*` are handled by
 * \Erikr\Chrome\Admin\Dispatch::handle() (POST + CSRF + Admin-role enforced
 * inside the dispatcher). wlmonitor-specific admin actions are intercepted
 * BEFORE delegation, so departures/debug/OGD/colours stay owned here.
 *
 * Action inventory
 * ────────────────
 * Public (no auth):
 *   monitor          GET   ?diva=            Realtime departure data
 *   stations         GET   ?lat&lon | (none) Station list by distance or A–Z
 *
 * Authenticated (login required):
 *   theme_save       POST  theme=            Save theme preference to DB
 *   position_save    POST  lat= lon=         Save geolocation to session (CSRF)
 *   favorites        GET                     List current user's favorites
 *   favorites_check  GET   ?diva=            Check if DIVA is already a favorite
 *   favorites_add    POST  …                 Create favorite (CSRF)
 *   favorites_edit   POST  …                 Update favorite (CSRF)
 *   favorites_delete POST  id=               Delete favorite (CSRF)
 *   favorites_sort   POST  JSON body         Reorder favorites (CSRF)
 *   log              GET   ?page&limit       Activity log for the current user
 *   state_save       POST  favId= diva=     Persist last-viewed monitor state (CSRF)
 *
 * Admin only (Admin role + CSRF for writes):
 *   admin_ogd_update   POST  (CSRF)          Download & reload WL station data
 *   admin_user_edit    POST  … (CSRF)        Edit user (departures + debug)
 *   admin_color_edit   POST  color= farbe=   Rename a color label
 *   admin_user_list / admin_user_create / admin_user_delete /
 *   admin_user_reset / admin_user_toggle_disabled / admin_user_revoke_totp /
 *   admin_user_reset_invalid / admin_log_list
 *                      POST  (CSRF)          Handled by Chrome\Admin\Dispatch.
 */

require_once(__DIR__ . '/../inc/initialize.php');
require_once(__DIR__ . '/../inc/monitor.php');
require_once(__DIR__ . '/../inc/stations.php');
require_once(__DIR__ . '/../inc/favorites.php');
require_once(__DIR__ . '/../inc/admin.php');
require_once(__DIR__ . '/../inc/colors.php');
require_once(__DIR__ . '/../inc/ogd.php');
require_once(__DIR__ . '/../inc/state.php');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * Output $data as JSON and terminate.
 *
 * JSON_HEX_TAG and JSON_HEX_AMP ensure the output is safe to embed in
 * HTML contexts and cannot break out of a <script> block.
 *
 * @param mixed $data   Value to encode.
 * @param int   $status HTTP status code (default 200).
 * @return never
 */
function api_json(mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    exit;
}

/**
 * Abort with HTTP 401 if the current session is not authenticated.
 */
function api_require_login(): void {
    if (empty($_SESSION['loggedin'])) {
        api_json(['error' => 'Not authenticated'], 401);
    }
}

/**
 * Abort with HTTP 403 if the current user does not have the Admin role.
 *
 * Implicitly also calls api_require_login().
 */
function api_require_admin(): void {
    api_require_login();
    if (($_SESSION['rights'] ?? '') !== 'Admin') {
        api_json(['error' => 'Forbidden'], 403);
    }
}

/**
 * Abort with HTTP 403 if the submitted CSRF token is invalid.
 *
 * Accepts the token from $_POST['csrf_token'] or the X-CSRF-TOKEN header.
 */
function api_require_csrf(): void {
    if (!csrf_verify()) {
        api_json(['error' => 'Invalid CSRF token'], 403);
    }
}

try {
    switch ($action) {

        // ── Monitor ──────────────────────────────────────────────────────────

        case 'monitor':
            // DIVA falls back to the session value, then to Karlsplatz as default.
            $diva = sanitizeDivaInput($_GET['diva'] ?? $_SESSION['diva'] ?? '60200103');
            $_SESSION['diva'] = $diva;
            $maxDep = (int) ($_SESSION['departures'] ?? MAX_DEPARTURES);
            $monitorData = monitor_get($con, $diva, $maxDep);

            // The WL API silently omits stops with no upcoming departures.
            // Inject empty placeholder entries so the JS can render a card for
            // every requested DIVA (filtered-favourite cards must always be visible).
            $requestedDivas = array_filter(array_map('trim', explode(',', $diva)));
            $returnedDivas  = [];
            foreach ($monitorData as $k => $v) {
                if (is_array($v) && isset($v['diva'])) $returnedDivas[] = $v['diva'];
            }
            $missingDivas = array_diff($requestedDivas, $returnedDivas);
            if (!empty($missingDivas)) {
                $nameMap = diva_info($con, array_values($missingDivas));
                foreach ($missingDivas as $missingDiva) {
                    $monitorData['__stop_' . $missingDiva] = [
                        'id'           => '__stop_' . $missingDiva,
                        'diva'         => $missingDiva,
                        'station_name' => $nameMap[$missingDiva]['station'] ?? $missingDiva,
                        'lines'        => [],
                    ];
                }
            }

            api_json($monitorData);

        // ── Stations ─────────────────────────────────────────────────────────

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

        // ── Theme ─────────────────────────────────────────────────────────────

        case 'theme_save':
            api_require_login();
            api_require_csrf();
            $t = $_POST['theme'] ?? '';
            if (!in_array($t, ['light', 'dark', 'auto'], true)) {
                api_json(['error' => 'Invalid theme'], 400);
            }
            $stmt = $con->prepare('UPDATE auth.auth_accounts SET theme = ? WHERE id = ?');
            $stmt->bind_param('si', $t, $_SESSION['id']);
            $stmt->execute();
            $stmt->close();
            $_SESSION['theme'] = $t;
            api_json(['ok' => true]);

        // ── Position ──────────────────────────────────────────────────────────

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

        // ── State ─────────────────────────────────────────────────────────────

        case 'state_save':
            api_require_login();
            api_require_csrf();
            // favId arrives as a FormData string ("5") or absent; reject "0" as invalid
            $favId = (isset($_POST['favId']) && ctype_digit($_POST['favId']) && (int) $_POST['favId'] > 0)
                ? (int) $_POST['favId'] : null;
            $diva  = sanitizeDivaInput($_POST['diva'] ?? '') ?: null;
            if ($favId !== null) {
                // Ownership check — silently ignore if the fav belongs to another user
                $chk = $con->prepare('SELECT id FROM wl_favorites WHERE id = ? AND idUser = ?');
                $chk->bind_param('ii', $favId, $_SESSION['id']);
                $chk->execute();
                if (!$chk->get_result()->fetch_row()) $favId = null;
                $chk->close();
            }
            state_upsert($con, (int) $_SESSION['id'], $favId, $diva);
            api_json(['ok' => true]);

        // ── Favorites ─────────────────────────────────────────────────────────

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
                $_POST['title']       ?? '',
                $_POST['diva']        ?? '',
                $_POST['bclass']      ?? 'btn-outline-success',
                (int) ($_POST['sort'] ?? 0),
                isset($_POST['filter_json']) ? (string) $_POST['filter_json'] : null
            );
            api_json(['id' => $id]);

        case 'favorites_edit':
            api_require_login();
            api_require_csrf();
            $ok = favorites_edit(
                $con,
                (int) $_SESSION['id'],
                (int) ($_POST['favId'] ?? 0),
                $_POST['title']       ?? '',
                $_POST['diva']        ?? '',
                $_POST['bclass']      ?? 'btn-outline-success',
                (int) ($_POST['sort'] ?? 0),
                isset($_POST['filter_json']) ? (string) $_POST['filter_json'] : null
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
            // Body is a JSON array: [{'id': 12, 'sort': 1}, …]
            $body = json_decode(file_get_contents('php://input', length: 65536), true);
            if (!is_array($body)) {
                api_json(['error' => 'Invalid JSON body'], 400);
            }
            favorites_save_sort($con, (int) $_SESSION['id'], $body);
            api_json(['ok' => true]);

        // ── Activity log ──────────────────────────────────────────────────────

        case 'log':
            api_require_login();
            $page   = max(1, (int) ($_GET['page']  ?? $_SESSION['logPage']));
            $limit  = max(1, min(100, (int) ($_GET['limit'] ?? $_SESSION['logLimit'])));
            $offset = ($page - 1) * $limit;
            $uid    = (int) $_SESSION['id'];
            $stmt   = $con->prepare(
                'SELECT context, activity, origin, INET_NTOA(ipAdress) AS ip, logTime
                 FROM auth_log WHERE idUser = ? ORDER BY logTime DESC LIMIT ? OFFSET ?'
            );
            $stmt->bind_param('iii', $uid, $limit, $offset);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            api_json($rows);

        // ── OGD station data update (admin, wlmonitor-specific) ──────────────

        case 'admin_ogd_update':
            api_require_admin();
            api_require_csrf();
            set_time_limit(120);    // CSV downloads can be slow
            ignore_user_abort(true); // Run to completion even if browser disconnects
            $result = ogd_update($con);
            appendLog($con, 'admin', 'OGD update ' . ($result['ok'] ? 'OK' : 'FAILED: ' . $result['error']));
            api_json($result, $result['ok'] ? 200 : 500);

        // ── User edit (admin, overrides Dispatch to carry departures + debug) ─

        case 'admin_user_edit':
            api_require_admin();
            api_require_csrf();
            $ok = wl_admin_edit_user(
                $con,
                (int) ($_POST['id']         ?? 0),
                $_POST['email']             ?? '',
                $_POST['rights']            ?? 'User',
                (int) ($_POST['disabled']   ?? 0),
                (int) ($_POST['departures'] ?? MAX_DEPARTURES),
                (int) ($_POST['debug']      ?? 0),
                (bool) ($_POST['totp_reset'] ?? false)
            );
            api_json(['ok' => $ok]);

        // ── Color labels (admin, wlmonitor-specific) ─────────────────────────

        case 'admin_color_edit':
            api_require_admin();
            api_require_csrf();
            $color = $_POST['color'] ?? '';
            $farbe = $_POST['farbe'] ?? '';
            if ($color === '' || $farbe === '') {
                api_json(['ok' => false, 'error' => 'color und farbe sind erforderlich.'], 400);
            }
            $ok = wl_color_edit($con, $color, $farbe);
            api_json(['ok' => $ok]);

        // ── All remaining admin_* actions: Chrome Dispatch ───────────────────

        default:
            if (str_starts_with($action, 'admin_')) {
                \Erikr\Chrome\Admin\Dispatch::handle($con, $action, [
                    'baseUrl' => APP_BASE_URL,
                    'selfId'  => (int) ($_SESSION['id'] ?? 0),
                ]);
                exit;
            }
            api_json(['error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    appendLog($con, 'api', 'Error: ' . $e->getMessage());
    api_json(['error' => 'Internal server error'], 500);
}
