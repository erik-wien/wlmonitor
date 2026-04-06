<?php
/**
 * scripts/migrate_diva.php
 *
 * One-time migration:
 *   1. Renames wl_favorites.rbls → diva (if not already done)
 *   2. Renames ogd_steige.DIVA → RBL (the column holds RBL numbers, not DIVAs)
 *   3. Converts existing favorites from RBL/stop-level numbers to
 *      station-level DIVA numbers (as required by the WL realtime API)
 *   4. Recreates the ogd_stations and ogd_diva views
 *
 * Safe to run multiple times — skips steps already completed.
 *
 * Run:  php scripts/migrate_diva.php
 *       APP_ENV=production php scripts/migrate_diva.php
 */

if (php_sapi_name() !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../include/initialize.php';

// --- Step 1a: rename wl_favorites.rbls → diva (if not already done) --------
$cols = [];
$r = $con->query("SHOW COLUMNS FROM wl_favorites");
while ($row = $r->fetch_assoc()) $cols[] = $row['Field'];

if (in_array('rbls', $cols, true) && !in_array('diva', $cols, true)) {
    $con->query("ALTER TABLE wl_favorites CHANGE rbls diva VARCHAR(255) NOT NULL DEFAULT ''");
    echo "Renamed wl_favorites.rbls → diva\n";
} elseif (in_array('diva', $cols, true)) {
    echo "wl_favorites.diva already exists — skipping rename\n";
} else {
    echo "WARNING: neither rbls nor diva column found in wl_favorites\n";
}

// --- Step 1b: rename ogd_steige.DIVA → RBL and add proper DIVA column ------
$steigeCols = [];
$r = $con->query("SHOW COLUMNS FROM ogd_steige");
while ($row = $r->fetch_assoc()) $steigeCols[] = $row['Field'];

if (in_array('DIVA', $steigeCols, true) && !in_array('RBL', $steigeCols, true)) {
    $con->query("ALTER TABLE ogd_steige CHANGE DIVA RBL VARCHAR(10)");
    echo "Renamed ogd_steige.DIVA → RBL\n";
    $steigeCols[] = 'RBL';
} elseif (in_array('RBL', $steigeCols, true)) {
    echo "ogd_steige.RBL already exists — skipping rename\n";
} else {
    echo "WARNING: neither DIVA nor RBL column found in ogd_steige\n";
}

if (!in_array('DIVA', $steigeCols, true)) {
    $con->query("ALTER TABLE ogd_steige ADD COLUMN DIVA VARCHAR(20) NULL AFTER RBL");
    if ($con->error) {
        echo "ERROR adding ogd_steige.DIVA: " . $con->error . "\n";
        exit(1);
    }
    echo "Added ogd_steige.DIVA column\n";
}

// Populate DIVA from ogd_haltestellen (station-level, required by WL realtime API)
$con->query(
    'UPDATE ogd_steige s
     JOIN ogd_haltestellen h ON s.FK_HALTESTELLEN_ID = h.HALTESTELLEN_ID
     SET s.DIVA = h.DIVA'
);
echo "ogd_steige.DIVA populated (" . $con->affected_rows . " rows)\n";

// --- Step 2: convert RBL numbers to station-level DIVA in favorites ---------
$favs = $con->query('SELECT id, title, diva FROM wl_favorites');
$updated = 0;
$skipped = 0;
while ($fav = $favs->fetch_assoc()) {
    $rbls = array_map('trim', explode(',', $fav['diva']));

    // Already migrated if all values are 8-digit station-level DIVAs
    $needsMigration = array_filter($rbls, fn($r) => is_numeric($r) && strlen($r) < 7);
    if (empty($needsMigration)) {
        $skipped++;
        continue;
    }

    $placeholders = implode(',', array_fill(0, count($rbls), '?'));
    $types = str_repeat('s', count($rbls));

    $stmt = $con->prepare("
        SELECT DISTINCT h.DIVA AS station_diva
        FROM ogd_steige s
        JOIN ogd_haltestellen h ON s.FK_HALTESTELLEN_ID = h.HALTESTELLEN_ID
        WHERE s.RBL IN ($placeholders)
        AND h.DIVA IS NOT NULL AND h.DIVA <> ''
        ORDER BY h.DIVA
    ");
    $stmt->bind_param($types, ...$rbls);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rows)) {
        echo "  SKIP (no mapping found): {$fav['title']} ({$fav['diva']})\n";
        $skipped++;
        continue;
    }

    $newDiva = implode(',', array_column($rows, 'station_diva'));
    $upd = $con->prepare('UPDATE wl_favorites SET diva = ? WHERE id = ?');
    $upd->bind_param('si', $newDiva, $fav['id']);
    $upd->execute();
    $upd->close();
    echo "  Updated: {$fav['title']} — {$fav['diva']} → $newDiva\n";
    $updated++;
}
echo "Favorites: $updated migrated, $skipped skipped\n";

// --- Step 3: recreate ogd_stations view -------------------------------------
$con->query('DROP VIEW IF EXISTS ogd_stations');
$con->query("CREATE SQL SECURITY INVOKER VIEW ogd_stations AS
    SELECT
        h.HALTESTELLEN_ID,
        h.NAME AS Haltestelle,
        h.DIVA AS diva,
        GROUP_CONCAT(DISTINCT l.BEZEICHNUNG ORDER BY l.BEZEICHNUNG ASC SEPARATOR ',') AS Linien,
        h.WGS84_LAT AS LAT,
        h.WGS84_LON AS LON
    FROM ogd_steige s
    JOIN ogd_linien l ON s.FK_LINIEN_ID = l.LINIEN_ID
    JOIN ogd_haltestellen h ON s.FK_HALTESTELLEN_ID = h.HALTESTELLEN_ID
    WHERE h.DIVA IS NOT NULL AND h.DIVA <> ''
    GROUP BY h.HALTESTELLEN_ID, h.NAME, h.DIVA, h.WGS84_LAT, h.WGS84_LON");
if ($con->error) {
    echo "ERROR recreating view: " . $con->error . "\n";
    exit(1);
}
$n = $con->query('SELECT COUNT(*) AS n FROM ogd_stations')->fetch_assoc()['n'];
echo "ogd_stations view recreated — $n rows\n";

// Recreate ogd_diva view (uses ogd_steige.RBL, renamed from DIVA)
$con->query('DROP VIEW IF EXISTS ogd_diva');
$con->query("CREATE SQL SECURITY INVOKER VIEW ogd_diva AS
    SELECT s.RBL AS rbl, h.NAME AS station,
        GROUP_CONCAT(DISTINCT l.BEZEICHNUNG ORDER BY l.BEZEICHNUNG ASC SEPARATOR ',') AS `lines`,
        s.STEIG_WGS84_LAT AS LAT, s.STEIG_WGS84_LON AS LON
    FROM ogd_steige s
    JOIN ogd_linien l ON s.FK_LINIEN_ID = l.LINIEN_ID
    JOIN ogd_haltestellen h ON s.FK_HALTESTELLEN_ID = h.HALTESTELLEN_ID
    WHERE s.RBL IS NOT NULL AND s.RBL <> ''
    GROUP BY s.RBL");
if ($con->error) {
    echo "ERROR recreating ogd_diva view: " . $con->error . "\n";
    exit(1);
}
$n2 = $con->query('SELECT COUNT(*) AS n FROM ogd_diva')->fetch_assoc()['n'];
echo "ogd_diva view recreated — $n2 rows\n";
echo "Done.\n";
