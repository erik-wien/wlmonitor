<?php
/**
 * scripts/test_mail.php
 * Quick SMTP connectivity and send test.
 * Run: php scripts/test_mail.php recipient@example.com
 */
if (php_sapi_name() !== 'cli') exit("CLI only.\n");

$to = $argv[1] ?? null;
if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "Usage: php scripts/test_mail.php recipient@example.com\n";
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/yaml.php';

$cfg  = wl_yaml_load(__DIR__ . '/../config.yaml');
$smtp = $cfg['smtp'];

define('SMTP_HOST',      $smtp['host']);
define('SMTP_PORT',      (int) $smtp['port']);
define('SMTP_USER',      $smtp['user']);
define('SMTP_PASS',      $smtp['password']);
define('SMTP_FROM',      $smtp['from']);
define('SMTP_FROM_NAME', $smtp['from_name']);

echo "SMTP config:\n";
echo "  Host: " . SMTP_HOST . "\n";
echo "  Port: " . SMTP_PORT . "\n";
echo "  User: " . SMTP_USER . "\n";
echo "  From: " . SMTP_FROM . " (" . SMTP_FROM_NAME . ")\n";
echo "  To:   $to\n\n";
echo "Sending test mail...\n";

try {
    send_mail(
        $to,
        $to,
        'WL Monitor – SMTP Test',
        '<p>SMTP test from WL Monitor. If you receive this, sending works.</p>',
        'SMTP test from WL Monitor. If you receive this, sending works.'
    );
    echo "OK – mail accepted by SMTP server.\n";
} catch (\Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
