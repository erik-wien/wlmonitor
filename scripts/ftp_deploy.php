<?php
/**
 * scripts/ftp_deploy.php
 *
 * Uploads the application to a remote FTP server, then runs pending DB
 * migrations via a temporary self-deleting PHP runner served from the webroot.
 *
 * Usage:  php scripts/ftp_deploy.php [env]
 *   env defaults to 'world4you'
 */

$root = dirname(__DIR__);
$env  = $argv[1] ?? 'world4you';

$cfg = json_decode(file_get_contents($root . '/config/db.json'), true);
$db  = $cfg[$env] ?? null;

if (!$db || !isset($db['ftp_server'], $db['ftp_user'], $db['ftp_password'], $db['ftp_base_dir'])) {
    fwrite(STDERR, "No FTP config found for environment '$env'.\n");
    exit(1);
}

$ftpServer  = $db['ftp_server'];
$ftpUser    = $db['ftp_user'];
$ftpPass    = $db['ftp_password'];
$remoteBase = rtrim($db['ftp_base_dir'], '/');
$baseUrl    = rtrim($db['base_url'], '/');

// ── Exclude rules ─────────────────────────────────────────────────────────────
//
// migrations/ stays on the server (the migration runner needs it).
// scripts/    is excluded — deploy tooling doesn't belong on a web server.
// Dev-only vendor packages are excluded by directory name (matched at any depth).

$excludeDirs = [
    // project dirs not needed on server
    '.git', '.claude', 'tests', 'deprecated', 'docs',
    'img', 'config', 'data', 'backups', '.worktrees', 'worktrees',
    'scripts',
    // dev-only vendor packages
    'phpunit', 'sebastian', 'nikic', 'theseer', 'phar-io', 'myclabs', 'staabm',
    // vendor CLI binaries
    'bin',
];
$excludeNames = [
    '.gitignore', '.DS_Store', '.phpunit.result.cache',
    'CLAUDE.md', 'phpunit.xml', 'composer.json', 'composer.lock',
    'app.dev', 'app.prod', 'app.world4you',
];
$excludeExts = ['md'];

// ── Connect ───────────────────────────────────────────────────────────────────

echo "Connecting to $ftpServer ...\n";
$ftp = ftp_ssl_connect($ftpServer) ?: ftp_connect($ftpServer);
if (!$ftp) {
    fwrite(STDERR, "Could not connect to $ftpServer.\n");
    exit(1);
}
if (!ftp_login($ftp, $ftpUser, $ftpPass)) {
    fwrite(STDERR, "FTP login failed for user $ftpUser.\n");
    exit(1);
}
ftp_pasv($ftp, true);
echo "Connected. Target: $remoteBase\n\n";

// ── Helpers ───────────────────────────────────────────────────────────────────

function ftp_mkdirs(mixed $ftp, string $remotePath): void {
    $parts = explode('/', trim($remotePath, '/'));
    $path  = '';
    foreach ($parts as $part) {
        $path .= '/' . $part;
        @ftp_mkdir($ftp, $path);
    }
}

function upload_tree(
    mixed  $ftp,
    string $localDir,
    string $remoteDir,
    array  $excludeDirs,
    array  $excludeNames,
    array  $excludeExts,
    int    &$uploaded,
    int    &$failed
): void {
    ftp_mkdirs($ftp, $remoteDir);

    foreach (scandir($localDir) as $item) {
        if ($item === '.' || $item === '..') continue;

        $localPath  = $localDir  . '/' . $item;
        $remotePath = $remoteDir . '/' . $item;

        if (is_dir($localPath)) {
            if (in_array($item, $excludeDirs, true)) continue;
            upload_tree($ftp, $localPath, $remotePath, $excludeDirs, $excludeNames, $excludeExts, $uploaded, $failed);
        } else {
            if (in_array($item, $excludeNames, true)) continue;
            if (in_array(pathinfo($item, PATHINFO_EXTENSION), $excludeExts, true)) continue;

            if (ftp_put($ftp, $remotePath, $localPath, FTP_BINARY)) {
                echo "  ok  $remotePath\n";
                $uploaded++;
            } else {
                fwrite(STDERR, "  FAIL $remotePath\n");
                $failed++;
            }
        }
    }
}

// ── Upload files ──────────────────────────────────────────────────────────────

$uploaded = 0;
$failed   = 0;

upload_tree($ftp, $root, $remoteBase, $excludeDirs, $excludeNames, $excludeExts, $uploaded, $failed);

// ── Upload environment sentinel ───────────────────────────────────────────────

$tmp = tempnam(sys_get_temp_dir(), 'wl_');
file_put_contents($tmp, '');
$sentinel = $remoteBase . '/app.' . $env;
if (ftp_put($ftp, $sentinel, $tmp, FTP_BINARY)) {
    echo "  ok  $sentinel (sentinel)\n";
    $uploaded++;
}

// ── Upload temporary migration runner ─────────────────────────────────────────
// Self-contained: reads config/db.json and migrations/ from the server,
// runs pending migrations, self-deletes.

$token = bin2hex(random_bytes(16));

$runner = <<<'RUNNER'
<?php
if (($_GET['t'] ?? '') !== '__TOKEN__') { http_response_code(403); exit; }
$r   = dirname(__DIR__);
$cfg = json_decode(file_get_contents($r . '/config/db.json'), true);
$env = file_exists($r . '/app.world4you') ? 'world4you' : 'prod';
$db  = $cfg[$env];
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$con = mysqli_connect($db['host'], $db['user'], $db['pass'], $db['name']);
mysqli_set_charset($con, 'utf8');
$con->query("CREATE TABLE IF NOT EXISTS db_migrations (
    name VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$applied = [];
$res = $con->query('SELECT name FROM db_migrations');
while ($row = $res->fetch_assoc()) $applied[$row['name']] = true;
$files = glob($r . '/migrations/*.sql'); sort($files); $ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) { echo "skip $name\n"; continue; }
    $sql = file_get_contents($file);
    $con->multi_query($sql);
    do { if ($result = $con->store_result()) $result->free(); }
    while ($con->more_results() && $con->next_result());
    $stmt = $con->prepare('INSERT INTO db_migrations (name) VALUES (?)');
    $stmt->bind_param('s', $name); $stmt->execute(); $stmt->close();
    echo "apply $name\n"; $ran++;
}
echo $ran === 0 ? "Nothing to migrate.\n" : "$ran migration(s) applied.\n";
unlink(__FILE__);
RUNNER;

$runner = str_replace('__TOKEN__', $token, $runner);
file_put_contents($tmp, $runner);

$runnerRemote = $remoteBase . '/web/_m.php';
if (ftp_put($ftp, $runnerRemote, $tmp, FTP_BINARY)) {
    echo "  ok  $runnerRemote (migration runner)\n";
}
unlink($tmp);

ftp_close($ftp);

// ── Trigger migration runner via HTTP ─────────────────────────────────────────

echo "\nRunning migrations on server ...\n";
$runnerUrl = $baseUrl . '/_m.php?t=' . $token;
$response  = @file_get_contents($runnerUrl);
if ($response !== false) {
    foreach (explode("\n", trim($response)) as $line) {
        echo "  $line\n";
    }
} else {
    fwrite(STDERR, "  Warning: could not reach migration runner at $runnerUrl\n");
    fwrite(STDERR, "  Run migrations manually if needed.\n");
}

echo "\nDone. $uploaded uploaded" . ($failed ? ", $failed FAILED" : '') . ".\n";
if ($failed > 10) exit(1); // only hard-fail if many errors, not occasional FTP hiccups
