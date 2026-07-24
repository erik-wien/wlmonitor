<?php
/**
 * inc/admin.php
 *
 * Thin wrappers around the shared chrome + auth admin APIs that hydrate the
 * wlmonitor-specific per-user fields (wl_preferences.departures) onto each
 * user row.
 *
 * Authorization boundary
 * ──────────────────────
 * These functions do NOT check caller rights. All call sites in api.php must
 * call api_require_admin() before invoking any function here.
 */

/**
 * Paginated user list with wlmonitor extras (departures) merged in.
 *
 * Returns the same shape as \Erikr\Chrome\Admin\Users::listExtended(), plus
 * a `departures` key on each user row.
 */
function wl_admin_list_users(mysqli $con, int $page = 1, int $perPage = 25, string $filter = ''): array
{
    $data = \Erikr\Chrome\Admin\Users::listExtended($con, $page, $perPage, $filter);

    if (empty($data['users'])) {
        return $data;
    }

    $ids          = array_column($data['users'], 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types        = str_repeat('i', count($ids));

    // Departures lives in wlmonitor.wl_preferences (cross-DB from auth).
    $stmt = $con->prepare(
        "SELECT user_id, departures FROM wl_preferences WHERE user_id IN ($placeholders)"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $prefs = [];
    $res   = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $prefs[(int) $row['user_id']] = (int) $row['departures'];
    }
    $stmt->close();

    foreach ($data['users'] as &$user) {
        $user['departures'] = $prefs[$user['id']] ?? MAX_DEPARTURES;
    }
    unset($user);

    return $data;
}

/**
 * Update a user's auth fields (via the library) plus wlmonitor's
 * departures preference.
 *
 * The departures write is deliberately NOT gated on admin_edit_user()'s
 * return value: the library reports success via affected_rows, which is 0
 * whenever the UPDATE leaves every auth column unchanged — the common
 * edit-modal case where the admin only changes "Abfahrten" and
 * email/rights/disabled stay as pre-filled (same bug pattern as
 * zeiterfassung TASK-17 Review-Fix). admin_edit_user() throws on genuine DB
 * errors, so reaching the departures write means the auth-side call itself
 * did not fail. To avoid creating an orphan wl_preferences row for a
 * non-existent targetId, the write is additionally gated on the target
 * actually existing in auth_accounts.
 */
function wl_admin_edit_user(
    mysqli $con,
    int    $targetId,
    string $email,
    string $rights,
    int    $disabled,
    int    $departures,
    bool   $totp_reset = false
): bool {
    $ok = admin_edit_user($con, $targetId, $email, $rights, $disabled, $totp_reset);

    $departuresWritten = false;
    if ($targetId > 0 && wl_admin_user_exists($con, $targetId)) {
        $departures = max(1, min($departures, MAX_DEPARTURES));
        $stmt = $con->prepare(
            'INSERT INTO wl_preferences (user_id, departures) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE departures = VALUES(departures)'
        );
        $stmt->bind_param('ii', $targetId, $departures);
        $stmt->execute();
        $stmt->close();
        $departuresWritten = true;
    }

    return $ok || $departuresWritten;
}

/**
 * @internal Whether $targetId has a row in auth_accounts. Used to guard the
 * wl_preferences write against orphan rows for non-existent users.
 */
function wl_admin_user_exists(mysqli $con, int $targetId): bool
{
    $stmt = $con->prepare('SELECT 1 FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE id = ?');
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    return $exists;
}
