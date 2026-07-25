<?php
// tests/Integration/PageProbeTest.php

namespace WLMonitor\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for web/profil.php and web/aktivitaet.php actually
 * rendering. Context: during a cross-app API-token refactor, none of the
 * six sibling apps' test suites rendered these two pages, so a missing
 * GRANT on auth_api_tokens fataled profil.php in production undetected.
 * This closes that gap for wlmonitor.
 *
 * Runs the real pages out-of-process (they call exit() via auth_require())
 * via tests/fixtures/page_probe.php — modelled on simplechat's
 * tests/fixtures/page_probe.php + tests/run.php TASK-9 section.
 *
 * Does NOT extend IntegrationTestCase: that base wraps $this->con in an
 * uncommitted transaction rolled back in tearDown, which the page_probe.php
 * subprocess (its own, separate mysqli connection) can never see — a
 * committed row is required for anything the probed page must find in the
 * DB. The one test here that needs a real, visible probe user
 * (test_profil_does_not_leak_plaintext_token_with_real_token_row) commits
 * its own row via the app's normal DB credentials and cleans it up via a
 * local-root connection: the app DB user has SELECT/INSERT/UPDATE but not
 * DELETE on auth_accounts (verified via SHOW GRANTS), so a committed test
 * account cannot be removed through the app's own credentials. This class
 * of grant gap is exactly the incident category this test file exists to
 * catch — see class docblock above. test-integration is local-only already
 * (see Makefile), so relying on local root for this one cleanup step is
 * consistent with the suite's existing environment assumptions.
 */
