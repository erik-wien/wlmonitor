<?php
/**
 * inc/admin.php
 *
 * User management functions for the Admin panel.
 *
 * IMPORTANT — Authorization boundary
 * ───────────────────────────────────
 * These functions do NOT verify that the caller is an administrator.
 * All call sites in api.php must call api_require_admin() before invoking
 * any function in this file.
 *
 * Security notes
 * ──────────────
 * - The rights field is validated against a fixed whitelist (['Admin', 'User']).
 * - LIKE filter parameters are manually escaped to prevent wildcard injection.
 * - Self-deletion is blocked in admin_delete_user().
 * - All mutating operations are logged via appendLog().
 */

/**
 * Return a paginated, optionally filtered list of all user accounts.
 *
 * The filter is applied as a LIKE %…% match against the username column.
 * Special LIKE characters (\, %, _) in the filter string are escaped.
 *
 * @param mysqli $con     Active database connection.
 * @param int    $page    1-based page number.
 * @param int    $perPage Rows per page (default 25).
 * @param string $filter  Optional substring to match against usernames.
 * @return array {
 *   'users'    : array[]  List of user rows.
 *   'total'    : int      Total rows matching the filter (for pagination).
 *   'page'     : int      Current page.
 *   'per_page' : int      Rows per page.
 * }
 */
function admin_list_users(mysqli $con, int $page = 1, int $perPage = 25, string $filter = ''): array {
    $offset = ($page - 1) * $perPage;
    $like   = null;

    if ($filter !== '') {
        // Escape backslash, percent, and underscore to prevent LIKE injection.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter);
        $like    = '%' . $escaped . '%';
        $stmt    = $con->prepare(
            'SELECT id, username, email, disabled, departures, debug, rights
             FROM jardyx_auth.auth_accounts WHERE username LIKE ? ORDER BY username LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('sii', $like, $perPage, $offset);
    } else {
        $stmt = $con->prepare(
            'SELECT id, username, email, disabled, departures, debug, rights
             FROM jardyx_auth.auth_accounts ORDER BY username LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('ii', $perPage, $offset);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id'         => (int) $row['id'],
            'username'   => $row['username'],
            'email'      => $row['email'],
            'disabled'   => (int) $row['disabled'],
            'departures' => (int) $row['departures'],
            'debug'      => (int) $row['debug'],
            'rights'     => $row['rights'],
        ];
    }
    $stmt->close();

    // Separate count query for the pagination total.
    if ($like !== null) {
        $cstmt = $con->prepare('SELECT COUNT(*) FROM jardyx_auth.auth_accounts WHERE username LIKE ?');
        $cstmt->bind_param('s', $like);
    } else {
        $cstmt = $con->prepare('SELECT COUNT(*) FROM jardyx_auth.auth_accounts');
    }
    $cstmt->execute();
    $total = 0;
    $cstmt->bind_result($total);
    $cstmt->fetch();
    $cstmt->close();

    return ['users' => $rows, 'total' => (int) $total, 'page' => $page, 'per_page' => $perPage];
}

/**
 * Update a user account's editable fields.
 *
 * The rights value is validated against a whitelist; any unrecognised value
 * is silently coerced to 'User'.
 *
 * @param mysqli $con         Active database connection.
 * @param int    $targetId    ID of the account to update.
 * @param string $email       New email address.
 * @param string $rights      Role: 'Admin' | 'User'.
 * @param int    $disabled    1 to disable the account, 0 to enable.
 * @param int    $departures  Per-user departure display count override.
 * @param int    $debug       1 to enable verbose debug logging for this user.
 * @return bool               True if the row was updated.
 */
function admin_edit_user(mysqli $con, int $targetId, string $email, string $rights, int $disabled, int $departures, int $debug): bool {
    $rights      = in_array($rights, ['Admin', 'User'], true) ? $rights : 'User';
    $disabledStr = $disabled ? '1' : '0';
    $debugStr    = $debug    ? '1' : '0';

    $stmt = $con->prepare(
        'UPDATE jardyx_auth.auth_accounts SET email = ?, rights = ?, disabled = ?, departures = ?, debug = ? WHERE id = ?'
    );
    $stmt->bind_param('sssisi', $email, $rights, $disabledStr, $departures, $debugStr, $targetId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    appendLog($con, 'admin', "User #$targetId updated.", 'web');
    return $ok;
}

/**
 * Generate and set a random password for a user account.
 *
 * Generates 8 random bytes (16 hex characters), hashes with bcrypt-13,
 * stores the hash, and returns the plaintext so the admin can communicate
 * it out-of-band to the user.
 *
 * @param mysqli $con      Active database connection.
 * @param int    $targetId ID of the account to reset.
 * @return string          New plaintext password (shown once; not stored).
 */
function admin_reset_password(mysqli $con, int $targetId): string {
    $newPass = bin2hex(random_bytes(8));
    $hash    = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 13]);
    $stmt    = $con->prepare('UPDATE jardyx_auth.auth_accounts SET password = ? WHERE id = ?');
    $stmt->bind_param('si', $hash, $targetId);
    $stmt->execute();
    $stmt->close();
    appendLog($con, 'admin', "Password reset for user #$targetId.", 'web');
    return $newPass;
}

/**
 * Permanently delete a user account.
 *
 * Self-deletion is blocked: if $targetId equals $requestingUserId the
 * function returns false without touching the database.
 *
 * @param mysqli $con              Active database connection.
 * @param int    $targetId         ID of the account to delete.
 * @param int    $requestingUserId ID of the admin performing the delete
 *                                 (from $_SESSION['id']).
 * @return bool                    True if a row was deleted; false if
 *                                 self-deletion was attempted or no row matched.
 */
function admin_delete_user(mysqli $con, int $targetId, int $requestingUserId): bool {
    if ($targetId === $requestingUserId) {
        return false; // An admin cannot delete their own account.
    }
    $stmt = $con->prepare('DELETE FROM jardyx_auth.auth_accounts WHERE id = ?');
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    appendLog($con, 'admin', "User #$targetId deleted.", 'web');
    return $ok;
}
