<?php
/**
 * checkFavorite.php
 * 
 * Check provided station numbers in favorits table.
 * 
 * supporting code for index.php
 *
 * On every Monitor there is a button behind the stations name.
 *
 * If the user has favored this station, this script finds it in the wl-favorits-table and generates a delete button,
 * which removes the favorite from the favorots table.
 *
 * If the station is not found, an "Add favorit"-Button is generated, which adds it to the table.
 *
 * Stations are identified by their rbl-numbers, which is technicalle not correct, but pragmatically favorable.
 * In this way users will be able to cumulate any Line/direction combinations under one Station name.
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

	// Let'S check if the user is logged in. If not, simply abort.
	if ( !isset($_SESSION['loggedin']) ) {
		// Could not get the data that should have been sent.
		die();
	}

	$colorClasses = array("btn-outline-info","btn-outline-primary","btn-outline-success","btn-outline-warning","btn-outline-danger","btn-outline-dark","btn-outline-secondary");
	
	if (!isset($_GET['rbls'])){die('Check Favorites: No rbls provided.');};
	$rbls = $_GET['rbls'];

	if (!isset($_GET['stationName'])){die('Check Favorites: No station name provided.');};
	$stationName = $_GET['stationName'];	
	
	$sql  = "SELECT id ";
	$sql .= "FROM wl_favorites ";
	$sql .= "WHERE idUser=? and rbls=? ";
	$stmt = $con->prepare($sql);

	$stmt->bind_param('ii', $_SESSION['id'], $rbls);
	$stmt->execute();
	$stmt->bind_result($idStation);
	$stmt->store_result();

	if ($stmt->num_rows > 0) {
		$stmt->fetch();

		$out = "";
		$out = <<<html
		<button class="btn btn-outline-primary ml-1 btn-xs" type="button" id="deleteFavorite" ><i id="addFavoriteContent" class="far fa-trash-alt"></i></button>
				<script>
					$("#deleteFavorite").click(function(){
						$.ajax({url: "deleteFavorite.php?id=$idStation", 
						success: function(result) { location.reload(); }
						});
					});
				</script>
html;
		echo $out;
		
	} else {
		foreach($colorClasses as $colorClass) {
			$out = "";
			$out = <<<html
				<button class="btn $colorClass ml-1 btn-xs" type="button" id="addFavorite"><i id="addFavoriteContent" class="far fa-star"></i></button>
					<script>
						$("#addFavorite.$colorClass").click(function(){
							$.ajax({url: "addFavorite.php?title=$stationName&rbls=$rbls&bclass=$colorClass", 
							success: function(result) { location.reload(); }
							});
						});
					</script>
html;
			echo $out;
		}
	}

	$stmt->close();


