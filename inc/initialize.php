<?php
/**
 * inc/initialize.php
 *
 * Bootstrap file — MUST be the first include in every PHP entry point.
 *
 * Responsibilities:
 *  1. Load config and define constants.
 *  2. Open $con (MySQLi to wlmonitor DB; auth queries use auth. prefix).
 *  3. Define RATE_LIMIT_FILE and AUTH_DB_PREFIX for the auth library.
 *  4. Call auth_bootstrap() — security headers, session, CSRF.
 *  5. Define wlmonitor-specific utility functions.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/yaml.php';

// ── Load config ───────────────────────────────────────────────────────────────
//
// config.yaml is generated per-target by mcp/generate.py and is the single
// source of truth at runtime. To switch environments, regenerate with
//   python3 ../mcp/generate.py --app wlmonitor --target <local|akadbrain|world4you>

$_cfg = wl_yaml_load(__DIR__ . '/../config.yaml');

define('APP_ENV',  $_cfg['app']['env'] ?? 'dev');
define('APP_CODE', $_cfg['APP_CODE'] ?? 'wlm');

/**
 * Das E-Paper-Display (reTerminal E1003) haengt an EINER Instanz: eriks.cloud
 * auf akadbrain. Nur dort steht der MQTT-Broker, den web/mqtt/ zum Senden
 * braucht (mosquitto auf 127.0.0.1) und den scripts/akadbrain/mqtt_subscriber.py
 * liest; nur dort liegen data/board_state/ und der Wetter-Cache des Boards.
 *
 * Auf world4you (wlmonitor.jardyx.com) gibt es davon NICHTS -- ein
 * "eInk Display"-Menuepunkt fuehrte dort auf eine Seite, die grundsaetzlich
 * "Senden fehlgeschlagen" meldet, und "Board-Einstellungen" wuerde ein
 * Geraet konfigurieren, das diese Instanz nie ausliefert (Nutzervorgabe
 * 2026-09-03: "eInk Display gibts nur auf eriks.cloud, nicht jardyx").
 *
 * APP_ENV ist exakt der Deploy-Target-Name (mcp/generate.py: env = target).
 * Bewusst als Positivliste: eine neue Umgebung bekommt das Feature nur,
 * wenn sie hier eingetragen wird -- nicht automatisch.
 */
function wl_board_feature_available(string $env): bool
{
    return in_array($env, ['akadbrain', 'local'], true);
}
define('BOARD_FEATURE_AVAILABLE', wl_board_feature_available(APP_ENV));

define('SCRIPT_PATH',    '/home/.sites/765/site679/web/jardyx.com/wlmonitor/');
define('CURRENT_PATH',   __FILE__);
define('APIKEY',         'tVqqssNTeDyFb35');
define('MAX_DEPARTURES', 2);
define('APP_VERSION',    '3.0');
define('APP_BUILD',      81);

$_db = $_cfg['db'];
define('DATABASE_HOST',     $_db['host']);
define('DATABASE_USER',     $_db['user']);
define('DATABASE_PASS',     $_db['password']);
define('DATABASE_NAME',     $_db['name']);
define('AUTH_DATABASE_NAME', $_cfg['auth_db']['name'] ?? 'auth');
define('APP_BASE_URL',       rtrim($_cfg['app']['base_url'] ?? '', '/'));
define('APP_NAME',           $_cfg['app']['name']          ?? 'WL Monitor');
define('APP_SUPPORT_EMAIL',  $_cfg['app']['support_email'] ?? 'contact@eriks.cloud');
define('APP_COLOR',          $_cfg['app']['color']         ?? '#e2001a');

/** Prefix for all cross-DB auth table references (e.g. 'auth.'). */
define('AUTH_DB_PREFIX', AUTH_DATABASE_NAME . '.');

// Mail-Konfiguration: erikr/auth sucht diesen Pfad ZUERST, danach die
// Systempfade (/opt/homebrew/etc, /etc/jardyx). Auf world4you ist keiner
// der beiden anlegbar und open_basedir sperrt alles ausserhalb des
// Web-Verzeichnisses aus — die Datei liegt dort deshalb neben der
// config.yaml im App-Wurzelverzeichnis, das nicht ausgeliefert wird
// (nachgewiesen 2026-07-28: HTTP 404). Fehlt die Datei, gelten die
// Systempfade wie bisher. auth TASK-6.
define('AUTH_MAIL_CONFIG_PATH', dirname(__DIR__) . '/mail.ini');


// Privilegierte Loeschverbindung (Spec 2026-07-25 §3.1a). Verpflichtend:
// admin_delete_user() faellt NICHT auf $con zurueck.
$_adminDb = $_cfg['auth_admin_db'] ?? [];
define('AUTH_ADMIN_DB_HOST', $_adminDb['host']     ?? 'localhost');
define('AUTH_ADMIN_DB_NAME', $_adminDb['name']     ?? '');
define('AUTH_ADMIN_DB_USER', $_adminDb['user']     ?? '');
define('AUTH_ADMIN_DB_PASS', $_adminDb['password'] ?? '');
define('AUTH_ADMIN_DB_SOCKET', $_adminDb['socket'] ?? null);
unset($_adminDb);

unset($_cfg, $_db);

date_default_timezone_set('Europe/Vienna');

// ── Database ──────────────────────────────────────────────────────────────────

function createDBConnection(): mysqli {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $con = mysqli_connect(DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_NAME);
    // utf8mb4, nicht utf8: MySQLs "utf8" ist 3-Byte und kann keine
    // 4-Byte-Zeichen -- also keine Emoji. wlmonitor war 2026-09-04 die
    // EINZIGE App der Suite, die noch auf utf8 stand (suche, zeiterfassung,
    // Energie, biblio, last.fm, simplechat: laengst utf8mb4), obwohl alle
    // Tabellen bereits utf8mb4 sind. Aufgefallen an einem echten
    // Kalendernamen ("🔜 Eriks Termine"), den MariaDB mit "Incorrect string
    // value" abwies. Da nur die VERBINDUNG umgestellt wird und keine Spalte,
    // entfaellt das ueblich heikle Thema (Index-Schluessellaengen).
    mysqli_set_charset($con, 'utf8mb4');
    return $con;
}

$con = createDBConnection();

// ── Auth library constants (must be defined before autoload side-effects) ─────

define('RATE_LIMIT_FILE', __DIR__ . '/../data/ratelimit.json');

// ── Bootstrap (security headers + session + CSRF) ─────────────────────────────

// img-src blob: is required by the Cropper.js avatar editor in profil.php,
// which previews the selected file via URL.createObjectURL() before upload.
auth_bootstrap(['img-src' => 'blob:'], $con);

// ── Cross-DB cleanup hooks for admin_delete_user() ────────────────────────────
//
// wl_favorites and wl_preferences live in the wlmonitor DB, not in auth,
// so they cannot use FK ON DELETE CASCADE against auth_accounts.
// admin_delete_user() ruft diese Callables VOR dem DELETE auf. Es gibt keine
// gemeinsame Transaktion (andere Verbindung, andere DB) — dafuer gilt: schlaegt
// ein Hook fehl, wird das Konto NICHT geloescht. Hooks muessen idempotent sein.

admin_register_delete_cleanup(static function (mysqli $authCon, int $userId): void {
    global $con;
    $stmt = $con->prepare('DELETE FROM wl_favorites WHERE idUser = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $con->prepare('DELETE FROM wl_preferences WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
});

// ── Session globals ───────────────────────────────────────────────────────────

$loggedIn  = $_SESSION['loggedin'] ?? 0;
$username  = $loggedIn ? $_SESSION['username'] : '';

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
