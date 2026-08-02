<?php
// tests/Integration/BoardTokenEndpointTest.php
//
// Behaviour of the token-authenticated JSON endpoints (web/board.php,
// web/monitor_json.php) that inc/board.php's pure functions cannot see:
// they call exit(), so they are run out-of-process via
// tests/fixtures/token_probe.php (modelled on PageProbeTest.php).
//
// Covers two review findings (2026-08-02):
//   B2 — the WL API silently omits stops with no upcoming departures; a
//        filtered favourite whose only station is omitted must still show
//        up as an (empty) card, and a request where the WL API returns
//        nothing at all for ANY requested DIVA must be a valid 200, not a
//        503 (monitor_get() throwing "No monitors found" is not an outage).
//   B5 — a request with no Authorization header at all (a pre-migration
//        client) must NOT write an auth_log row; a request with a
//        presented-but-invalid token (a real anomaly) must write exactly
//        one.

namespace WLMonitor\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class BoardTokenEndpointTest extends TestCase
{
    private \mysqli $con;
    private ?int $testUserId = null;
    /** @var list<int> */
    private array $testFavoriteIds = [];

    protected function setUp(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->con = new \mysqli(DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_NAME);
        $this->con->set_charset('utf8');
    }

    protected function tearDown(): void
    {
        if ($this->testFavoriteIds !== []) {
            $ph    = implode(',', array_fill(0, count($this->testFavoriteIds), '?'));
            $types = str_repeat('i', count($this->testFavoriteIds));
            $stmt  = $this->con->prepare("DELETE FROM wl_favorites WHERE id IN ($ph)");
            $stmt->bind_param($types, ...$this->testFavoriteIds);
            $stmt->execute();
            $stmt->close();
        }
        if ($this->testUserId !== null) {
            // The app's own DB user has no DELETE on auth_accounts (see
            // vendor/erikr/auth CLAUDE.md) — clean up via local root, same
            // pattern as PageProbeTest::test_profil_does_not_leak_plaintext_token…
            $root = new \mysqli(DATABASE_HOST, 'root', '', DATABASE_NAME);
            $root->query('DELETE FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE id = ' . $this->testUserId);
            $root->close();
        }
        $this->con->close();
    }

