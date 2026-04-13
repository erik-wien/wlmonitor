<?php
/**
 * inc/admin.php
 *
 * Thin wrappers around the erikr/auth admin library that add wlmonitor-specific
 * per-user preferences (wl_preferences.departures).
 *
 * All functions in this file call library functions from erikr/auth for auth_accounts
 * operations and handle wl_preferences locally.
 *
 * Authorization boundary
 * ──────────────────────
 * These functions do NOT check caller rights. All call sites in api.php must call
 * api_require_admin() before invoking any function here.
 */

/**
 * Paginated user list with wlmonitor departures preference merged in.
 *
 * Calls admin_list_users() from the library (which queries auth_accounts only),
 * then fetches departures from wl_preferences and merges by user_id.
 *
 * @return array Same shape as admin_list_users() but each user row also contains 'departures' key.
 */
function wl_admin_list_users(mysqli $con, int $page = 1, int $perPage = 25, string $filter = ''): array
{
    $data = admin_list_users($con, $page, $perPage, $filter);

    if (empty($data['users'])) {
        return $data;
    }

    $ids          = array_column($data['users'], 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types        = str_repeat('i', count($ids));
    $stmt         = $con->prepare(
        "SELECT user_id, departures FROM wl_preferences WHERE user_id IN ($placeholders)"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $prefs  = [];
    while ($row = $result->fetch_assoc()) {
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
 * Update a user's auth fields and wlmonitor departures preference.
 *
 * @return bool True if the auth_accounts row was updated.
 */
function wl_admin_edit_user(
    mysqli $con,
    int    $targetId,
    string $email,
    string $rights,
    int    $disabled,
    int    $departures,
    int    $debug
): bool {
    $ok = admin_edit_user($con, $targetId, $email, $rights, $disabled, $debug);

    if ($ok) {
        $departures = max(1, min($departures, MAX_DEPARTURES));
        $stmt       = $con->prepare(
            'INSERT INTO wl_preferences (user_id, departures) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE departures = VALUES(departures)'
        );
        $stmt->bind_param('ii', $targetId, $departures);
        $stmt->execute();
        $stmt->close();
    }

    return $ok;
}
