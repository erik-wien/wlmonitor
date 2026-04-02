<?php
/**
 * GetStarions.php
 *
 * Produces a json file with stations
 * 
 * supporting code for index.php
 * 
 *
 * The json stream is processed into an html snipped and sent back.
 *
 * 
 * PHP version 7.2
 *
 * LICENSE: This source file is subject to version 3.01 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_01.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   geo-information
 * @package    wl-monitor
 * @author     Erik R. Huemer <erik.huemer@jardyx.com>
 * @copyright  2019 Erik R. Huemer
 * @license    http://www.php.net/license/3_01.txt  PHP License 3.01
 * @version    SVN: $Id$
 * @link       https://www.jardyx.com/wl-monitor/download/wl-monitor.zip
 * @see        https://www.jardyx.com/wl-monitor/
 * @since      File available since Release 1.2.0
 * @deprecated not depreciated
 */


require_once(__DIR__ . '/../include/initialize.php');

header("Content-Type: application/json; charset=UTF-8");

if (isset($_GET["lat"]) && isset($_GET["lon"])) {
    $lat = (float) $_GET["lat"];
    $lon = (float) $_GET["lon"];
    $sql = "SELECT s.Haltestelle AS station, FLOOR(ST_Distance_Sphere(point(s.LAT,s.LON),point(?,?))/30)*30 AS distance, rbls, s.lat, s.lon FROM `ogd_stations` AS s ORDER BY distance, station LIMIT 100;";

} else {
    $sql = "SELECT s.Haltestelle AS station, rbls, s.lat, s.lon FROM `ogd_stations` AS s ORDER BY station;";

}

$stmt = $con->prepare($sql);

if (isset($_GET["lat"]) && isset($_GET["lon"])) {
   $stmt->bind_param("dd", $lat, $lon);
}
$stmt->execute();

if ($stmt->error) {
    appendLog('auth', 'SQL (getStations): ' . $stmt->error , 'web');
    $_SESSION['Error'] = "SQL Fehler in getStations.php: " . $stmt->error;
    echo "SQL ERROR: " . $sql . $stmt->error . "\n";
}

$result = $stmt->get_result();
$num_rows = $result->num_rows;

if ($num_rows > 0) {
    $stations = array();
    while ($row = $result->fetch_assoc()) {
        $stations[] = array(
            'station' => $row["station"] ,
            'distance' => ($row["distance"] ?? 0),
            'rbls' => $row["rbls"],
            'lat' => $row["lat"],
            'lon' => $row["lon"]
        );
    }
    echo json_encode($stations);
}
else {
    echo "SQL: " . $sql . "\n";
    echo $num_rows . " stations found.";
}

$stmt->close();
$con->close();

?>