    /** @return array{status: ?int, out: string} */
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
        return ['status' => $status, 'out' => $stdout];
    }

    private function authLogCount(string $context, int $sinceId): int
    {
        $stmt = $this->con->prepare(
            'SELECT COUNT(*) c FROM ' . AUTH_DB_PREFIX . 'auth_log WHERE context = ? AND id > ?'
        );
        $stmt->bind_param('si', $context, $sinceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) $row['c'];
    }

    private function maxAuthLogId(): int
    {
        $res = $this->con->query('SELECT COALESCE(MAX(id), 0) m FROM ' . AUTH_DB_PREFIX . 'auth_log');
        return (int) $res->fetch_assoc()['m'];
    }

    private function createTokenUser(): string
    {
        $username = 'probe_board_' . uniqid();
        $email    = $username . '@example.invalid';
        $password = password_hash('ProbePass123!', PASSWORD_BCRYPT, ['cost' => 4]);
        $activation = 'activated';
        $disabled   = '0';
        $rights     = 'User';
        $newMail    = '';
        $lastLogin  = date('Y-m-d H:i:s');
        $invalidLogins = 0;

        $stmt = $this->con->prepare(
            'INSERT INTO ' . AUTH_DB_PREFIX . 'auth_accounts
                (username, email, password, activation_code, disabled, rights, newMail, lastLogin, invalidLogins)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param(
            'ssssssssi',
            $username, $email, $password, $activation, $disabled, $rights, $newMail, $lastLogin, $invalidLogins
        );
        $stmt->execute();
        $userId = (int) $this->con->insert_id;
        $stmt->close();
        $this->testUserId = $userId;

        return auth_api_token_issue($this->con, $userId, 'probe', 'web', null);
    }

    private function createFavorite(string $title, string $diva, ?array $filter): int
    {
        $filterJson = $filter !== null ? json_encode($filter) : null;
        $bclass     = 'btn-primary';
        $stmt = $this->con->prepare(
            'INSERT INTO wl_favorites (idUser, title, sort, diva, bclass, filter_json) VALUES (?, ?, 0, ?, ?, ?)'
        );
        $stmt->bind_param('issss', $this->testUserId, $title, $diva, $bclass, $filterJson);
        $stmt->execute();
        $id = (int) $this->con->insert_id;
        $stmt->close();
        $this->testFavoriteIds[] = $id;
        return $id;
    }

    // --- B5: unauthenticated requests must not spam auth_log -----------------

    public function test_board_missing_token_is_not_logged(): void
    {
        $before = $this->maxAuthLogId();
        $r = $this->runProbe('board.php', []); // no Authorization header at all
        $this->assertSame(401, $r['status']);
        $this->assertSame(
            0,
            $this->authLogCount('board', $before),
            'an absent token is a pre-migration client, not an anomaly — must stay silent'
        );
    }

    public function test_board_invalid_presented_token_is_logged(): void
    {
        $before = $this->maxAuthLogId();
        $r = $this->runProbe('board.php', ['authorization' => 'Bearer not-a-real-token']);
        $this->assertSame(401, $r['status']);
        $this->assertSame(
            1,
            $this->authLogCount('board', $before),
            'a presented-but-invalid token is the reportable anomaly'
        );
    }

    public function test_monitor_json_missing_token_is_not_logged(): void
    {
        $before = $this->maxAuthLogId();
        $r = $this->runProbe('monitor_json.php', []);
        $this->assertSame(401, $r['status']);
        $this->assertSame(0, $this->authLogCount('monitor_json', $before));
    }

    public function test_monitor_json_invalid_presented_token_is_logged(): void
    {
        $before = $this->maxAuthLogId();
        $r = $this->runProbe('monitor_json.php', ['authorization' => 'Bearer not-a-real-token']);
        $this->assertSame(401, $r['status']);
        $this->assertSame(1, $this->authLogCount('monitor_json', $before));
    }

    // --- B2: missing/omitted DIVAs must not silently vanish -------------------

    public function test_board_shows_placeholder_for_diva_the_wl_api_omitted(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111,90222222', [
            '90222222' => [['line' => '63', 'platform' => '1']],
        ]);

        // The WL API returns a monitor for 90111111 only — 90222222 (the
        // filtered station) is silently omitted, as real stops with no
        // upcoming departures are.
        $mock = json_encode([
            'message' => ['serverTime' => '2026-08-02T10:00:00+02:00'],
            'data'    => ['monitors' => [[
                'locationStop' => ['properties' => [
                    'title' => 'Halt 1', 'name' => 'STK90111111', 'diva' => ['statId' => '90111111'],
                ]],
                'lines' => [[
                    'name' => 'L1', 'towards' => 'Z', 'type' => 'ptTram', 'platform' => '1',
                    'departures' => ['departure' => [['departureTime' => ['countdown' => 4]]]],
                ]],
            ]]],
        ]);

        $r = $this->runProbe('board.php', [
            'authorization'    => 'Bearer ' . $token,
            'mock_wl_response' => $mock,
        ]);

        $this->assertSame(200, $r['status']);
        $body = json_decode($r['out'], true);
        $this->assertNotNull($body, 'response must be valid JSON: ' . $r['out']);
        $divas = array_column($body['favorites'][0]['stations'], 'diva');
        $this->assertContains(
            '90222222',
            $divas,
            'the filtered station the WL API omitted must still appear as a card'
        );
    }

    public function test_board_returns_200_not_503_when_wl_api_has_nothing_for_any_requested_diva(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90333333', null);

        // WL API returns an empty monitors array — monitor_get() throws
        // RuntimeException('No monitors found…') for this.
        $mock = json_encode([
            'message' => ['serverTime' => '2026-08-02T10:00:00+02:00'],
            'data'    => ['monitors' => []],
        ]);

        $r = $this->runProbe('board.php', [
            'authorization'    => 'Bearer ' . $token,
            'mock_wl_response' => $mock,
        ]);

        $this->assertSame(200, $r['status'], 'no departures anywhere is not an outage: ' . $r['out']);
        $body = json_decode($r['out'], true);
        $this->assertNotNull($body, 'response must be valid JSON: ' . $r['out']);
        $this->assertCount(1, $body['favorites']);
    }
}
