<?php
// tests/bootstrap.php

require_once __DIR__ . '/../vendor/autoload.php';

$_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';

require_once __DIR__ . '/../include/initialize.php';

// Load wlmonitor business-logic modules
require_once __DIR__ . '/../inc/favorites.php';
require_once __DIR__ . '/../inc/stations.php';
require_once __DIR__ . '/../inc/admin.php';
require_once __DIR__ . '/../inc/monitor.php';

if (!file_exists(__DIR__ . '/../data')) {
    mkdir(__DIR__ . '/../data', 0755, true);
}
$rateLimitFile = __DIR__ . '/../data/ratelimit.json';
if (!file_exists($rateLimitFile)) {
    file_put_contents($rateLimitFile, '{}');
}
