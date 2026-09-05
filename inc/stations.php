<?php
/**
 * inc/stations.php
 *
 * Station list queries against the ogd_stations view.
 *
 * ogd_stations is a MariaDB view that joins ogd_haltestellen + ogd_steige +
 * ogd_linien and exposes one row per physical station with its DIVA number
 * (station-level, 8-digit), name, coordinates, and served lines.
 *
 * DIVA vs RBL distinction
 * ───────────────────────
 * - DIVA  (ogd_haltestellen.DIVA)  : station-level 8-digit identifier.
 *                                     This is what the WL Realtime API accepts.
 * - RBL   (ogd_steige.RBL)         : stop-level 4-digit identifier (one per
 *                                     direction/platform).  Not used by the app.
 */

/**
 * Return up to 100 stations nearest to a geographic coordinate.
 *
 * Uses MySQL's ST_Distance_Sphere() for accurate great-circle distance.
 * Results are rounded to the nearest 30 m increment to avoid false precision
 * and sorted by distance, then alphabetically within the same bracket.
 *
 * @param mysqli $con Active database connection.
 * @param float  $lat Latitude  in decimal degrees (range −90 … +90).
 * @param float  $lon Longitude in decimal degrees (range −180 … +180).
 * @return array[]    Rows: ['station', 'distance' (m), 'diva', 'lat', 'lon']
 */
function stations_by_distance(mysqli $con, float $lat, float $lon): array {
    $sql = "SELECT s.Haltestelle AS station,
                   FLOOR(ST_Distance_Sphere(point(s.LON, s.LAT), point(?, ?)) / 30) * 30 AS distance,
                   s.diva, s.Linien AS `lines`, s.LAT AS lat, s.LON AS lon,
                   GROUP_CONCAT(DISTINCT st.RICHTUNG ORDER BY st.RICHTUNG SEPARATOR '') AS directions
            FROM ogd_stations AS s
            JOIN ogd_steige st ON st.DIVA = s.diva
            GROUP BY s.diva, s.Haltestelle, s.Linien, s.LAT, s.LON
            ORDER BY distance, station
            LIMIT 100";
    $stmt = $con->prepare($sql);
    // Note: ST_Distance_Sphere takes (longitude, latitude) — arguments are intentionally swapped.
    $stmt->bind_param('dd', $lon, $lat);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'station'    => $row['station'],
            'distance'   => (int) ($row['distance'] ?? 0),
            'diva'       => $row['diva'],
            'lines'      => $row['lines'] ?? '',
            'directions' => $row['directions'] ?? '',
            'lat'        => $row['lat'],
            'lon'        => $row['lon'],
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * Return all stations in alphabetical order.
 *
 * Used for the default A–Z station list.  No LIMIT — the full ~4 000 row
 * dataset is returned; the client filters in JavaScript.
 *
 * @param mysqli $con Active database connection.
 * @return array[]    Rows: ['station', 'diva', 'lat', 'lon']
 */
function stations_alpha(mysqli $con): array {
    $result = $con->query(
        "SELECT s.diva, s.Haltestelle AS station, s.Linien AS `lines`, s.LAT AS lat, s.LON AS lon,
                GROUP_CONCAT(DISTINCT st.RICHTUNG ORDER BY st.RICHTUNG SEPARATOR '') AS directions
         FROM ogd_stations AS s
         JOIN ogd_steige st ON st.DIVA = s.diva
         GROUP BY s.diva, s.Haltestelle, s.Linien, s.LAT, s.LON
         ORDER BY station"
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'station'    => $row['station'],
            'diva'       => $row['diva'],
            'lines'      => $row['lines'] ?? '',
            'directions' => $row['directions'] ?? '',
            'lat'        => $row['lat'],
            'lon'        => $row['lon'],
        ];
    }
    return $rows;
}

// diva_info() wanderte 2026-09-05 nach erikr/wl-client (src/stations.php):
// sie liest die ogd_*-Referenzdaten der Wiener Linien, die seither auch das
// Display-Projekt braucht. Die uebrigen Funktionen hier sind reine
// Web-Oberflaeche (Umkreissuche, alphabetische Liste, Positionsspeicher) und
// bleiben.


/**
 * Store the user's current geographic position in the session.
 *
 * Used to remember the last known location so the "Nähe" sort can be
 * re-applied across page refreshes without re-requesting geolocation.
 *
 * @param mysqli $con Active database connection (used for logging only).
 * @param float  $lat Latitude  in decimal degrees.
 * @param float  $lon Longitude in decimal degrees.
 */
function stations_save_position(mysqli $con, float $lat, float $lon): void {
    $_SESSION['lat'] = $lat;
    $_SESSION['lon'] = $lon;
    appendLog($con, 'pos', "Position saved: $lat, $lon");
}
