<?php
// tests/Integration/ImpersonateTest.php

namespace WLMonitor\Tests\Integration;

class ImpersonateTest extends IntegrationTestCase
{
    private int $adminId;
    private int $userId;
    private string $adminUsername;
    private string $userUsername;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUsername = 'admin_' . uniqid();
        $this->userUsername  = 'user_'  . uniqid();

        $this->adminId = $this->createUser([
            'username' => $this->adminUsername,
            'rights'   => 'Admin',
        ]);
        $this->userId = $this->createUser([
            'username' => $this->userUsername,
            'rights'   => 'User',
        ]);

        $_SESSION['id']         = $this->adminId;
        $_SESSION['username']   = $this->adminUsername;
        $_SESSION['email']      = 'admin@example.com';
        $_SESSION['img']        = '';
        $_SESSION['img_type']   = '';
        $_SESSION['has_avatar'] = false;
        $_SESSION['disabled']   = 0;
        $_SESSION['rights']     = 'Admin';
        $_SESSION['theme']      = 'auto';
        $_SESSION['sId']        = '';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['impersonator']);
        parent::tearDown();
    }

    // ── begin / end roundtrip ─────────────────────────────────────────────

    public function test_begin_switches_session_to_target(): void
    {
        $ok = admin_impersonate_begin($this->con, $this->userId);

        $this->assertTrue($ok);
        $this->assertSame($this->userId, $_SESSION['id']);
        $this->assertSame($this->userUsername, $_SESSION['username']);
        $this->assertSame('User', $_SESSION['rights']);
    }

    public function test_end_restores_admin_session(): void
    {
        admin_impersonate_begin($this->con, $this->userId);
        $ok = admin_impersonate_end($this->con);

        $this->assertTrue($ok);
        $this->assertSame($this->adminId, $_SESSION['id']);
        $this->assertSame($this->adminUsername, $_SESSION['username']);
        $this->assertSame('Admin', $_SESSION['rights']);
        $this->assertArrayNotHasKey('impersonator', $_SESSION);
    }

    public function test_is_impersonating_true_after_begin(): void
    {
        admin_impersonate_begin($this->con, $this->userId);
        $this->assertTrue(admin_is_impersonating());
    }

    public function test_is_impersonating_false_after_end(): void
    {
        admin_impersonate_begin($this->con, $this->userId);
        admin_impersonate_end($this->con);
        $this->assertFalse(admin_is_impersonating());
    }

    // ── auth_log.impersonator_id ──────────────────────────────────────────

    public function test_begin_log_entry_has_impersonator_id_set(): void
    {
        admin_impersonate_begin($this->con, $this->userId);

        $stmt = $this->con->prepare(
            'SELECT idUser, impersonator_id FROM ' . AUTH_DB_PREFIX . 'auth_log
              WHERE context = ? AND activity LIKE ?
              ORDER BY id DESC LIMIT 1'
        );
        $ctx      = 'admin';
        $activity = '%began impersonating%';
        $stmt->bind_param('ss', $ctx, $activity);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row, 'No begin-impersonation log entry found');
        $this->assertSame($this->userId,  (int) $row['idUser'],          'idUser should be target');
        $this->assertSame($this->adminId, (int) $row['impersonator_id'], 'impersonator_id should be admin');
    }

    public function test_normal_log_entry_has_null_impersonator_id(): void
    {
        appendLog($this->con, 'test', 'plain entry');

        $stmt = $this->con->prepare(
            'SELECT impersonator_id FROM ' . AUTH_DB_PREFIX . 'auth_log
              WHERE context = ? AND activity = ?
              ORDER BY id DESC LIMIT 1'
        );
        $ctx      = 'test';
        $activity = 'plain entry';
        $stmt->bind_param('ss', $ctx, $activity);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row);
        $this->assertNull($row['impersonator_id']);
    }

    // ── guard: refused begins ─────────────────────────────────────────────

    public function test_begin_blocked_for_admin_target(): void
    {
        $ok = admin_impersonate_begin($this->con, $this->adminId);
        $this->assertFalse($ok);
        $this->assertFalse(admin_is_impersonating());
    }

    public function test_begin_blocked_for_nonexistent_target(): void
    {
        $ok = admin_impersonate_begin($this->con, 999999999);
        $this->assertFalse($ok);
    }

    public function test_begin_blocked_when_already_impersonating(): void
    {
        admin_impersonate_begin($this->con, $this->userId);

        $secondUserId = $this->createUser(['rights' => 'User']);
        $ok = admin_impersonate_begin($this->con, $secondUserId);

        $this->assertFalse($ok);
        $this->assertSame($this->userId, $_SESSION['id']); // still impersonating first user
    }
}
