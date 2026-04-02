<?php
// inc/admin.php
// User management -- caller must verify Admin rights before calling these functions

function admin_list_users(mysqli $con, int $page = 1, int $perPage = 25, string $filter = ''): array {
    $offset = ($page - 1) * $perPage;
    $like = null;
    if ($filter !== '') {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter);
        $like = '%' . $escaped . '%';
        $stmt = $con->prepare(
            'SELECT id, username, email, disabled, departures, debug, rights
             FROM wl_accounts WHERE username LIKE ? ORDER BY username LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('sii', $like, $perPage, $offset);
    } else {
        $stmt = $con->prepare(
            'SELECT id, username, email, disabled, departures, debug, rights
             FROM wl_accounts ORDER BY username LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('ii', $perPage, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
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

    // Total count for pagination
    if ($like !== null) {
        $cstmt = $con->prepare('SELECT COUNT(*) FROM wl_accounts WHERE username LIKE ?');
        $cstmt->bind_param('s', $like);
    } else {
        $cstmt = $con->prepare('SELECT COUNT(*) FROM wl_accounts');
    }
    $cstmt->execute();
    $total = 0;
    $cstmt->bind_result($total);
    $cstmt->fetch();
    $cstmt->close();

    return ['users' => $rows, 'total' => (int) $total, 'page' => $page, 'per_page' => $perPage];
}

function admin_edit_user(mysqli $con, int $targetId, string $email, string $rights, int $disabled, int $departures, int $debug): bool {
    $rights = in_array($rights, ['Admin', 'User'], true) ? $rights : 'User';
    $stmt   = $con->prepare(
        'UPDATE wl_accounts SET email = ?, rights = ?, disabled = ?, departures = ?, debug = ? WHERE id = ?'
    );
    $stmt->bind_param('ssiiii', $email, $rights, $disabled, $departures, $debug, $targetId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    appendLog($con, 'admin', "User #$targetId updated.", 'web');
    return $ok;
}

function admin_reset_password(mysqli $con, int $targetId): string {
    $newPass = bin2hex(random_bytes(8));
    $hash    = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 13]);
    $stmt    = $con->prepare('UPDATE wl_accounts SET password = ? WHERE id = ?');
    $stmt->bind_param('si', $hash, $targetId);
    $stmt->execute();
    $stmt->close();
    appendLog($con, 'admin', "Password reset for user #$targetId.", 'web');
    return $newPass;
}

function admin_delete_user(mysqli $con, int $targetId, int $requestingUserId): bool {
    if ($targetId === $requestingUserId) {
        return false; // cannot delete yourself
    }
    $stmt = $con->prepare('DELETE FROM wl_accounts WHERE id = ?');
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    appendLog($con, 'admin', "User #$targetId deleted.", 'web');
    return $ok;
}
