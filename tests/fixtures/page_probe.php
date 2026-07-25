<?php
declare(strict_types=1);

/**
 * tests/fixtures/page_probe.php
 *
 * Out-of-process helper to exercise real web/*.php pages that call exit()
 * (auth_require() redirects) — adapted from simplechat's
 * tests/fixtures/page_probe.php for wlmonitor's session shape (see
 * inc/layout.php render_header() and web/profil.php / web/aktivitaet.php
 * for which $_SESSION keys the pages actually read).
 *
 * Invoked as: php page_probe.php <page-relative-to-web> <scenario.json>
 * Scenario JSON keys: loggedin (bool), id, username, rights, method, post.
 *
 * A pre-started native PHP session lets us seed $_SESSION before
 * inc/initialize.php's auth_bootstrap() -> session_start() (a no-op on an
 * already-active session).
 *
 * STDOUT carries whatever the page echoes (HTML). The HTTP status code is
 * reported on STDERR as "STATUS:<code>\n" via a shutdown function, since it
 * cannot be observed after exit().
 */

$page         = $argv[1] ?? '';
$scenarioFile = $argv[2] ?? '';
$scenario     = json_decode((string) file_get_contents($scenarioFile), true) ?? [];

session_start();
if (!empty($scenario['loggedin'])) {
    $_SESSION['loggedin']   = true;
    $_SESSION['id']         = $scenario['id']       ?? 999999;
    $_SESSION['username']   = $scenario['username'] ?? 'probe-user';
    $_SESSION['rights']     = $scenario['rights']    ?? 'User';
    $_SESSION['theme']      = 'auto';
    $_SESSION['csrf_token'] = 'probe-csrf-token';
} else {
    $_SESSION = [];
}
// inc/initialize.php's auth_bootstrap() unconditionally calls its own
// session_start($sessionOpts) (erikr/auth src/bootstrap.php). Calling
// session_start() while a session is already active raises an E_WARNING
// that this environment's php.ini (display_errors=STDOUT) writes straight
// into the page body — polluting the very output/headers this probe
// exists to check (and breaking header('Location: ...') via "headers
// already sent"). session_write_close() flushes our seeded $_SESSION to
// the session store and deactivates the session (without losing the
// session id), so auth_bootstrap()'s session_start() re-opens the SAME
// session cleanly instead of hitting the "already active" branch.
session_write_close();

$_POST = $scenario['post'] ?? [];
$_SERVER['REQUEST_METHOD'] = $scenario['method'] ?? 'GET';
$_SERVER['SCRIPT_NAME']    = '/' . $page;
$_SERVER['PHP_SELF']       = '/' . $page;
// Deliberately NOT *.eriks.cloud / *.jardyx.com / *.test — those hosts
// route auth_require() through the central-login redirect
// (auth_central_login_url(), vendor/erikr/auth/src/log.php). This host
// falls through to the app's own local login.php redirect instead, which
// is what we want to probe here.
$_SERVER['HTTP_HOST']      = 'wlmonitor.test.invalid';
// appendLog() -> getUserIpAddr() reads REMOTE_ADDR unconditionally
// (erikr/auth src/log.php) — unset under CLI otherwise.
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

// PHP's CLI SAPI has no implicit "200 by default" the way a real web server
// request does — http_response_code() reads false until something sets it.
// A page that renders normally (no header('Location: ...') redirect) never
// calls http_response_code() itself, so without this default the STATUS
// line would come out empty even on a successful render. header('Location:
// ...') always forces the code to 302 regardless of a prior explicit value
// (verified), so this default is safely overridden by auth_require()'s
// redirect path.
http_response_code(200);

register_shutdown_function(static function (): void {
    fwrite(STDERR, 'STATUS:' . http_response_code() . "\n");
});

require __DIR__ . '/../../web/' . $page;
