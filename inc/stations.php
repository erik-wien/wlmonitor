<?php
// inc/stations.php
// Station list from ogd_stations table

function stations_by_distance(mysqli $con, float $lat, float $lon): array {
    $sql = "SELECT s.Haltestelle AS station,
                   FLOOR(ST_Distance_Sphere(point(s.LAT, s.LON), point(?, ?)) / 30) * 30 AS distance,
                   s.diva, s.lat, s.lon
            FROM ogd_stations AS s
            ORDER BY distance, station
            LIMIT 100";
    $stmt = $con->prepare($sql);
    $stmt->bind_param('dd', $lat, $lon);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'station'  => $row['station'],
            'distance' => (int) ($row['distance'] ?? 0),
            'diva'     => $row['diva'],
            'lat'      => $row['lat'],
            'lon'      => $row['lon'],
        ];
    }
    $stmt->close();
    return $rows;
}

function stations_alpha(mysqli $con): array {
    $result = $con->query(
        "SELECT s.Haltestelle AS station, s.diva, s.lat, s.lon FROM ogd_stations AS s ORDER BY station"
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'station' => $row['station'],
            'diva'    => $row['diva'],
            'lat'     => $row['lat'],
            'lon'     => $row['lon'],
        ];
    }
    return $rows;
}

function stations_save_position(mysqli $con, float $lat, float $lon): void {
    $_SESSION['lat'] = $lat;
    $_SESSION['lon'] = $lon;
    appendLog($con, 'pos', "Position saved: $lat, $lon", 'web');
}
