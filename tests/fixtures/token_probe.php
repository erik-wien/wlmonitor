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
 *   mock_wl_response ?string  Raw JSON body to serve for any https:// fetch —
 *                              lets the probe exercise monitor_get() without a
 *                              real network call, matching
 *                              tests/Unit/MonitorParserTest.php's MockHttpWrapper.
 *
 * STDOUT carries whatever the page echoes (the JSON body). The HTTP status
 * code is reported on STDERR as "STATUS:<code>\n" via a shutdown function.
 */

$page         = $argv[1] ?? '';
$scenarioFile = $argv[2] ?? '';
$scenario     = json_decode((string) file_get_contents($scenarioFile), true) ?? [];

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
    fwrite(STDERR, 'STATUS:' . http_response_code() . "\n");
});

require __DIR__ . '/../../web/' . $page;
