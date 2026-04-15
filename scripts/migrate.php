<?php
/**
 * scripts/migrate.php
 *
 * Runs pending SQL migrations against the active environment's database.
 * Safe to run repeatedly — already-applied migrations are skipped.
 *
 * Usage:  php scripts/migrate.php
 */

$root = dirname(__DIR__);
require_once $root . '/inc/yaml.php';

$cfg = wl_yaml_load($root . '/config.yaml');
$env = $cfg['target'] ?? 'local';
$db  = $cfg['db'];

echo "Environment : $env\n";
echo "Database    : {$db['name']} @ {$db['host']}\n\n";

// ── Connect ───────────────────────────────────────────────────────────────────

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$con = mysqli_connect($db['host'], $db['user'], $db['password'], $db['name']);
mysqli_set_charset($con, 'utf8');

// ── Ensure tracking table exists ──────────────────────────────────────────────

$con->query("CREATE TABLE IF NOT EXISTS db_migrations (
    name       VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

// ── Load already-applied migrations ──────────────────────────────────────────

$applied = [];
$res = $con->query('SELECT name FROM db_migrations');
while ($row = $res->fetch_assoc()) {
    $applied[$row['name']] = true;
}

// ── Run pending migrations in filename order ──────────────────────────────────

$files = glob($root . '/migrations/*.sql');
sort($files);
$ran = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (isset($applied[$name])) {
        echo "  skip   $name\n";
        continue;
    }

    $sql = file_get_contents($file);

    // Execute — multi_query handles files with multiple statements
    $con->multi_query($sql);
    do {
        if ($result = $con->store_result()) {
            $result->free();
        }
    } while ($con->more_results() && $con->next_result());

    $stmt = $con->prepare('INSERT INTO db_migrations (name) VALUES (?)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->close();

    echo "  apply  $name\n";
    $ran++;
}

echo "\n" . ($ran === 0 ? 'Nothing to migrate.' : "$ran migration(s) applied.") . "\n";
