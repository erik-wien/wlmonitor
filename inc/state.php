<?php
/**
 * inc/state.php — per-user cross-session monitor state.
 */

/**
 * Load persisted monitor state for a user from wl_preferences.
 *
 * @return array{last_fav_id: int|null, last_diva: string|null}
 */
function state_load(mysqli $con, int $userId): array
{
    $stmt = $con->prepare('SELECT last_fav_id, last_diva FROM wl_preferences WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        'last_fav_id' => ($row && $row['last_fav_id'] !== null) ? (int) $row['last_fav_id'] : null,
        'last_diva'   => $row['last_diva'] ?? null,
    ];
}

/**
 * Upsert persisted monitor state.
 * Pass null for $favId on ad-hoc station loads; pass null for $diva to clear it.
 */
function state_upsert(mysqli $con, int $userId, ?int $favId, ?string $diva): void
{
    $stmt = $con->prepare(
        'INSERT INTO wl_preferences (user_id, last_fav_id, last_diva)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE last_fav_id = VALUES(last_fav_id),
                                 last_diva   = VALUES(last_diva)'
    );
    $stmt->bind_param('iis', $userId, $favId, $diva);
    $stmt->execute();
    $stmt->close();
}
