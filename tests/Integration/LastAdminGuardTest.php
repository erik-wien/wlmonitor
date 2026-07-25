<?php
// tests/Integration/LastAdminGuardTest.php

namespace WLMonitor\Tests\Integration;

/**
 * Regression tests for the last-admin guard in admin_delete_user().
 *
 * The guard counts the remaining enabled Admins with
 *   WHERE rights = 'Admin' AND disabled = '0' AND id <> ?
 * auth_accounts.disabled is enum('0','1'), and in a COMPARISON a bare number is
 * read as the member INDEX — index 0 is the ENUM error member, so the unquoted
 * form `disabled = 0` matches NO row at all. The count was therefore always 0
 * and EVERY deletion of an Admin account was refused with 'last_admin', in all
 * seven suite apps (fixed in auth 98d905b).
 *
 * The auth unit suite mocks mysqli, so no SQL ever reaches a server there and a
 * stub cannot reproduce ENUM semantics. Only a real connection can — hence this
 * lives in Integration, like DisabledFlagTest.
 *
 * Test world: admin_delete_user() reads through auth_privileged_con(), a
 * SEPARATE mysqli connection that cannot see rows this test's transaction has
 * not committed (and would block on their locks). Like
 * AdminTest::test_delete_removes_user(), these tests therefore commit and clean
 * up by hand instead of relying on the rollback in tearDown().
 *
 * To make the admin count deterministic, setUp() disables every pre-existing
 * enabled Admin and tearDown() restores them from a snapshot — the tests then
 * only ever see their own throwaway accounts.
 */
class LastAdminGuardTest extends IntegrationTestCase
{
    /** IDs of real Admin rows temporarily set to disabled='1' by setUp(). */
    private array $hiddenAdminIds = [];

    /** Throwaway accounts created by this test, to be removed in tearDown(). */
    private array $scratchIds = [];

    /** A non-admin account standing in for the acting administrator. */
    private int $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        // Leave the transaction: the privileged connection must be able to see
        // and lock the rows created below.
        $this->con->commit();

        $this->hiddenAdminIds = $this->enabledAdminIds();
        foreach ($this->hiddenAdminIds as $id) {
            $this->setDisabled($id, '1');
        }

        // Deliberately a User: the acting admin's own row must not influence
        // the count of remaining admins in these tests.
        $this->actorId  = $this->scratch(['rights' => 'User']);
        $_SESSION['id'] = $this->actorId;
    }

    protected function tearDown(): void
    {
        foreach ($this->scratchIds as $id) {
            $this->removeAccount($id);
        }
        foreach ($this->hiddenAdminIds as $id) {
            $this->setDisabled($id, '0');
        }
        parent::tearDown();
    }

    // --- Helpers -------------------------------------------------------------

    /** @return int[] */
    private function enabledAdminIds(): array
    {
        $res = $this->con->query(
            'SELECT id FROM ' . AUTH_DB_PREFIX . "auth_accounts
              WHERE rights = 'Admin' AND disabled = '0'"
        );
        $ids = [];
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $res->free();

        return $ids;
    }

    private function setDisabled(int $id, string $value): void
    {
        // Bound as a STRING — see the class docblock.
        $stmt = $this->con->prepare(
            'UPDATE ' . AUTH_DB_PREFIX . 'auth_accounts SET disabled = ? WHERE id = ?'
        );
        $stmt->bind_param('si', $value, $id);
        $stmt->execute();
        $stmt->close();
    }

    /** Create a throwaway account and remember it for cleanup. */
    private function scratch(array $overrides = []): int
    {
        $id = $this->createUser($overrides);
        $this->scratchIds[] = $id;

        return $id;
    }

    private function exists(int $id): bool
    {
        $stmt = $this->con->prepare(
            'SELECT id FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE id = ?'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null;
    }

    /**
     * Remove a throwaway account without going through admin_delete_user().
     * The app DB user deliberately has no DELETE on auth_accounts (Auth-Rules
     * §8), so the account row goes over the privileged connection; the app data
     * goes over the app connection, because the cleanup hooks do not run here.
     */
    private function removeAccount(int $id): void
    {
        $stmt = $this->con->prepare('DELETE FROM wl_preferences WHERE user_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $priv = auth_privileged_con($this->con);
        $stmt = $priv->prepare(
            'DELETE FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE id = ?'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    // --- Tests ---------------------------------------------------------------

    /**
     * The case the ENUM bug broke: with other enabled Admins around, deleting an
     * Admin must go through.
     */
    public function test_deleting_an_admin_succeeds_while_other_admins_remain(): void
    {
        $this->scratch(['rights' => 'Admin']);
        $this->scratch(['rights' => 'Admin']);
        $target = $this->scratch(['rights' => 'Admin']);

        $res = admin_delete_user($this->con, $target, $this->actorId);

        $this->assertTrue(
            $res['ok'],
            "Two other enabled Admins remain, so this deletion must succeed. "
            . "With the unquoted `disabled = 0` the count query matches no row, "
            . "the guard sees 0 remaining admins and refuses every Admin deletion."
        );
        $this->assertNull($res['error']);
        $this->assertFalse($this->exists($target), 'The account must really be gone');
    }

    /** The guard itself: the last enabled Admin stays. */
    public function test_deleting_the_last_enabled_admin_is_refused(): void
    {
        $target = $this->scratch(['rights' => 'Admin']);

        $res = admin_delete_user($this->con, $target, $this->actorId);

        $this->assertFalse($res['ok']);
        $this->assertSame('last_admin', $res['error']);
        $this->assertTrue($this->exists($target), 'A refused deletion must leave the account');
    }

    /** Disabled Admins cannot administer anything, so they do not count. */
    public function test_a_disabled_admin_does_not_count_as_a_remaining_admin(): void
    {
        $this->scratch(['rights' => 'Admin', 'disabled' => '1']);
        $target = $this->scratch(['rights' => 'Admin']);

        $res = admin_delete_user($this->con, $target, $this->actorId);

        $this->assertFalse($res['ok']);
        $this->assertSame('last_admin', $res['error']);
        $this->assertTrue($this->exists($target));
    }

    /**
     * The guard must only ever look at Admin targets. Counting unconditionally
     * would reject every deletion whenever no enabled Admin exists — with a
     * reason that has nothing to do with the account being deleted.
     */
    public function test_a_non_admin_target_is_never_refused_as_last_admin(): void
    {
        // setUp() left no enabled Admin at all — the worst case for the guard.
        $this->assertSame([], $this->enabledAdminIds());

        $target = $this->scratch(['rights' => 'User']);

        $res = admin_delete_user($this->con, $target, $this->actorId);

        $this->assertNotSame(
            'last_admin',
            $res['error'],
            'A non-admin deletion must never be blocked by the last-admin guard'
        );
        $this->assertTrue($res['ok']);
        $this->assertFalse($this->exists($target));
    }
}
