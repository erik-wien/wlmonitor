<?php
// tests/Integration/AuthTest.php

namespace WLMonitor\Tests\Integration;

class AuthTest extends IntegrationTestCase
{
    private string $password = 'TestPass123!';
    private string $testIp;

    protected function setUp(): void
    {
        parent::setUp();
        // Use a unique IP per test so rate-limit state doesn't bleed between tests
        $this->testIp = '10.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 254);
        $_SERVER['REMOTE_ADDR'] = $this->testIp;

        // Clear this IP from the rate limit file before each test
        auth_clear_failures($this->testIp);
    }

    protected function tearDown(): void
    {
        auth_clear_failures($this->testIp);
        parent::tearDown();
    }

    // --- Successful login ----------------------------------------------------

    public function test_login_succeeds_with_correct_credentials(): void
    {
        $uid = $this->createUser(['password' => password_hash($this->password, PASSWORD_BCRYPT, ['cost' => 4])]);
        $stmt = $this->con->prepare('SELECT username FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $username = $stmt->get_result()->fetch_assoc()['username'];
        $stmt->close();

        $result = auth_login($this->con, $username, $this->password);

        $this->assertTrue($result['ok']);
        $this->assertSame($username, $result['username']);
    }

    public function test_login_sets_session_variables(): void
    {
        $uid = $this->createUser(['password' => password_hash($this->password, PASSWORD_BCRYPT, ['cost' => 4])]);
        $stmt = $this->con->prepare('SELECT username FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $username = $stmt->get_result()->fetch_assoc()['username'];
        $stmt->close();

        auth_login($this->con, $username, $this->password);

        $this->assertTrue($_SESSION['loggedin']);
        $this->assertSame($uid, $_SESSION['id']);
        $this->assertSame($username, $_SESSION['username']);
    }

    // --- Credential failures -------------------------------------------------

    public function test_login_fails_with_wrong_password(): void
    {
        $uid = $this->createUser();
        $stmt = $this->con->prepare('SELECT username FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $username = $stmt->get_result()->fetch_assoc()['username'];
        $stmt->close();

        $result = auth_login($this->con, $username, 'wrong_password');

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_login_fails_with_nonexistent_username(): void
    {
        $result = auth_login($this->con, 'nobody_' . uniqid(), $this->password);

        $this->assertFalse($result['ok']);
    }

    public function test_login_fails_for_disabled_account(): void
    {
        $uid = $this->createUser([
            'disabled'  => '1',
            'password'  => password_hash($this->password, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $stmt = $this->con->prepare('SELECT username FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $username = $stmt->get_result()->fetch_assoc()['username'];
        $stmt->close();

        $result = auth_login($this->con, $username, $this->password);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsStringIgnoringCase('gesperrt', $result['error']);
    }

    public function test_login_fails_for_unactivated_account(): void
    {
        $uid = $this->createUser([
            'activation_code' => 'someuniqcode',
            'password'        => password_hash($this->password, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $stmt = $this->con->prepare('SELECT username FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $username = $stmt->get_result()->fetch_assoc()['username'];
        $stmt->close();

        $result = auth_login($this->con, $username, $this->password);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsStringIgnoringCase('aktiviert', $result['error']);
    }

    // --- Failed-login counter ------------------------------------------------

    public function test_failed_login_increments_invalid_logins(): void
    {
        $uid = $this->createUser();
        $stmt = $this->con->prepare('SELECT username FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $username = $stmt->get_result()->fetch_assoc()['username'];
        $stmt->close();

        auth_login($this->con, $username, 'wrong');

        $stmt = $this->con->prepare('SELECT invalidLogins FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_assoc()['invalidLogins'];
        $stmt->close();

        $this->assertSame(1, $count);
    }

    // --- Rate limiting -------------------------------------------------------

    public function test_rate_limit_blocks_after_max_failures(): void
    {
        // Record RATE_LIMIT_MAX failures for this IP
        for ($i = 0; $i < RATE_LIMIT_MAX; $i++) {
            auth_record_failure($this->testIp);
        }

        $result = auth_login($this->con, 'doesnotmatter', 'doesnotmatter');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsStringIgnoringCase('Fehlversuche', $result['error']);
    }

    public function test_rate_limit_is_not_triggered_below_max(): void
    {
        // One fewer than the limit — should not be rate-limited yet
        for ($i = 0; $i < RATE_LIMIT_MAX - 1; $i++) {
            auth_record_failure($this->testIp);
        }

        $this->assertFalse(auth_is_rate_limited($this->testIp));
    }

    public function test_successful_login_clears_rate_limit(): void
    {
        $uid = $this->createUser(['password' => password_hash($this->password, PASSWORD_BCRYPT, ['cost' => 4])]);
        $stmt = $this->con->prepare('SELECT username FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $username = $stmt->get_result()->fetch_assoc()['username'];
        $stmt->close();

        // Record some failures, then log in successfully
        auth_record_failure($this->testIp);
        auth_record_failure($this->testIp);
        auth_login($this->con, $username, $this->password);

        $this->assertFalse(auth_is_rate_limited($this->testIp));
    }
}
