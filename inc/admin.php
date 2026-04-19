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

    if ($ok) {
        $departures = max(1, min($departures, MAX_DEPARTURES));
        $stmt = $con->prepare(
            'INSERT INTO wl_preferences (user_id, departures) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE departures = VALUES(departures)'
        );
        $stmt->bind_param('ii', $targetId, $departures);
        $stmt->execute();
        $stmt->close();
    }

    return $ok;
}
