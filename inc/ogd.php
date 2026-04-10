<?php
// inc/ogd.php
// OGD station data update: download WL CSVs, reload DB tables, recreate views.

define('OGD_LOCK_FILE',    __DIR__ . '/../data/ogd_update.lock');
define('OGD_LOCK_TIMEOUT', 300); // seconds before a stale lock is ignored

define('OGD_CSV_URLS', [
    'haltestellen' => 'https://data.wien.gv.at/csv/wienerlinien-ogd-haltestellen.csv',
    'steige'       => 'https://data.wien.gv.at/csv/wienerlinien-ogd-steige.csv',
    'linien'       => 'https://data.wien.gv.at/csv/wienerlinien-ogd-linien.csv',
]);

/**
 * Try to acquire the update lock.
 * Returns true on success, false if another update is already running.
 */
function ogd_lock_acquire(): bool {
    $fp = fopen(OGD_LOCK_FILE, 'c');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    ftruncate($fp, 0);
    fwrite($fp, json_encode(['pid' => getmypid(), 'started_at' => time()]));
    fflush($fp);
    // Keep the file handle open for the duration of the run so the lock holds.
    // Store it in a global so ogd_lock_release() can close it.
    $GLOBALS['_ogd_lock_fp'] = $fp;
    return true;
}

function ogd_lock_release(): void {
    $fp = $GLOBALS['_ogd_lock_fp'] ?? null;
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
        unset($GLOBALS['_ogd_lock_fp']);
    }
    @unlink(OGD_LOCK_FILE);
}

/**
 * Download a CSV and parse it into an array of associative rows.
 */
function ogd_download_csv(string $url): array {
    $ctx = stream_context_create(['http' => ['timeout' => 30]]);
    $raw = file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException("CSV download failed: $url");
    }
    $lines  = explode("\n", $raw);
    $header = str_getcsv(array_shift($lines), ';', '"', '');
    $rows   = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $rows[] = array_combine($header, str_getcsv($line, ';', '"', ''));
    }
    return $rows;
}

/**
 * Run the full OGD update.
 *
 * Returns an array:
 *   ['ok' => bool, 'log' => string[], 'error' => string|null]
 */
