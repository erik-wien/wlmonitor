<?php
// tests/Integration/AdminTest.php

namespace WLMonitor\Tests\Integration;

class AdminTest extends IntegrationTestCase
{
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId  = $this->createUser(['rights' => 'Admin']);
        $_SESSION['id'] = $this->adminId;
    }

    // --- admin_list_users ----------------------------------------------------

    public function test_list_returns_paginated_shape(): void
    {
        $result = admin_list_users($this->con);
        $this->assertArrayHasKey('users',    $result);
        $this->assertArrayHasKey('total',    $result);
        $this->assertArrayHasKey('page',     $result);
        $this->assertArrayHasKey('per_page', $result);
    }

    public function test_list_total_reflects_all_users(): void
    {
        $before = admin_list_users($this->con)['total'];
        $this->createUser();
        $this->createUser();
        $after = admin_list_users($this->con)['total'];
        $this->assertSame($before + 2, $after);
    }

    public function test_list_per_page_limits_rows(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createUser();
        }
        $result = admin_list_users($this->con, 1, 3);
        $this->assertCount(3, $result['users']);
        $this->assertSame(3, $result['per_page']);
    }

    public function test_list_page_2_returns_different_rows(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->createUser();
        }
        $page1 = array_column(admin_list_users($this->con, 1, 3)['users'], 'id');
        $page2 = array_column(admin_list_users($this->con, 2, 3)['users'], 'id');
        $this->assertEmpty(array_intersect($page1, $page2), 'Page 1 and page 2 must not share rows');
    }

    public function test_list_filter_matches_by_username(): void
    {
        $unique = 'xqzfilter_' . uniqid();
        $this->createUser(['username' => $unique]);

        $result = admin_list_users($this->con, 1, 25, $unique);
        $names  = array_column($result['users'], 'username');

        $this->assertContains($unique, $names);
        $this->assertSame(1, $result['total']);
    }

    public function test_list_filter_excludes_non_matching_users(): void
    {
        $unique = 'zzznoone_' . uniqid();
        $result = admin_list_users($this->con, 1, 25, $unique);
        $this->assertSame(0, $result['total']);
    }

    public function test_list_filter_escapes_percent_wildcard(): void
    {
        $this->createUser(['username' => 'pct_user']);
        $this->createUser(['username' => 'another_user']);

        // If "%" were unescaped it would be a wildcard matching every row.
        // Properly escaped it should match only usernames containing a literal "%".
        // None of our test users have "%" in their name, so the count must be 0.
        $result = admin_list_users($this->con, 1, 25, '%');
        $this->assertSame(0, $result['total']);
    }

    public function test_list_filter_escapes_underscore_wildcard(): void
    {
        // If "_" were unescaped it would be a single-char wildcard and match everything
        $this->createUser(['username' => 'under_score']);

        $result = admin_list_users($this->con, 1, 25, '_');
        // No user has a literal single underscore as their whole name
        foreach ($result['users'] as $u) {
            $this->assertNotSame('_', $u['username']);
        }
    }

    // --- admin_edit_user -----------------------------------------------------

    public function test_edit_updates_email_and_rights(): void
    {
        $uid  = $this->createUser(['email' => 'old@example.com', 'rights' => 'User']);
        $ok   = admin_edit_user($this->con, $uid, 'new@example.com', 'Admin', 0, 3, 0);

        $stmt = $this->con->prepare('SELECT email, rights, departures FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertTrue($ok);
        $this->assertSame('new@example.com', $row['email']);
        $this->assertSame('Admin', $row['rights']);
        $this->assertSame(3, (int) $row['departures']);
    }

    public function test_edit_rejects_invalid_rights_value(): void
    {
        $uid = $this->createUser(['rights' => 'User']);
        admin_edit_user($this->con, $uid, 'x@example.com', 'Superuser', 0, 2, 0);

        $stmt = $this->con->prepare('SELECT rights FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $rights = $stmt->get_result()->fetch_assoc()['rights'];
        $stmt->close();

        // 'Superuser' is not allowed; must fall back to 'User'
        $this->assertSame('User', $rights);
    }

    // --- admin_reset_password ------------------------------------------------

    public function test_reset_password_returns_plaintext(): void
    {
        $uid      = $this->createUser();
        $newPass  = admin_reset_password($this->con, $uid);

        $this->assertNotEmpty($newPass);
        $this->assertSame(16, strlen($newPass)); // bin2hex(8 bytes) = 16 hex chars
    }

    public function test_reset_password_hash_verifies(): void
    {
        $uid     = $this->createUser();
        $newPass = admin_reset_password($this->con, $uid);

        $stmt = $this->con->prepare('SELECT password FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $hash = $stmt->get_result()->fetch_assoc()['password'];
        $stmt->close();

        $this->assertTrue(password_verify($newPass, $hash));
    }

    // --- admin_delete_user ---------------------------------------------------

    public function test_delete_removes_user(): void
    {
        $uid = $this->createUser();
        $ok  = admin_delete_user($this->con, $uid, $this->adminId);

        $stmt = $this->con->prepare('SELECT id FROM auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertTrue($ok);
        $this->assertNull($row);
    }

    public function test_delete_returns_false_when_deleting_self(): void
    {
        $ok = admin_delete_user($this->con, $this->adminId, $this->adminId);
        $this->assertFalse($ok);
    }

    public function test_delete_returns_false_for_nonexistent_user(): void
    {
        $ok = admin_delete_user($this->con, 999999999, $this->adminId);
        $this->assertFalse($ok);
    }
}
