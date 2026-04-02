<?php
// inc/favorites.php
// Favorites CRUD for wl_favorites table

function favorites_get(mysqli $con, int $idUser): array {
    $stmt = $con->prepare(
        'SELECT id, idUser, title, diva, bclass, sort FROM wl_favorites WHERE idUser = ? ORDER BY sort, id'
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

function favorites_add(mysqli $con, int $idUser, string $title, string $diva, string $bclass, int $sort): int {
    $title  = mb_substr(strip_tags($title), 0, 120);
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

function favorites_edit(mysqli $con, int $idUser, int $favId, string $title, string $diva, string $bclass, int $sort): bool {
    $title  = mb_substr(strip_tags($title), 0, 120);
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
    return $affected >= 0;
}

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
 * Save a new sort order.
 * $items = [['id' => 12, 'sort' => 1], ...]
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
