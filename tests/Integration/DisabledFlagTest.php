<?php
// tests/Integration/DisabledFlagTest.php

namespace WLMonitor\Tests\Integration;

use Erikr\Chrome\Admin\Users;

/**
 * Regression tests for the auth_accounts.disabled ENUM writes.
 *
 * auth_accounts.disabled is enum('0','1'). MariaDB reads a BARE NUMBER in an
 * ENUM assignment as the member INDEX, not as the value:
 *   disabled = 1  -> index 1 -> first member -> '0'   (account stays ENABLED)
 *   disabled = 0  -> the error member        -> ERROR 1265 "Data truncated"
 * Binding the flag as an integer therefore broke BOTH directions, each in a
 * different way, while the calling code believed it had succeeded.
 *
 * These tests exist because the auth/chrome unit suites mock mysqli: a stub
 * happily accepts any bind type, so the wrong semantics only appear against a
 * real server. Hence: Integration, real DB, and always asserting the STORED
 * VALUE, never just the return value.
 *
 * Fixed in auth 9382104 (auth_deactivate_own_account) and
 * chrome ee19b96 (Users::setDisabled).
 */
class DisabledFlagTest extends IntegrationTestCase
{
    /** Read the raw enum value straight from the row. */
    private function storedDisabled(int $userId): ?string
    {
        $stmt = $this->con->prepare(
            'SELECT disabled FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE id = ?'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : (string) $row['disabled'];
    }

    // --- Chrome\Admin\Users::setDisabled -------------------------------------
    // Called by Chrome\Admin\Dispatch for the "Deaktivieren"/"Aktivieren"
    // buttons in the shared user administration of all seven suite apps.

    public function test_set_disabled_true_stores_the_string_one(): void
    {
        $uid = $this->createUser(['disabled' => '0']);

        $ok = Users::setDisabled($this->con, $uid, true);

        $this->assertTrue($ok, 'setDisabled(true) must report a changed row');
        $this->assertSame(
            '1',
            $this->storedDisabled($uid),
            "Deactivating must store the enum VALUE '1'. An integer bind stores "
            . "member index 1, i.e. '0', and the account silently stays active."
        );
    }

    public function test_set_disabled_false_stores_the_string_zero(): void
    {
        $uid = $this->createUser(['disabled' => '1']);

        $ok = Users::setDisabled($this->con, $uid, false);

        $this->assertTrue($ok, 'setDisabled(false) must report a changed row');
        $this->assertSame(
            '0',
            $this->storedDisabled($uid),
            "Reactivating must store the enum VALUE '0'. An integer bind sends 0, "
            . 'which is the ENUM error member and aborts with "Data truncated".'
        );
    }

    public function test_set_disabled_round_trip_ends_enabled(): void
    {
        $uid = $this->createUser(['disabled' => '0']);

        Users::setDisabled($this->con, $uid, true);
        $this->assertSame('1', $this->storedDisabled($uid));

        Users::setDisabled($this->con, $uid, false);
        $this->assertSame('0', $this->storedDisabled($uid));
    }

    // --- auth_deactivate_own_account -----------------------------------------

    public function test_deactivate_own_account_stores_the_string_one(): void
    {
        $uid = $this->createUser(['rights' => 'User', 'disabled' => '0']);

        $res = auth_deactivate_own_account($this->con, $uid, 'TestPass123!');

        $this->assertTrue($res['ok'], 'Correct password on a non-admin account must succeed');
        $this->assertNull($res['error']);
        $this->assertSame(
            '1',
            $this->storedDisabled($uid),
            "Self-deactivation must store the enum VALUE '1'; an integer bind "
            . 'left the account active while reporting success.'
        );
    }

    public function test_deactivate_own_account_rejects_wrong_password_and_leaves_account_enabled(): void
    {
        $uid = $this->createUser(['rights' => 'User', 'disabled' => '0']);

        $res = auth_deactivate_own_account($this->con, $uid, 'definitely-not-the-password');

        $this->assertFalse($res['ok']);
        $this->assertSame('wrong_password', $res['error']);
        $this->assertSame('0', $this->storedDisabled($uid), 'A rejected attempt must not touch the flag');
    }

    public function test_deactivate_own_account_refuses_admins_and_leaves_account_enabled(): void
    {
        $uid = $this->createUser(['rights' => 'Admin', 'disabled' => '0']);

        $res = auth_deactivate_own_account($this->con, $uid, 'TestPass123!');

        $this->assertFalse($res['ok']);
        $this->assertSame('admin_cannot_deactivate', $res['error']);
        $this->assertSame('0', $this->storedDisabled($uid), 'An admin must stay enabled');
    }

    public function test_deactivate_own_account_reports_already_disabled(): void
    {
        $uid = $this->createUser(['rights' => 'User', 'disabled' => '1']);

        $res = auth_deactivate_own_account($this->con, $uid, 'TestPass123!');

        $this->assertFalse($res['ok']);
        $this->assertSame('already_disabled', $res['error']);
        $this->assertSame('1', $this->storedDisabled($uid));
    }
}
