<?php
// tests/Integration/PasswordResetTest.php

namespace WLMonitor\Tests\Integration;

class PasswordResetTest extends IntegrationTestCase
{
    private int $userId;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->email  = 'reset_' . uniqid() . '@example.com';
        $this->userId = $this->createUser(['email' => $this->email]);
    }

    // --- Token creation ------------------------------------------------------

    public function test_token_is_inserted_and_unused(): void
    {
        $token = $this->insertToken($this->userId);

        $stmt = $this->con->prepare(
            'SELECT used, expires_at FROM password_resets WHERE token = ?'
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['used']);
        $this->assertGreaterThan(new \DateTime(), new \DateTime($row['expires_at']));
    }

    public function test_token_is_64_hex_chars(): void
    {
        $token = $this->insertToken($this->userId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    // --- Token validation queries (mirror executeReset.php logic) ------------

    public function test_valid_token_resolves_to_correct_user(): void
    {
        $token = $this->insertToken($this->userId);
        $row   = $this->fetchResetRow($token);

        $this->assertNotNull($row);
        $this->assertSame($this->userId, (int) $row['user_id']);
    }

    public function test_expired_token_is_not_returned(): void
    {
        $token = $this->insertToken($this->userId, expired: true);
        $row   = $this->fetchResetRow($token);

        $this->assertNull($row);
    }

    public function test_used_token_is_not_returned(): void
    {
        $token = $this->insertToken($this->userId);
        $this->markTokenUsed($token);

        $row = $this->fetchResetRow($token);

        $this->assertNull($row);
    }

    public function test_nonexistent_token_is_not_returned(): void
    {
        $row = $this->fetchResetRow(str_repeat('0', 64));
        $this->assertNull($row);
    }

    // --- Password change + token retirement ----------------------------------

    public function test_using_token_updates_password(): void
    {
        $token  = $this->insertToken($this->userId);
        $newPw  = 'NewSecurePass99!';
        $hash   = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 4]);

        $upd = $this->con->prepare('UPDATE auth_accounts SET password = ? WHERE id = ?');
        $upd->bind_param('si', $hash, $this->userId);
        $upd->execute();
        $upd->close();

        $this->markTokenUsed($token);

        // Verify password changed
        $stmt = $this->con->prepare('SELECT password FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $stored = $stmt->get_result()->fetch_assoc()['password'];
        $stmt->close();

        $this->assertTrue(password_verify($newPw, $stored));
    }

    public function test_using_token_marks_it_as_used(): void
    {
        $token = $this->insertToken($this->userId);
        $this->markTokenUsed($token);

        $stmt = $this->con->prepare('SELECT used FROM password_resets WHERE token = ?');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $used = (int) $stmt->get_result()->fetch_assoc()['used'];
        $stmt->close();

        $this->assertSame(1, $used);
    }

    public function test_token_cannot_be_reused_after_use(): void
    {
        $token = $this->insertToken($this->userId);
        $this->markTokenUsed($token);

        // Attempting to use it again must return no row
        $row = $this->fetchResetRow($token);
        $this->assertNull($row);
    }

    // --- Old tokens are cleared on new request -------------------------------

    public function test_old_tokens_deleted_when_new_one_created(): void
    {
        $old = $this->insertToken($this->userId);

        // Simulate the "delete old tokens then insert new" logic from forgotPassword.php
        $del = $this->con->prepare('DELETE FROM password_resets WHERE user_id = ?');
        $del->bind_param('i', $this->userId);
        $del->execute();
        $del->close();

        $new = $this->insertToken($this->userId);

        // Old token must be gone
        $stmt = $this->con->prepare('SELECT id FROM password_resets WHERE token = ?');
        $stmt->bind_param('s', $old);
        $stmt->execute();
        $stmt->get_result(); // consume
        $this->assertSame(0, $stmt->num_rows);
        $stmt->close();

        // New token must exist
        $this->assertNotNull($this->fetchResetRow($new));
    }

    // --- Email lookup (mirrors forgotPassword.php) ---------------------------

    public function test_email_lookup_finds_activated_user(): void
    {
        $stmt = $this->con->prepare(
            'SELECT id, username FROM auth_accounts
             WHERE email = ? AND activation_code = "activated" AND disabled = "0"'
        );
        $stmt->bind_param('s', $this->email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row);
        $this->assertSame($this->userId, (int) $row['id']);
    }

    public function test_email_lookup_excludes_disabled_user(): void
    {
        $upd = $this->con->prepare('UPDATE auth_accounts SET disabled = "1" WHERE id = ?');
        $upd->bind_param('i', $this->userId);
        $upd->execute();
        $upd->close();

        $stmt = $this->con->prepare(
            'SELECT id FROM auth_accounts
             WHERE email = ? AND activation_code = "activated" AND disabled = "0"'
        );
        $stmt->bind_param('s', $this->email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNull($row);
    }

    public function test_email_lookup_excludes_unactivated_user(): void
    {
        $upd = $this->con->prepare('UPDATE auth_accounts SET activation_code = "pending" WHERE id = ?');
        $upd->bind_param('i', $this->userId);
        $upd->execute();
        $upd->close();

        $stmt = $this->con->prepare(
            'SELECT id FROM auth_accounts
             WHERE email = ? AND activation_code = "activated" AND disabled = "0"'
        );
        $stmt->bind_param('s', $this->email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNull($row);
    }

    // --- Helpers -------------------------------------------------------------

    private function insertToken(int $userId, bool $expired = false): string
    {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = $expired
            ? date('Y-m-d H:i:s', time() - 1)
            : date('Y-m-d H:i:s', time() + 3600);

        $stmt = $this->con->prepare(
            'INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iss', $userId, $token, $expiresAt);
        $stmt->execute();
        $stmt->close();
        return $token;
    }

    private function fetchResetRow(string $token): ?array
    {
        $stmt = $this->con->prepare(
            'SELECT pr.id, pr.user_id, a.username
             FROM password_resets pr
             JOIN auth_accounts a ON a.id = pr.user_id
             WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()'
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function markTokenUsed(string $token): void
    {
        $stmt = $this->con->prepare('UPDATE password_resets SET used = 1 WHERE token = ?');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();
    }
}
