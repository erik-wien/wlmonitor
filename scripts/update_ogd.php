<?php
/**
 * scripts/update_ogd.php
 *
 * Downloads the three Wiener Linien OGD static CSV files from data.wien.gv.at
 * and reloads ogd_haltestellen, ogd_steige, ogd_linien.
 *
 * Run from repo root:  php scripts/update_ogd.php
 * Production:          APP_ENV=production php scripts/update_ogd.php
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../include/initialize.php';
require_once __DIR__ . '/../inc/ogd.php';

$result = ogd_update($con);

foreach ($result['log'] as $line) {
    echo $line . "\n";
}

if (!$result['ok']) {
    echo "FAILED: " . $result['error'] . "\n";
    exit(1);
}