function ogd_update(mysqli $con): array {
    $log = [];
    $out = function (string $msg) use (&$log) { $log[] = $msg; };

    if (!ogd_lock_acquire()) {
        return ['ok' => false, 'log' => [], 'error' => 'Update already in progress.'];
    }

    try {
        $out('Downloading CSV files...');
        $haltestellen = ogd_download_csv(OGD_CSV_URLS['haltestellen']);
        $out('  haltestellen: ' . count($haltestellen) . ' rows');
        $steige = ogd_download_csv(OGD_CSV_URLS['steige']);
        $out('  steige: ' . count($steige) . ' rows');
        $linien = ogd_download_csv(OGD_CSV_URLS['linien']);
        $out('  linien: ' . count($linien) . ' rows');

        $con->begin_transaction();
        try {
            // --- ogd_linien ---
            $con->query('DELETE FROM ogd_linien');
            $stmt = $con->prepare(
                'INSERT INTO ogd_linien (LINIEN_ID, BEZEICHNUNG, REIHENFOLGE, ECHTZEIT, VERKEHRSMITTEL, STAND)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($linien as $r) {
                $stmt->bind_param(
                    'isiiss',
                    $r['LINIEN_ID'], $r['BEZEICHNUNG'], $r['REIHENFOLGE'],
                    $r['ECHTZEIT'],  $r['VERKEHRSMITTEL'], $r['STAND']
                );
                $stmt->execute();
            }
            $stmt->close();
            $out('  ogd_linien loaded.');

            // --- ogd_haltestellen ---
            $con->query('DELETE FROM ogd_haltestellen');
            $stmt = $con->prepare(
                'INSERT INTO ogd_haltestellen
                     (HALTESTELLEN_ID, TYP, DIVA, NAME, GEMEINDE, GEMEINDE_ID, WGS84_LAT, WGS84_LON, STAND)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($haltestellen as $r) {
                $stand = $r['STAND'] ?: null;
                $stmt->bind_param(
                    'isissidds',
                    $r['HALTESTELLEN_ID'], $r['TYP'],     $r['DIVA'],
                    $r['NAME'],           $r['GEMEINDE'], $r['GEMEINDE_ID'],
                    $r['WGS84_LAT'],      $r['WGS84_LON'], $stand
                );
                $stmt->execute();
            }
            $stmt->close();
            $out('  ogd_haltestellen loaded.');

            // --- ogd_steige ---
            $con->query('DELETE FROM ogd_steige');
            $stmt = $con->prepare(
                'INSERT INTO ogd_steige
                     (STEIG_ID, FK_LINIEN_ID, FK_HALTESTELLEN_ID, RICHTUNG, REIHENFOLGE,
                      RBL, BEREICH, STEIG, STEIG_WGS84_LAT, STEIG_WGS84_LON, STAND)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($steige as $r) {
                $rbl = $r['RBL_NUMMER'];
                $stmt->bind_param(
                    'iiisisssdds',
                    $r['STEIG_ID'],        $r['FK_LINIEN_ID'], $r['FK_HALTESTELLEN_ID'],
                    $r['RICHTUNG'],        $r['REIHENFOLGE'],  $rbl,
                    $r['BEREICH'],         $r['STEIG'],
                    $r['STEIG_WGS84_LAT'], $r['STEIG_WGS84_LON'], $r['STAND']
                );
                $stmt->execute();
            }
            $stmt->close();

            // Populate DIVA from ogd_haltestellen via FK
            $con->query(
                'UPDATE ogd_steige s
                 JOIN ogd_haltestellen h ON s.FK_HALTESTELLEN_ID = h.HALTESTELLEN_ID
                 SET s.DIVA = h.DIVA'
            );
            if ($con->error) {
                throw new RuntimeException('ogd_steige.DIVA update failed: ' . $con->error);
            }
            $out('  ogd_steige loaded (RBL + DIVA populated).');

            $con->commit();

        } catch (Throwable $e) {
            $con->rollback();
            throw $e;
        }

        // Recreate views outside the transaction (DDL causes implicit commit anyway)
        $con->query('DROP VIEW IF EXISTS ogd_stations');
        $con->query("CREATE SQL SECURITY INVOKER VIEW ogd_stations AS
            SELECT h.HALTESTELLEN_ID, h.NAME AS Haltestelle, h.DIVA AS diva,
                GROUP_CONCAT(DISTINCT l.BEZEICHNUNG ORDER BY l.BEZEICHNUNG ASC SEPARATOR ',') AS Linien,
                h.WGS84_LAT AS LAT, h.WGS84_LON AS LON
            FROM ogd_steige s
            JOIN ogd_linien l ON s.FK_LINIEN_ID = l.LINIEN_ID
            JOIN ogd_haltestellen h ON s.FK_HALTESTELLEN_ID = h.HALTESTELLEN_ID
            WHERE h.DIVA IS NOT NULL AND h.DIVA <> ''
            GROUP BY h.HALTESTELLEN_ID, h.NAME, h.DIVA, h.WGS84_LAT, h.WGS84_LON");
        if ($con->error) {
            throw new RuntimeException('ogd_stations view failed: ' . $con->error);
        }

        $nStations = (int) $con->query('SELECT COUNT(*) AS n FROM ogd_stations')->fetch_assoc()['n'];
        $out("View recreated: ogd_stations=$nStations");
        $out('Done.');

        return ['ok' => true, 'log' => $log, 'error' => null];

    } catch (Throwable $e) {
        $out('ERROR: ' . $e->getMessage());
        return ['ok' => false, 'log' => $log, 'error' => $e->getMessage()];
    } finally {
        ogd_lock_release();
    }
}
