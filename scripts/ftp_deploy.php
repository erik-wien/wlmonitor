<?php
/**
 * scripts/ftp_deploy.php
 *
 * Uploads the application to a remote FTP server.
 * Reads FTP credentials from config/db.json for the given environment.
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

// ── Exclude rules (mirrors deploy.sh rsync excludes) ─────────────────────────

$excludeDirs = [
    '.git', '.claude', 'tests', 'deprecated', 'docs',
    'img', 'config', 'data', 'backups', '.worktrees', 'worktrees',
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

// ── Upload ────────────────────────────────────────────────────────────────────

$uploaded = 0;
$failed   = 0;

upload_tree($ftp, $root, $remoteBase, $excludeDirs, $excludeNames, $excludeExts, $uploaded, $failed);

// Drop the environment sentinel file
$tmp = tempnam(sys_get_temp_dir(), 'wl_sentinel_');
file_put_contents($tmp, '');
$sentinel = $remoteBase . '/app.' . $env;
if (ftp_put($ftp, $sentinel, $tmp, FTP_BINARY)) {
    echo "  ok  $sentinel (sentinel)\n";
    $uploaded++;
}
unlink($tmp);

ftp_close($ftp);

echo "\nDone. $uploaded uploaded" . ($failed ? ", $failed FAILED" : '') . ".\n";
if ($failed) exit(1);
