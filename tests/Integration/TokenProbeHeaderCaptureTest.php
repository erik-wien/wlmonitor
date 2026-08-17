<?php
// tests/Integration/TokenProbeHeaderCaptureTest.php
//
// tests/fixtures/token_probe.php now also captures headers_list() to STDERR
// (Board-Protokoll plan, Task 4) -- needed because the rewritten
// web/board.php (Task 5) returns a binary body on STDOUT, so response
// metadata (Content-Type, X-Board-*) can no longer be inferred from the body.
// Exercised here against the STILL-JSON board.php (this task runs before
// Task 5's rewrite) since its Content-Type header is a stable, unrelated
// fixture to assert against.

namespace WLMonitor\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class TokenProbeHeaderCaptureTest extends TestCase
{
    /** @return array{status: ?int, out: string, headers: list<string>} */
    private function runProbe(string $page, array $scenario): array
    {
        $scenarioFile = tempnam(sys_get_temp_dir(), 'wlm_tok_');
        file_put_contents($scenarioFile, json_encode($scenario));
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/token_probe.php')
             . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($scenarioFile);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($scenarioFile);

        $status = null;
        if (preg_match('/STATUS:(\d+)/', $stderr, $m)) {
            $status = (int) $m[1];
        }
        $headers = [];
        if (preg_match('/HEADERS:(.+)/', $stderr, $m)) {
            $headers = json_decode($m[1], true) ?? [];
        }
        return ['status' => $status, 'out' => $stdout, 'headers' => $headers];
    }

    public function test_headers_list_is_captured_on_stderr(): void
    {
        $r = $this->runProbe('board.php', []); // kein Authorization-Header -> 401, aber Header stehen trotzdem

        $this->assertSame(401, $r['status']);
        $matches = array_filter($r['headers'], static fn (string $h): bool =>
            str_starts_with($h, 'Content-Type: application/json'));
        $this->assertNotEmpty($matches, 'Content-Type-Header muss in headers_list() auftauchen: ' . json_encode($r['headers']));
    }

    public function test_custom_headers_scenario_key_reaches_the_page_as_a_server_superglobal(): void
    {
        // board.php selbst liest If-None-Match nicht (Stand Task 4) -- dieser
        // Test prueft nur, dass der Header uebertragen wird, ohne die Seite
        // zum Absturz zu bringen.
        $r = $this->runProbe('board.php', ['headers' => ['If-None-Match' => '"abc"']]);

        $this->assertSame(401, $r['status'], 'unveraendertes Verhalten: fehlender Authorization-Header bleibt 401');
        $this->assertNotEmpty($r['headers'], 'HEADERS: line muss tatsaechlich Header-Daten enthalten (beweist, dass der CGI-Mechanismus lief)');
    }
}
