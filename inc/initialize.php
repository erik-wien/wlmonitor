<?php
/**
 * inc/initialize.php
 *
 * Bootstrap file — MUST be the first include in every PHP entry point.
 *
 * Responsibilities:
 *  1. Load config and define constants.
 *  2. Open $con (MySQLi to wlmonitor DB; auth queries use jardyx_auth. prefix).
 *  3. Define RATE_LIMIT_FILE and AUTH_DB_PREFIX for the auth library.
 *  4. Call auth_bootstrap() — security headers, session, CSRF.
 *  5. Define wlmonitor-specific utility functions.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ── Load config ───────────────────────────────────────────────────────────────

$_dbConfigFile = __DIR__ . '/../config/db.json';
$_dbConfig     = json_decode(file_get_contents($_dbConfigFile), true);
$_dbEnv        = file_exists(__DIR__ . '/../app.world4you') ? 'world4you'
               : (file_exists(__DIR__ . '/../app.prod') ? 'prod' : 'dev');
define('APP_ENV', $_dbEnv);
$_db           = $_dbConfig[$_dbEnv] ?? $_dbConfig['dev'];

define('SCRIPT_PATH',    '/home/.sites/765/site679/web/jardyx.com/wlmonitor/');
define('CURRENT_PATH',   __FILE__);
define('AVATAR_DIR',     'img/user/');
define('APIKEY',         'tVqqssNTeDyFb35');
define('MAX_DEPARTURES', 2);
define('APP_VERSION',    '3.0');
define('APP_BUILD',      21);

define('DATABASE_HOST',     $_db['host']);
define('DATABASE_USER',     $_db['user']);
define('DATABASE_PASS',     $_db['pass']);
define('DATABASE_NAME',     $_db['name']);
define('AUTH_DATABASE_NAME',$_db['auth_name'] ?? 'jardyx_auth');
define('APP_BASE_URL',      rtrim($_db['base_url'] ?? '', '/'));

/** Prefix for all cross-DB auth table references (e.g. 'jardyx_auth.'). */
define('AUTH_DB_PREFIX', AUTH_DATABASE_NAME . '.');

$_smtp = $_dbConfig['smtp_' . $_dbEnv] ?? $_dbConfig['smtp_dev'];
define('SMTP_HOST',      $_smtp['host']);
define('SMTP_PORT',      (int) $_smtp['port']);
define('SMTP_USER',      $_smtp['user']);
define('SMTP_PASS',      $_smtp['pass']);
define('SMTP_FROM',      $_smtp['from']);
define('SMTP_FROM_NAME', $_smtp['from_name']);

unset($_dbConfigFile, $_dbConfig, $_dbEnv, $_db, $_smtp);

date_default_timezone_set('Europe/Vienna');

// ── Database ──────────────────────────────────────────────────────────────────

function createDBConnection(): mysqli {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $con = mysqli_connect(DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_NAME);
    mysqli_set_charset($con, 'utf8');
    return $con;
}

$con = createDBConnection();

// ── Auth library constants (must be defined before autoload side-effects) ─────

define('RATE_LIMIT_FILE', __DIR__ . '/../data/ratelimit.json');

// ── Bootstrap (security headers + session + CSRF) ─────────────────────────────

auth_bootstrap([]);

// ── Session globals ───────────────────────────────────────────────────────────

$loggedIn  = $_SESSION['loggedin'] ?? 0;
$username  = $loggedIn ? $_SESSION['username'] : '';
$img       = $_SESSION['img'] = $_SESSION['img'] ?? 'user-md-grey.svg';
$avatarDir = $loggedIn ? AVATAR_DIR . $_SESSION['img'] : '';

// ── wlmonitor-specific utilities ──────────────────────────────────────────────

/**
 * Strip everything except digits and commas from a DIVA/RBL input string.
 */
function sanitizeDivaInput(string $divaGet): string {
    return preg_replace('/[^0-9,]/', '', $divaGet);
}

/** Alias for sanitizeDivaInput() — backward compatibility. */
function sanitizeRblInput(string $input): string {
    return sanitizeDivaInput($input);
}

/**
 * Render an SVG icon from the sprite.
 *
 * @param string $id    Icon ID (without 'icon-' prefix, e.g. 'subway').
 * @param string $class Extra CSS classes to add alongside 'icon'.
 * @return string       HTML <svg> element (safe, no user input reflected).
 */
function icon(string $id, string $class = ''): string {
    $c = 'icon' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') : '');
    $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    return '<svg class="' . $c . '" aria-hidden="true" focusable="false">'
         . '<use href="css/icons.svg#icon-' . $safeId . '"></use>'
         . '</svg>';
}
