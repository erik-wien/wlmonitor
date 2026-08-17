<?php
declare(strict_types=1);

/**
 * tests/fixtures/token_probe.php
 *
 * Out-of-process helper to exercise the token-authenticated JSON endpoints
 * (web/board.php, web/monitor_json.php), which call exit() and therefore
 * cannot be required in-process from PHPUnit — modelled on
 * tests/fixtures/page_probe.php, but for Bearer-token auth instead of
 * cookie sessions (these endpoints deliberately never touch $_SESSION).
 *
 * Invoked as: php token_probe.php <page> <scenario.json>
 *
 * Scenario JSON keys:
 *   authorization    ?string  Value for the Authorization header (e.g. "Bearer xyz").
 *                              Omitted/null → no Authorization header at all.
 *   get              ?array   Merged into $_GET (e.g. ['fav' => '3']).
 *   headers          ?array   Extra request headers, name => value (e.g.
 *                              ['X-Device-Touch' => 'fav1', 'If-None-Match' => '"abc"']).
 *                              Merged into $_SERVER as HTTP_<NAME> the same way
 *                              a real web server populates header superglobals.
 *   mock_wl_response ?string  Raw JSON body to serve for any https:// fetch —
 *                              lets the probe exercise monitor_get() without a
 *                              real network call, matching
 *                              tests/Unit/MonitorParserTest.php's MockHttpWrapper.
 *
 * STDOUT carries whatever the page echoes (the response body — binary for
 * board.php since the Board-Protokoll rewrite). The HTTP status code is
 * reported on STDERR as "STATUS:<code>\n", and the full response header list
 * (headers_list()) as "HEADERS:<json array>\n", both via a shutdown function
 * -- STDOUT alone can no longer carry response metadata once it's opaque
 * binary.
 *
 * Prerequisite: the `php-cgi` binary must be on PATH (Homebrew's php formula
 * installs it alongside `php`). headers_list() returns an empty array under
 * PHP's CLI SAPI, so this script re-executes itself via php-cgi to capture
 * real headers; if php-cgi is missing, every probe invocation -- not just
 * header-capture-specific tests -- fails fast with a clear error rather than
 * silently degrading to empty headers.
 */

$page         = $argv[1] ?? '';
$scenarioFile = $argv[2] ?? '';
$scenario     = json_decode((string) file_get_contents($scenarioFile), true) ?? [];

// ── CLI SAPI workaround: headers_list() doesn't work in CLI SAPI ────────────
// If running in CLI, re-execute via php-cgi (which supports headers_list()).
if (php_sapi_name() === 'cli') {
    $phpCgiBinary = trim((string) shell_exec('which php-cgi 2>/dev/null'));
    if (!$phpCgiBinary || !file_exists($phpCgiBinary)) {
        fwrite(STDERR, "ERROR: php-cgi not found on PATH -- required for tests/fixtures/token_probe.php's header-capture mechanism (see file docblock)\n");
        exit(1);
    }

    // Re-execute this script via php-cgi, which properly supports headers_list().
    $cmd = escapeshellarg($phpCgiBinary) . ' ' . escapeshellarg(__FILE__)
         . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($scenarioFile);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // php-cgi outputs HTTP headers (CRLF-terminated), blank line (CRLF), then body.
    // Extract body and forward both body and STDERR output.
    // Handle both CRLF and LF line endings.
    if (preg_match("/\r?\n\r?\n/", $stdout, $matches)) {
        $separator = $matches[0];
        [, $body] = explode($separator, $stdout, 2);
        echo $body;
    } else {
        fwrite(STDERR, "ERROR: php-cgi subprocess output had no header/body separator -- cannot safely split response. php-cgi STDERR follows:\n" . $stderr . "\n");
        exit(1);
    }
    // Forward STDERR from the cgi subprocess (contains STATUS: and HEADERS:).
    if ($stderr) {
        fwrite(STDERR, $stderr);
    }
    exit;
}

if (!empty($scenario['authorization'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = $scenario['authorization'];
}
$_GET = $scenario['get'] ?? [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME']    = '/' . $page;
$_SERVER['PHP_SELF']       = '/' . $page;
// Deliberately NOT *.eriks.cloud / *.jardyx.com / *.test — see page_probe.php.
$_SERVER['HTTP_HOST']      = 'wlmonitor.test.invalid';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

foreach ($scenario['headers'] ?? [] as $name => $value) {
    $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = (string) $value;
}

class TokenProbeHttpMock
{
    /** @var resource|null */
    public $context;
    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->pos = 0;
        return true;
    }

    public function stream_read(int $count): string|false
    {
        $resp  = (string) $GLOBALS['__wl_mock_response'];
        $chunk = substr($resp, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk === '' ? false : $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen((string) $GLOBALS['__wl_mock_response']);
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return false;
    }
}

if (array_key_exists('mock_wl_response', $scenario) && $scenario['mock_wl_response'] !== null) {
    $GLOBALS['__wl_mock_response'] = $scenario['mock_wl_response'];
    stream_wrapper_unregister('https');
    stream_wrapper_register('https', TokenProbeHttpMock::class);
}

// No implicit "200 by default" under the CLI SAPI — see page_probe.php.
http_response_code(200);

register_shutdown_function(static function (): void {
    // Use php://stderr for compatibility with both CLI and CGI SAPI.
    // (STDERR constant is not defined in CGI SAPI)
    $stderr = fopen('php://stderr', 'w');
    if ($stderr) {
        fwrite($stderr, 'STATUS:' . http_response_code() . "\n");
        fwrite($stderr, 'HEADERS:' . json_encode(headers_list(), JSON_UNESCAPED_SLASHES) . "\n");
        fclose($stderr);
    }
});

require __DIR__ . '/../../web/' . $page;
