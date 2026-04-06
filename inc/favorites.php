<?php
/**
 * inc/favorites.php
 *
 * CRUD operations for the wl_favorites table.
 *
 * A "favorite" is a named shortcut to one or more transit stations, stored as
 * a comma-separated list of DIVA numbers.  Users can assign a Bootstrap button
 * class (bclass) for visual customisation and a sort order for display sequence.
 *
 * Security notes
 * ──────────────
 * - All write operations embed $idUser in the WHERE clause so a user can only
 *   affect their own rows (IDOR prevention).
 * - DIVA input is sanitised to [0-9,] via sanitizeDivaInput().
 * - Title is stripped of HTML tags and capped at 100 characters.
 * - bclass is restricted to [a-z0-9-] to allow only valid Bootstrap class names.
 * - All queries use prepared statements (SQL injection prevention).
 */

/**
 * Retrieve all favorites for a user, ordered by sort then id.
 *
 * @param mysqli $con    Active database connection.
 * @param int    $idUser User ID from $_SESSION['id'].
 * @return array[]       List of favorite rows:
 *                       ['id', 'title', 'diva', 'bclass', 'sort']
 */
function favorites_get(mysqli $con, int $idUser): array {
    $stmt = $con->prepare(
        'SELECT id, idUser, title, diva, bclass, sort
         FROM wl_favorites WHERE idUser = ? ORDER BY sort, id'
    );
    $stmt->bind_param('i', $idUser);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id'     => (int) $row['id'],
            'title'  => $row['title'],
            'diva'   => $row['diva'],
            'bclass' => $row['bclass'],
            'sort'   => (int) $row['sort'],
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * Check whether a DIVA string is already saved as a favorite for a user.
 *
 * @param mysqli $con    Active database connection.
 * @param int    $idUser User ID.
 * @param string $diva   DIVA number(s) to look up (sanitised internally).
 * @return bool          True if an exact match exists.
 */
function favorites_check(mysqli $con, int $idUser, string $diva): bool {
    $diva = sanitizeDivaInput($diva);
    $stmt = $con->prepare('SELECT id FROM wl_favorites WHERE idUser = ? AND diva = ?');
    $stmt->bind_param('is', $idUser, $diva);
    $stmt->execute();
    $stmt->store_result();
    $found = $stmt->num_rows > 0;
    $stmt->close();
    return $found;
}

/**
 * Create a new favorite.
 *
 * Input sanitisation applied:
 *  - title  : strip_tags() + mb_substr(..., 0, 100)
 *  - diva   : sanitizeDivaInput() → [0-9,] only
 *  - bclass : preg_replace → [a-z0-9-] only (Bootstrap class names)
 *
 * @param mysqli $con    Active database connection.
 * @param int    $idUser Owner's user ID.
 * @param string $title  Display label (max 100 chars, no HTML).
 * @param string $diva   DIVA number(s), comma-separated.
 * @param string $bclass Bootstrap button variant class (e.g. 'btn-outline-success').
 * @param int    $sort   Sort position (lower = higher in list).
 * @return int           Auto-incremented ID of the new row.
 */
function favorites_add(mysqli $con, int $idUser, string $title, string $diva, string $bclass, int $sort): int {
    $title  = mb_substr(strip_tags($title), 0, 100);
    $diva   = sanitizeDivaInput($diva);
    $bclass = preg_replace('/[^a-z0-9\-]/', '', $bclass);

    $stmt = $con->prepare(
        'INSERT INTO wl_favorites (idUser, title, sort, diva, bclass, updated, created)
         VALUES (?, ?, ?, ?, ?, SYSDATE(), CURRENT_TIMESTAMP)'
    );
    $stmt->bind_param('isiss', $idUser, $title, $sort, $diva, $bclass);
    $stmt->execute();
    $newId = (int) $con->insert_id;
    $stmt->close();
    appendLog($con, 'favAdd', "Favorite #$newId ($title) added.", 'web');
    return $newId;
}

/**
 * Update an existing favorite.
 *
 * The WHERE clause includes both id AND idUser so users cannot modify
 * another user's favorites even if they guess the row ID.
 *
 * @param mysqli $con    Active database connection.
 * @param int    $idUser Owner's user ID (ownership check).
 * @param int    $favId  Row ID to update.
 * @param string $title  New display label.
 * @param string $diva   New DIVA number(s).
 * @param string $bclass New Bootstrap button class.
 * @param int    $sort   New sort position.
 * @return bool          True if exactly one row was updated.
 */
function favorites_edit(mysqli $con, int $idUser, int $favId, string $title, string $diva, string $bclass, int $sort): bool {
    $title  = mb_substr(strip_tags($title), 0, 100);
    $diva   = sanitizeDivaInput($diva);
    $bclass = preg_replace('/[^a-z0-9\-]/', '', $bclass);

    $stmt = $con->prepare(
        'UPDATE wl_favorites SET title = ?, diva = ?, bclass = ?, sort = ?
         WHERE id = ? AND idUser = ?'
    );
    $stmt->bind_param('sssiii', $title, $diva, $bclass, $sort, $favId, $idUser);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    appendLog($con, 'favEdit', "Favorite #$favId updated.", 'web');
    return $affected > 0;
}

/**
 * Delete a favorite by ID.
 *
 * Ownership is enforced by including idUser in the DELETE WHERE clause.
 *
 * @param mysqli $con    Active database connection.
 * @param int    $idUser Owner's user ID (ownership check).
 * @param int    $favId  Row ID to delete.
 * @return bool          True if a row was deleted.
 */
function favorites_delete(mysqli $con, int $idUser, int $favId): bool {
    $stmt = $con->prepare('DELETE FROM wl_favorites WHERE id = ? AND idUser = ?');
    $stmt->bind_param('ii', $favId, $idUser);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    appendLog($con, 'favDel', "Favorite #$favId deleted.", 'web');
    return $affected > 0;
}

/**
 * Persist a new sort order for a set of favorites.
 *
 * Each element of $items must be an associative array with integer 'id' and
 * 'sort' keys.  Items with missing keys are silently skipped.  The idUser
 * check in the WHERE clause prevents a user from reordering another user's
 * favorites.
 *
 * @param mysqli  $con    Active database connection.
 * @param int     $idUser Owner's user ID (ownership check on each row).
 * @param array[] $items  [['id' => int, 'sort' => int], …]
 */
function favorites_save_sort(mysqli $con, int $idUser, array $items): void {
    $stmt = $con->prepare('UPDATE wl_favorites SET sort = ? WHERE id = ? AND idUser = ?');
    foreach ($items as $item) {
        if (!isset($item['id'], $item['sort'])) continue;
        $id   = (int) $item['id'];
        $sort = (int) $item['sort'];
        $stmt->bind_param('iii', $sort, $id, $idUser);
        $stmt->execute();
    }
    $stmt->close();
    appendLog($con, 'favSort', 'Sort order saved.', 'web');
}
