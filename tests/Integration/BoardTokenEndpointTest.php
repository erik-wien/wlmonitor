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
    /** @var list<string> */
    private array $createdTokens = [];

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
        foreach ($this->createdTokens as $token) {
            $hash = board_state_hash($token);
            @unlink(board_state_meta_path($hash));
            @unlink(board_state_frame_path($hash));
        }
        $this->con->close();
    }

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

    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $h) {
            if (str_starts_with($h, $name . ':')) {
                return trim(substr($h, strlen($name) + 1));
            }
        }
        return null;
    }

    private function mockMonitorResponse(string $diva, int $countdown): string
    {
        return json_encode([
            'message' => ['serverTime' => '2026-08-16T19:00:00+02:00'],
            'data' => ['monitors' => [[
                'locationStop' => ['properties' => [
                    'title' => 'Halt', 'name' => 'STK' . $diva, 'diva' => ['statId' => $diva],
                ]],
                'lines' => [[
                    'name' => 'L1', 'towards' => 'Z', 'type' => 'ptTram', 'platform' => '1',
                    'departures' => ['departure' => [['departureTime' => ['countdown' => $countdown]]]],
                ]],
            ]]],
        ]);
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

        $token = auth_api_token_issue($this->con, $userId, 'probe', 'web', null);
        $this->createdTokens[] = $token;
        return $token;
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
        // Die eigentliche Platzhalter-Logik (eine gefilterte, von der WL-API
        // stillschweigend weggelassene Station bleibt als leere Karte
        // bestehen statt zu verschwinden) ist auf inc/board.php- und
        // inc/monitor.php-Ebene unit-getestet (board_favorite(),
        // monitor_inject_missing_stations()). Hier, end-to-end durch den
        // binaeren Endpunkt, ist nur noch pruefbar, dass die Pipeline dabei
        // NICHT fehlschlaegt -- der Koerper selbst ist opakes 1bpp.
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111,90222222', [
            '90222222' => [['line' => '63', 'platform' => '1']],
        ]);

        $mock = json_encode([
            'message' => ['serverTime' => '2026-08-16T10:00:00+02:00'],
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
        $this->assertSame('full', $this->headerValue($r['headers'], 'X-Board-Mode'));
        $this->assertGreaterThan(0, strlen($r['out']), 'Body darf nicht leer sein');
    }

    public function test_board_returns_200_not_503_when_wl_api_has_nothing_for_any_requested_diva(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90333333', null);

        // WL API returns an empty monitors array — monitor_get() throws
        // RuntimeException('No monitors found…') for this.
        $mock = json_encode([
            'message' => ['serverTime' => '2026-08-16T10:00:00+02:00'],
            'data'    => ['monitors' => []],
        ]);

        $r = $this->runProbe('board.php', [
            'authorization'    => 'Bearer ' . $token,
            'mock_wl_response' => $mock,
        ]);

        $this->assertSame(200, $r['status'], 'no departures anywhere is not an outage');
        $this->assertSame('full', $this->headerValue($r['headers'], 'X-Board-Mode'));
    }

    // --- Board-Protokoll: Vollbild/Patch/Touch/Debug (Spec §4, §5, §6) -------

    public function test_first_poll_for_a_device_is_always_full_mode(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);
        $mock = $this->mockMonitorResponse('90111111', 4);

        $r = $this->runProbe('board.php', ['authorization' => 'Bearer ' . $token, 'mock_wl_response' => $mock]);

        $this->assertSame(200, $r['status']);
        $this->assertSame('full', $this->headerValue($r['headers'], 'X-Board-Mode'));
        $this->assertSame('1872', $this->headerValue($r['headers'], 'X-Board-W'));
        $this->assertSame('1404', $this->headerValue($r['headers'], 'X-Board-H'));
        $this->assertSame((string) strlen($r['out']), $this->headerValue($r['headers'], 'Content-Length'));
        $this->assertNotNull($this->headerValue($r['headers'], 'X-Board-ETag'));
        $this->assertSame('1', $this->headerValue($r['headers'], 'X-Board-Favorite-Count'));
        // 1 Abfahrtenseite + der Schlafschirm-Slot, der seit 2026-08-23 immer
        // die letzte Seite ist (Nutzerwunsch: "damit ich den Schirm auch
        // absichtlich aufrufen kann").
        $this->assertSame('2', $this->headerValue($r['headers'], 'X-Board-Total-Pages'));
        $this->assertNull($this->headerValue($r['headers'], 'X-Board-Is-Sleep-Page'), 'Seite 1 von 2 ist Abfahrten, nicht der Schlafschirm');
    }

    public function test_favorite_count_header_reflects_two_configured_favorites(): void
    {
        // Die Touch-Leiste teilt sich dynamisch durch die tatsaechliche
        // Favoritenzahl (board_render_touch_bar_svg(), 1-3 Buttons) -- ohne
        // diesen Header muesste die Firmware raten, wie breit jede
        // Touch-Zone ist. Zwei statt drei Favoriten beweist, dass der
        // Header die ECHTE Zahl traegt, nicht nur einen festen Wert.
        $token = $this->createTokenUser();
        $this->createFavorite('A', '90111111', null);
        $this->createFavorite('B', '90222222', null);

        $r = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertSame('2', $this->headerValue($r['headers'], 'X-Board-Favorite-Count'));
    }

    public function test_total_pages_header_reflects_pagination_overflow(): void
    {
        // Analog zu X-Board-Favorite-Count: die Pagination-Pille
        // (board_render_stand_and_pagination_svg()) ist nur sichtbar und
        // ihre Breite nur bekannt, wenn die Firmware totalPages kennt.
        // 15 Linien an einer Haltestelle erzwingen zuverlaessig einen
        // Seitenumbruch (gleiche Fixture-Groesse wie
        // BoardTemplateLayoutTest, dort verifiziert dass 10 Linien NICHT,
        // aber 15 Linien SEHR WOHL ueberlaufen).
        $token = $this->createTokenUser();
        $this->createFavorite('Viele Linien', '90111111', null);

        $lines = [];
        foreach (range(1, 15) as $i) {
            $lines[] = [
                'name' => 'L' . $i, 'towards' => 'Z', 'type' => 'ptTram', 'platform' => (string) $i,
                'departures' => ['departure' => [['departureTime' => ['countdown' => 4]]]],
            ];
        }
        $mock = json_encode([
            'message' => ['serverTime' => '2026-08-17T19:00:00+02:00'],
            'data' => ['monitors' => [[
                'locationStop' => ['properties' => [
                    'title' => 'Halt', 'name' => 'STK90111111', 'diva' => ['statId' => '90111111'],
                ]],
                'lines' => $lines,
            ]]],
        ]);

        $r = $this->runProbe('board.php', ['authorization' => 'Bearer ' . $token, 'mock_wl_response' => $mock]);

        $this->assertSame(200, $r['status']);
        $totalPages = (int) $this->headerValue($r['headers'], 'X-Board-Total-Pages');
        $this->assertGreaterThan(1, $totalPages, 'X-Board-Total-Pages muss den echten Seitenumbruch widerspiegeln: ' . json_encode($r['headers']));
    }

    public function test_second_poll_with_matching_etag_and_unchanged_favorite_returns_patch_mode(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);

        $r1 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
        ]);
        $etag = $this->headerValue($r1['headers'], 'X-Board-ETag');
        $this->assertNotNull($etag);

        // Andere Abfahrtszeit -> garantiert sichtbar anderer Frame, unabhaengig
        // von der Uhrzeit, zu der der Test laeuft.
        $r2 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 9),
            'headers' => ['If-None-Match' => $etag],
        ]);

        $this->assertSame(200, $r2['status']);
        $this->assertSame('patch', $this->headerValue($r2['headers'], 'X-Board-Mode'));
        $this->assertGreaterThan(0, strlen($r2['out']));
        $w = (int) $this->headerValue($r2['headers'], 'X-Board-W');
        $h = (int) $this->headerValue($r2['headers'], 'X-Board-H');
        $this->assertLessThan(1872 * 1404, $w * $h, 'ein Patch darf nicht die volle Flaeche sein');
    }

    public function test_second_poll_with_mismatching_etag_returns_full_mode(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);

        $r1 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
        ]);
        $this->assertSame('full', $this->headerValue($r1['headers'], 'X-Board-Mode'));

        // Gleicher Favorit, gleiche Seite -- aber ein absichtlich falscher
        // If-None-Match-Wert. Das muss den ETag-Mismatch-Zweig treffen, nicht
        // den Favoriten-/Seitenwechsel-Zweig.
        $r2 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 9),
            'headers' => ['If-None-Match' => '"not-the-real-etag"'],
        ]);

        $this->assertSame(200, $r2['status']);
        $this->assertSame('full', $this->headerValue($r2['headers'], 'X-Board-Mode'));
    }

    public function test_favorite_switch_touch_forces_full_mode_even_with_matching_etag(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('A', '90111111', null);
        $this->createFavorite('B', '90222222', null);

        $r1 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
        ]);
        $etag = $this->headerValue($r1['headers'], 'X-Board-ETag');

        $r2 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90222222', 4),
            'headers' => ['If-None-Match' => $etag, 'X-Device-Touch' => 'fav1'],
        ]);

        $this->assertSame(200, $r2['status']);
        $this->assertSame('full', $this->headerValue($r2['headers'], 'X-Board-Mode'));
    }

    public function test_malformed_upstream_response_returns_503_json_error(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);

        $r = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => 'not valid json',
        ]);

        $this->assertSame(503, $r['status']);
        $this->assertSame('application/json; charset=utf-8', $this->headerValue($r['headers'], 'Content-Type'));
        $this->assertSame(['error' => 'upstream_unavailable'], json_decode($r['out'], true));
    }

    public function test_debug_svg_returns_raw_svg_and_bypasses_state_logic(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);

        $r = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
            'get' => ['debug' => 'svg'],
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertSame('image/svg+xml; charset=utf-8', $this->headerValue($r['headers'], 'Content-Type'));
        $this->assertStringStartsWith('<svg', $r['out']);
        $this->assertNull($this->headerValue($r['headers'], 'X-Board-Mode'), 'Debug-Zweig darf keine Diff-/State-Header setzen');
    }

    public function test_debug_png_returns_raw_png(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);

        $r = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
            'get' => ['debug' => 'png'],
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertSame('image/png', $this->headerValue($r['headers'], 'Content-Type'));
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $r['out']);
    }

    // --- Browser-Simulator (?debug=ui, ?debug=png&sim=1) ----------------------

    public function test_debug_ui_lists_touch_zones_for_the_configured_favorites(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('A', '90111111', null);
        $this->createFavorite('B', '90222222', null);

        $r = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
            'get' => ['debug' => 'ui'],
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertSame('text/html; charset=utf-8', $this->headerValue($r['headers'], 'Content-Type'));
        $this->assertStringContainsString('"zone":"fav0"', $r['out']);
        $this->assertStringContainsString('"zone":"fav1"', $r['out']);
        $this->assertStringNotContainsString('"zone":"fav2"', $r['out'], 'nur 2 Favoriten konfiguriert');
        // Absolut statt relativ (TASK-25): 2 Seiten (Monitor+Wetter, keine
        // Stoerung/Kalender im Mock) -> page_1/page_2, kein page_prev/page_next.
        $this->assertStringContainsString('"zone":"page_1"', $r['out']);
        $this->assertStringContainsString('"zone":"page_2"', $r['out']);
        $this->assertStringNotContainsString('page_prev', $r['out']);
        $this->assertStringNotContainsString('page_next', $r['out']);
        // Live-Befund 2026-08-27 auf akadbrain: die CSP (script-src 'self'
        // 'nonce-...', kein 'unsafe-inline') blockt das eingebettete <script>
        // stillschweigend ohne Nonce -- Seite laedt mit 200, aber keine Zone
        // reagiert. Muss zur Nonce aus der CSP-Antwortheader-Direktive passen.
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+">/', $r['out'], 'Inline-Script braucht eine nicht-leere CSP-Nonce');
        $csp = $this->headerValue($r['headers'], 'Content-Security-Policy');
        preg_match('/<script nonce="([^"]+)">/', $r['out'], $scriptNonce);
        $this->assertStringContainsString("'nonce-{$scriptNonce[1]}'", (string) $csp, 'Script-Nonce muss mit der CSP-Direktive uebereinstimmen');
    }

    public function test_debug_png_without_sim_resolves_touch_but_does_not_persist_it(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('A', '90111111', null);
        $this->createFavorite('B', '90222222', null);

        $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90222222', 4),
            'get' => ['debug' => 'png'],
            'headers' => ['X-Device-Touch' => 'fav1'],
        ]);

        $meta = board_state_load_meta(board_state_meta_path(board_state_hash($token)));
        $this->assertSame(0, $meta['activeFavoriteIndex'], 'einfaches debug=png (ohne sim) darf den Geraetezustand nicht veraendern');
    }

    public function test_debug_png_with_sim_persists_the_resolved_touch(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('A', '90111111', null);
        $this->createFavorite('B', '90222222', null);

        $r = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90222222', 4),
            'get' => ['debug' => 'png', 'sim' => '1'],
            'headers' => ['X-Device-Touch' => 'fav1'],
        ]);

        $this->assertSame(200, $r['status']);
        $meta = board_state_load_meta(board_state_meta_path(board_state_hash($token)));
        $this->assertSame(1, $meta['activeFavoriteIndex'], '&sim=1 muss die im Simulator angeklickte Navigation persistieren');
    }

    public function test_debug_png_with_sim_returns_fresh_touch_zones_reflecting_a_disruption_page(): void
    {
        // Nutzerbefund 2026-08-27 (live auf akadbrain): eine Stoerung fuegt
        // eine zusaetzliche Seite hinzu, die Pille wird breiter -- die im
        // Simulator einmalig geladenen Zonen passten danach nicht mehr zur
        // tatsaechlich gerenderten Pille. Der &sim=1-Response muss die
        // GERADE aufgeloesten Zonen mitliefern, nicht die vom Seitenaufbau.
        $token = $this->createTokenUser();
        $this->createFavorite('A', '90111111', null);

        $mockWithAlert = json_encode([
            'message' => ['serverTime' => '2026-08-16T19:00:00+02:00'],
            'data' => [
                'monitors' => [[
                    'locationStop' => ['properties' => [
                        'title' => 'Halt', 'name' => 'STK90111111', 'diva' => ['statId' => '90111111'],
                    ]],
                    'lines' => [[
                        'name' => 'L1', 'towards' => 'Z', 'type' => 'ptTram', 'platform' => '1',
                        'departures' => ['departure' => [['departureTime' => ['countdown' => 4]]]],
                    ]],
                ]],
                'trafficInfos' => [[
                    'name' => 'stoerung1', 'title' => 'Stoerung', 'description' => '…',
                    'priority' => 'high', 'relatedLines' => ['L1'],
                ]],
            ],
        ]);

        $r = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $mockWithAlert,
            'get' => ['debug' => 'png', 'sim' => '1'],
        ]);

        $this->assertSame(200, $r['status']);
        $zonesHeader = $this->headerValue($r['headers'], 'X-Board-Touch-Zones');
        $this->assertNotNull($zonesHeader, '&sim=1 muss X-Board-Touch-Zones setzen');
        // 1 Favorit, totalPages = 1 Abfahrtenseite + Stoerung + Schlafschirm = 3.
        $this->assertSame(board_touch_zones(1, 3), json_decode($zonesHeader, true));
    }

    public function test_debug_png_without_sim_does_not_set_touch_zones_header(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('A', '90111111', null);

        $r = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
            'get' => ['debug' => 'png'],
        ]);

        $this->assertNull($this->headerValue($r['headers'], 'X-Board-Touch-Zones'), 'einfaches debug=png ist read-only, kein Simulator-Header noetig');
    }

    public function test_user_with_no_favorites_still_gets_a_valid_full_frame(): void
    {
        // Kein mock_wl_response -- board.php darf monitor_get() bei null
        // Favoriten gar nicht erst aufrufen, sonst wuerde dieser Test an
        // einem echten (fehlenden) Netzwerkzugriff haengen bleiben.
        $token = $this->createTokenUser();

        $r = $this->runProbe('board.php', ['authorization' => 'Bearer ' . $token]);

        $this->assertSame(200, $r['status']);
        $this->assertSame('full', $this->headerValue($r['headers'], 'X-Board-Mode'));
        $this->assertGreaterThan(0, strlen($r['out']));
        $this->assertSame('0', $this->headerValue($r['headers'], 'X-Board-Favorite-Count'));
    }

    public function test_device_transitioning_from_no_favorites_to_a_favorite_between_polls(): void
    {
        // Kein mock_wl_response beim ersten Poll -- analog zu
        // test_user_with_no_favorites_still_gets_a_valid_full_frame(), keine
        // Favoriten heisst kein monitor_get()-Aufruf.
        $token = $this->createTokenUser();

        $r1 = $this->runProbe('board.php', ['authorization' => 'Bearer ' . $token]);
        $this->assertSame(200, $r1['status']);
        $this->assertSame('full', $this->headerValue($r1['headers'], 'X-Board-Mode'));
        $this->assertGreaterThan(0, strlen($r1['out']));

        // Geraet bekommt zwischen den Polls einen Favoriten -- der zweite
        // Poll braucht jetzt echt einen mock_wl_response, weil es etwas zum
        // Abfragen gibt.
        $this->createFavorite('Test', '90111111', null);

        $r2 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $this->mockMonitorResponse('90111111', 4),
        ]);
        $this->assertSame(200, $r2['status']);
        $this->assertNotNull($this->headerValue($r2['headers'], 'X-Board-Mode'));
    }

    // --- Schlafschirm als letzte Seite (Nutzerwunsch 2026-08-23) --------------

    public function test_page_next_reaches_the_sleep_screen_as_the_last_page(): void
    {
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);
        $mock = $this->mockMonitorResponse('90111111', 4);

        $r1 = $this->runProbe('board.php', ['authorization' => 'Bearer ' . $token, 'mock_wl_response' => $mock]);
        $this->assertSame('2', $this->headerValue($r1['headers'], 'X-Board-Total-Pages'), '1 Abfahrtenseite + Schlafschirm-Slot');
        $this->assertNull($this->headerValue($r1['headers'], 'X-Board-Is-Sleep-Page'));

        $r2 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $mock,
            'headers' => ['X-Device-Touch' => 'page_next'],
        ]);

        $this->assertSame('1', $this->headerValue($r2['headers'], 'X-Board-Is-Sleep-Page'), 'Seite 2 von 2 ist der Schlafschirm-Slot');
    }

    public function test_page_n_jumps_directly_to_the_sleep_screen_in_a_single_touch(): void
    {
        // TASK-25: die Touch-Pille springt absolut, nicht mehr schrittweise --
        // EIN Touch auf page_3 muss direkt die Seite 3 (hier: der Schlafschirm-
        // Slot bei Monitor+Stoerung+Schlaf) liefern, ohne zweimal page_next
        // zu brauchen. page_next selbst bleibt fuer die physischen Tasten
        // unveraendert funktionsfaehig (s. test_page_next_reaches_the_sleep_screen...).
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);
        $mockWithAlert = json_encode([
            'message' => ['serverTime' => '2026-08-16T19:00:00+02:00'],
            'data' => [
                'monitors' => [[
                    'locationStop' => ['properties' => [
                        'title' => 'Halt', 'name' => 'STK90111111', 'diva' => ['statId' => '90111111'],
                    ]],
                    'lines' => [[
                        'name' => 'L1', 'towards' => 'Z', 'type' => 'ptTram', 'platform' => '1',
                        'departures' => ['departure' => [['departureTime' => ['countdown' => 4]]]],
                    ]],
                ]],
                'trafficInfos' => [[
                    'name' => 'stoerung1', 'title' => 'Stoerung', 'description' => '…',
                    'priority' => 'high', 'relatedLines' => ['L1'],
                ]],
            ],
        ]);

        $r1 = $this->runProbe('board.php', ['authorization' => 'Bearer ' . $token, 'mock_wl_response' => $mockWithAlert]);
        $this->assertSame('3', $this->headerValue($r1['headers'], 'X-Board-Total-Pages'), '1 Abfahrtenseite + Stoerung + Schlafschirm-Slot');

        $r2 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $mockWithAlert,
            'headers' => ['X-Device-Touch' => 'page_3'],
        ]);

        $this->assertSame('1', $this->headerValue($r2['headers'], 'X-Board-Is-Sleep-Page'), 'ein einzelner page_3-Touch muss direkt Seite 3 (Schlafschirm) treffen');
    }

    public function test_forced_sleep_screen_does_not_persist_as_the_stored_page(): void
    {
        // Der letzte Abruf vor dem Tiefschlaf verlangt den Schlafschirm per
        // X-Device-Screen: sleep, UNABHAENGIG vom gespeicherten Blaetter-
        // Zustand. Wuerde das als activePage persistiert, staende beim
        // naechsten Aufwachen wieder der Schlafschirm da, obwohl der Nutzer
        // zuletzt auf Seite 1 (Abfahrten) war -- board.php muss den
        // unforcierten Wert speichern, s. Kommentar bei $forceSleepScreen.
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);
        $mock = $this->mockMonitorResponse('90111111', 4);

        $r1 = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $mock,
            'headers' => ['X-Device-Screen' => 'sleep'],
        ]);
        $this->assertSame('1', $this->headerValue($r1['headers'], 'X-Board-Is-Sleep-Page'));

        // Naechster regulaerer Poll (kein Screen-Header, kein Touch) MUSS
        // wieder Seite 1 (Abfahrten) zeigen, nicht den Schlafschirm.
        $r2 = $this->runProbe('board.php', ['authorization' => 'Bearer ' . $token, 'mock_wl_response' => $mock]);
        $this->assertNull($this->headerValue($r2['headers'], 'X-Board-Is-Sleep-Page'));
    }

    public function test_forced_sleep_screen_hides_the_pagination_pill_but_manual_paging_shows_it(): void
    {
        // Nutzerbefund 2026-08-23: "die paginierung ist jetzt aber leider
        // auch zu sehen, wenn das panel schlaeft." X-Device-Screen: sleep
        // ist per Definition der letzte Abruf vor esp_deep_sleep_start() --
        // die Pille muss dort fehlen. Bewusst gewordenes Hinblaettern
        // (page_next) laesst das Geraet wach, dort bleibt sie sichtbar.
        $token = $this->createTokenUser();
        $this->createFavorite('Test', '90111111', null);
        $mock = $this->mockMonitorResponse('90111111', 4);

        $forced = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $mock,
            'headers' => ['X-Device-Screen' => 'sleep'],
            'get' => ['debug' => 'svg'],
        ]);
        $this->assertStringNotContainsString('height="74" rx="37"', $forced['out'], 'erzwungener Vorschlaf-Abruf darf keine Pille zeigen');
        $this->assertStringContainsString('Stand ', $forced['out'], '"Stand HH:MM" bleibt trotzdem stehen');

        $paged = $this->runProbe('board.php', [
            'authorization' => 'Bearer ' . $token,
            'mock_wl_response' => $mock,
            'headers' => ['X-Device-Touch' => 'page_next'],
            'get' => ['debug' => 'svg'],
        ]);
        $this->assertStringContainsString('height="74" rx="37"', $paged['out'], 'bewusstes Hinblaettern zeigt die Pille');
    }
}
