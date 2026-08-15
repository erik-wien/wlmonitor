<?php
// tests/bootstrap.php

require_once __DIR__ . '/../vendor/autoload.php';

$_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';

require_once __DIR__ . '/../inc/initialize.php';

// PHPUnit's own runner requires this bootstrap file from inside a method, not
// from true top-level scope. That means $con (set by inc/initialize.php just
// above) lands in that method's local variables, never in true $GLOBALS. The
// admin_delete_user() cleanup hook registered in inc/initialize.php relies on
// `global $con;` to reach the wlmonitor DB connection — under PHPUnit it
// would otherwise resolve to null ("Call to a member function prepare() on
// null", surfacing as cleanup_failed). Promote it explicitly so that hook
// keeps working the same way it does when initialize.php is required from a
// real top-level entry point (index.php etc.).
$GLOBALS['con'] = $con;

// Load wlmonitor business-logic modules
require_once __DIR__ . '/../inc/favorites.php';
require_once __DIR__ . '/../inc/stations.php';
require_once __DIR__ . '/../inc/admin.php';
require_once __DIR__ . '/../inc/monitor.php';
require_once __DIR__ . '/../inc/board.php';
require_once __DIR__ . '/../inc/weather.php';

if (!file_exists(__DIR__ . '/../data')) {
    mkdir(__DIR__ . '/../data', 0755, true);
}
$rateLimitFile = __DIR__ . '/../data/ratelimit.json';
if (!file_exists($rateLimitFile)) {
    file_put_contents($rateLimitFile, '{}');
}