final class PageProbeTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
    }

    /**
     * Runs tests/fixtures/page_probe.php in a fresh PHP process against a
     * real web/*.php page.
     *
     * @return array{status: ?int, out: string}
     */
    private function runPageProbe(string $page, array $scenario): array
    {
        $scenarioFile = tempnam(sys_get_temp_dir(), 'wlm_page_');
        file_put_contents($scenarioFile, json_encode($scenario));
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/page_probe.php')
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

    /** Every <button ...> opening tag in $html — for the "no inline style" sweep. */
    private function buttonTags(string $html): array
    {
        preg_match_all('/<button\b[^>]*>/i', $html, $m);
        return $m[0];
    }

    private function assertNoButtonHasInlineStyle(string $html): void
    {
        foreach ($this->buttonTags($html) as $tag) {
            $this->assertStringNotContainsString('style="', $tag, "button tag leaks inline style: $tag");
        }
    }

    // --- profil.php ----------------------------------------------------------

    public function test_profil_without_session_redirects_no_fatal(): void
    {
        $r = $this->runPageProbe('profil.php', ['loggedin' => false]);
        $this->assertSame(302, $r['status'], 'expected a redirect, not a fatal');
        $this->assertSame('', $r['out'], 'no HTML leaked before the redirect');
    }

    public function test_profil_with_session_renders_expected_markup(): void
    {
        $r = $this->runPageProbe('profil.php', [
            'loggedin' => true, 'id' => 999999, 'username' => 'probe-user',
        ]);

        $this->assertSame(200, $r['status']);

        $this->assertSame(1, substr_count($r['out'], '<h1>'), 'exactly one <h1> on the page');
        $this->assertStringContainsString('<h1>Profil</h1>', $r['out']);

        $this->assertStringContainsString('id="profileAvatarFile"', $r['out']);
        $this->assertStringContainsString('Profilbild ändern', $r['out']);

        $this->assertMatchesRegularExpression(
            '#<dt>Benutzername</dt><dd>probe-user</dd>#',
            $r['out'],
            'username must be shown without an edit control right after it'
        );

        $this->assertStringContainsString(
            'id="profileEmailEditToggle"',
            $r['out'],
            'e-mail must have a pencil/toggle edit affordance'
        );

        $this->assertStringContainsString('Kennwort ändern', $r['out']);

        $this->assertStringContainsString('id="apiTokensToggle"', $r['out']);
        $this->assertMatchesRegularExpression('/Tokens verwalten \(\d+\)/', $r['out']);
        // Dialog is present in the markup but starts hidden — opened only via
        // data-modal-open/openModal(), never rendered visible on initial GET.
        $this->assertMatchesRegularExpression(
            '/<div class="app-modal-backdrop" id="apiTokensModal"[^>]*\bhidden\b/',
            $r['out'],
            'token dialog must be present but initially hidden'
        );

        $this->assertNoButtonHasInlineStyle($r['out']);

        // Fallback (no real token row here, id 999999 has none) — no
        // secret-shaped 64-hex-char string anywhere on the page. The
        // real-token-row version of this check lives in
        // test_profil_does_not_leak_plaintext_token_with_real_token_row().
        $this->assertDoesNotMatchRegularExpression('/\b[0-9a-f]{64}\b/i', $r['out']);
    }

    // --- aktivitaet.php --------------------------------------------------------

    public function test_aktivitaet_without_session_redirects_no_fatal(): void
    {
        $r = $this->runPageProbe('aktivitaet.php', ['loggedin' => false]);
        $this->assertSame(302, $r['status'], 'expected a redirect, not a fatal');
        $this->assertSame('', $r['out'], 'no HTML leaked before the redirect');
    }

    public function test_aktivitaet_with_session_renders_expected_markup(): void
    {
        $r = $this->runPageProbe('aktivitaet.php', [
            'loggedin' => true, 'id' => 999999, 'username' => 'probe-user',
        ]);

        $this->assertSame(200, $r['status']);

        $this->assertSame(1, substr_count($r['out'], '<h1>'), 'exactly one <h1> on the page');
        $this->assertStringContainsString('<h1>Log</h1>', $r['out']);

        $this->assertNoButtonHasInlineStyle($r['out']);
    }

    // --- token-leak regression, with a real committed token row --------------

    public function test_profil_does_not_leak_plaintext_token_with_real_token_row(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $con = new \mysqli(DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_NAME);
        $con->set_charset('utf8');

        $username = 'probe_pagetest_' . uniqid();
        $email    = 'probe_pagetest_' . uniqid() . '@example.invalid';
        $password = password_hash('ProbePass123!', PASSWORD_BCRYPT, ['cost' => 4]);
        $activation = 'activated';
        $disabled = '0';
        $rights = 'User';
        $newMail = '';
        $lastLogin = date('Y-m-d H:i:s');
        $invalidLogins = 0;

        $stmt = $con->prepare(
            'INSERT INTO ' . AUTH_DB_PREFIX . 'auth_accounts
                (username, email, password, activation_code, disabled, rights, newMail, lastLogin, invalidLogins)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param(
            'ssssssssi',
            $username, $email, $password, $activation, $disabled, $rights, $newMail, $lastLogin, $invalidLogins
        );
        $stmt->execute();
        $userId = (int) $con->insert_id;
        $stmt->close();

        $plainToken = auth_api_token_issue($con, $userId, 'probe-token', 'web', null);

        try {
            $r = $this->runPageProbe('profil.php', [
                'loggedin' => true, 'id' => $userId, 'username' => $username,
            ]);

            $this->assertSame(200, $r['status']);
            $this->assertStringContainsString('Tokens verwalten (1)', $r['out']);
            $this->assertStringNotContainsString(
                $plainToken,
                $r['out'],
                'plaintext token leaked in initial GET markup'
            );
            $this->assertDoesNotMatchRegularExpression('/\b[0-9a-f]{64}\b/i', $r['out']);
        } finally {
            // Cleanup via local root: the app's own DB user (DATABASE_USER)
            // has no DELETE grant on auth_accounts (see class docblock) —
            // ON DELETE CASCADE on auth_api_tokens.user_id removes the
            // token row too.
            $root = new \mysqli(DATABASE_HOST, 'root', '', DATABASE_NAME);
            $root->query('DELETE FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE id = ' . $userId);
            $root->close();
            $con->close();
        }
    }
}
