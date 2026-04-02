<?php
/**
 * monitor.php
 *
 * Produces the board of departure times
 * 
 * supporting code for index.php
 *
 * Central part of WL Monitor
 *
 * The cordial point of the project are the stopID-Numbers. 
 *
 * stopID numbers can be requested online from [WL stopID Search](https://till.mabe.at/rbl/)
 *
 * Every stopID number is connected to a line (like underground U1, Bus 37A or tram D) and
 * the direction it travels.
 * 
 * Therefor stations can be connected with more than one stopID-Numer, since the can be more than on line  
 * departing from that station going to one ore two directions.
 *
 * This script takes the provided stopID-Number(s) and calls the API of Wiener Linien.
 * The answer is a json stream with station and departure informations.
 * Also some incident informations can be provided.
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
 * @category	geo-information
 * @package		wl-monitor
 * @author		Erik R. Huemer <erik.huemer@jardyx.com>
 * @copyright	2019 Erik R. Huemer
 * @license		http://www.php.net/license/3_01.txt  PHP License 3.01
 * @version		SVN: $Id$
 * @link		https://www.jardyx.com/wl-monitor/download/wl-monitor.zip
 * @see			https://www.jardyx.com/wl-monitor/
 * @since		File available since Release 1.2.0
 * @deprecated	not depreciated
 **/
 
 
// Initialize
// --------------------------------------
 	require_once(__DIR__ . '/../include/initialize.php');

// set maximum Numbers of Departures!
		$maxDepartures = isset($_SESSION['departures']) ? $_SESSION['departures'] : MAX_DEPARTURES;


// Get RBL Numbers
// there can be more than one rbl-numbers; e.g. "4634,4635""
	
	// Station in URL?
	if(isset($_GET["rbl"])) {
		$rblRAW = sanitizeRblInput($_GET["rbl"]);
		$_SESSION['rbl'] = $rblRAW;
		logDebug('mon', "New Monitor: " . $rblRAW);
	} 
		
	// Fulltext-Search for Station?
	elseif(isset($_GET["s"])) 
	{
		function makeSearchStringSafe($searchString, $dbConnection) 
		{
			// Remove leading and trailing whitespace
			$searchString = trim($searchString);
		
			// Use mysqli_real_escape_string to escape special characters
			$searchString = mysqli_real_escape_string($dbConnection, $searchString);
		
			return $searchString;
		}

	
		$searchString = "%" . makeSearchStringSafe($_GET["s"], $con) . "%";
		
		try {
			global $con;
					
			$stmt = $con->prepare("SELECT GROUP_CONCAT(DISTINCT rbls ORDER BY rbls DESC SEPARATOR ',') as rblss FROM `ogd_stations` WHERE Haltestelle LIKE ? LIMIT 10");
			$stmt->bind_param("s", $searchString);
			
			if (!$stmt->execute()) {
				logDebug('mon', 'SQL (r.lines): ' . $stmt->error);
				$_SESSION['Error'] = "SQL Error: " . $stmt->error;
			} else {
				$stmt->bind_result($rblRAW);
				$stmt->fetch();
			}
			
			$stmt->close();
			
			$_SESSION['rbl'] = $rblRAW;
			logDebug('mon', "New Monitor: " . $rblRAW);

		}
		catch (Exception $e) {
			logDebug('mon', 'Exception (r.lines ' . $monitor[$monitorNum]["rbl"] . '): ' . $e->getMessage() . '\n');
		}				
		
	}
	
	// Station in SESSION?
	elseif (isset($_SESSION['rbl'])) {
		$rblRAW = $_SESSION['rbl'];
		logDebug('mon', "Reload Monitor: " . $rblRAW);
	} 
	
	// No Station from User; using default Station
	else {
		$rblRAW = "4111";
		addAlert ("secondary", "Wählen Sie eine Station aus dem Suchfeld oder den Favoriten");

	}
	


/* ---------------------------------------
 * Get the Data from the API
 * ---------------------------------------
*/
	
	function fetchMonitorData($rblRAW, $maxDepartures) {
		$apiurl = 'https://www.wienerlinien.at/ogd_realtime/monitor?rbl=' . str_replace(",", "&rbl=", $rblRAW) . '&sender=' . APIKEY . '&activateTrafficInfo=stoerungkurz&activateTrafficInfo=stoerunglang';
	
		$RAWcontent = file_get_contents($apiurl);
	
		if ($RAWcontent === FALSE) {
			throw new Exception('API request failed');
		}
	
		return $RAWcontent;
	}
	
	function handleApiError($message) {
		logDebug('mon', $message . "\n");
		addAlert('danger', $message);
		echo "<pre>Keine Abfahrsdaten gefunden. \n" . $message . "</pre>";
	}
	
	function handleProcessError($message) {
		logDebug('mon', $message . "\n");
		addAlert('danger', $message);
		echo "<pre>Fehler beim Verarbeiten der Daten. \n" . $message . "</pre>";
	}
	
	function handleOutputMonitorError($message) {
		logDebug('mon', $message . "\n");
		addAlert('danger', $message);
		echo "<pre>Fehler beim Anzeigen der Daten. \n" . $message . "</pre>";
	}
	

/* ---------------------------------------
 * Convert API Data to Monitor Data
 * ---------------------------------------
*/
	function processMonitorData($RAWcontent, $maxDepartures, $rblRAW) 
	// Decoding and fetching required content from the API response
	// Processing the monitor data
	// The extracted part will not change the logic of the code, but would enhance readability
	{

		$JsonContent 	= $JsonMessage 	= $JsonMonitors = $monitor = array();

		$JsonContent 	= json_decode($RAWcontent, true);
		$JsonMessage 	= $JsonContent["message"];

		$JsonMonitors 	= $JsonContent["data"]["monitors"];
		

		$MontorCount = count($JsonMonitors);

		if ($MontorCount = 0) {
			throw new Exception('No Monitors found.');
		}
	
		foreach($JsonMonitors as $monitorNum => $haltestelle){
			$monitor[$monitorNum]["title"]  = $haltestelle["locationStop"]["properties"]["title"];
			$monitor[$monitorNum]["rbl"]	= $haltestelle["locationStop"]["properties"]["attributes"]["rbl"];
			$monitor[$monitorNum]["len"]	= $haltestelle["locationStop"]["geometry"]["coordinates"]["0"];
			$monitor[$monitorNum]["lon"]	= $haltestelle["locationStop"]["geometry"]["coordinates"]["1"];
			
			
			foreach($haltestelle["lines"] as $lineNum => $line){
				$monitor[$monitorNum]["lines"][$lineNum]["platform"] = $line["platform"];
				$monitor[$monitorNum]["lines"][$lineNum]["towards"] = $line["towards"];
				$monitor[$monitorNum]["lines"][$lineNum]["direction"] = $line["direction"];
				$monitor[$monitorNum]["lines"][$lineNum]["name"] = $line["name"];
				$monitor[$monitorNum]["lines"][$lineNum]["type"] = $line["type"];
				$monitor[$monitorNum]["lines"][$lineNum]["barrierFree"] = $line["barrierFree"];
				$monitor[$monitorNum]["lines"][$lineNum]["trafficjam"] = $line["trafficjam"];

				// design of line symbol
				$monitor[$monitorNum]["lines"][$lineNum]["typeClass"] = "line line-" . $line["type"];
				if ($line["type"]=="ptMetro") {$monitor[$monitorNum]["lines"][$lineNum]["typeClass"] .= ' line-' . $line["type"] . "-" . $line["name"];}

				foreach($line["departures"]["departure"] as $departureNum => $departure){

					$monitor[$monitorNum]["lines"][$lineNum]["departures"][$departureNum]["time"] = $departure["departureTime"]["timePlanned"];

					// Calculate delay
					$timePlanned = $departure["departureTime"]["timeReal"] ?? $departure["departureTime"]["timePlanned"];
					$monitor[$monitorNum]["lines"][$lineNum]["departures"][$departureNum]["delay"] = strtotime($timePlanned) - strtotime( $departure["departureTime"]["timePlanned"]);

					$monitor[$monitorNum]["lines"][$lineNum]["departures"][$departureNum]["countdown"] = $departure["departureTime"]["countdown"];
					$monitor[$monitorNum]["lines"][$lineNum]["departures"][$departureNum]["color"] = ($departure["departureTime"]["countdown"] < 4) ? "danger" : "secondary";

				}
				
				try {
					global $con;
					
					$stmt = $con->prepare("SELECT r.lines FROM `ogd_RBL-Nummern` AS r WHERE rbl=? LIMIT 10");
					$stmt->bind_param("s", $monitor[$monitorNum]["rbl"]);
					
					if (!$stmt->execute()) {
						logDebug('mon', 'SQL (r.lines): ' . $stmt->error);
						$_SESSION['Error'] = "SQL Error: " . $stmt->error;
					} else {
						$stmt->bind_result($stationLinesss);
						$stmt->fetch();
					}
					
					$stmt->close();
				}
				catch (Exception $e) {
					logDebug('mon', 'Exception (r.lines ' . $monitor[$monitorNum]["rbl"] . '): ' . $e->getMessage() . '\n');
					$stationLinesss ="ubk.";
				}				
				
				$monitor[$monitorNum]["line"] = $stationLinesss;
			}
			
		}

		usort($monitor, function ($item1, $item2) {
			return $item1['rbl'] <=> $item2['rbl'];
		});
		
		return $monitor;
		
	}


/* ---------------------------------------
 * Convert Monitor Data to html
 * ---------------------------------------
*/
	function outputMonitorData($monitors, $rblRAW, $maxDepartures) 
	// Output HTML with data
	{

		$vorHaltestelle = "";
		$vorRbl = "";


		// Check if Monitor is in Favourites and output html for deleting or adding favourites
		function checkFavorites($stationRbls, $stationName)
		{
			global $con;
			$out = "";

			$sql  = "SELECT id FROM wl_favorites WHERE idUser=? and rbls=? ";
			$stmt = $con->prepare($sql);
			$stmt->bind_param('is', $_SESSION['id'], $stationRbls);
			$stmt->execute();
			
			$stmt->bind_result($idStation);
			$stmt->store_result();
		
			// Station is a favorite, show bin
			if ($stmt->num_rows > 0) {
				$stmt->fetch();
		
				$out .= <<<html
				Favorit löschen 
				<button class="btn btn-outline-danger ml-1 btn-xs" type="button" id="deleteFavorite" >
					<img src="img/icons/trash-xs-red.svg" id="addFavoriteContent" >
				</button>
				<script>
					$("#deleteFavorite").click(function(){
						$.ajax({url: "deleteFavorite.php?id=$idStation", 
						success: function(result) { location.reload(); }
						});
					});
				</script>
html;

			} 
			
			// Station is not a favorite, show buttons to add a favorite
			else {
				
				$colorClasses = array("btn-outline-info","btn-outline-primary","btn-outline-success","btn-outline-warning","btn-outline-danger","btn-outline-dark","btn-outline-secondary");

				$out .= "Als Favorit hinzufügen";

				foreach($colorClasses as $colorClass) {
					$out .= <<<html
						<button class="btn $colorClass ml-1 btn-xs" type="button" title="$colorClass" id="addFavorite$colorClass" onclick="adf('$stationName', '$stationRbls', '$colorClass')">
							<img src="img/icons/plus-xs-grey.svg" id="addFavoriteContent" >
						</button>
html;

				} // endforeach
			} // endif Station is a favorite
		
			$out = "<span id='favHandler'>" . $out . "</span>";
		
			return $out;
		
		} // end function checkFavorites
	
		
		function stationHeader($stationName, $rblRAW, $stationRbl, $stationLine, $stationLon, $stationLen)
		{
	
			$stationName = trim($stationName);				
			
			$out = <<<html
				<h5 class="mt-3" id="station$stationRbl" data-toggle="tooltip" title="rbl: $stationRbl" data-placement="bottom"> 
					
					
					<a href="?rbl=$stationRbl"> $stationName </a>
					
					<span class="text-small text-secondary font-weight-normal">
						($stationLine)
					</span>
					
					<a class="btn btn-outline-primary ml-1 btn-xs" href="https://www.google.at/maps/@$stationLon,$stationLen,19z" target="map">
						<img src="img/icons/locate-fixed-xs-blue.svg">
					</a>
				</h5>
html;

			return $out;
	
		
		} // end function StationHeader
	
	
	
		function lineDepartures($lines, $stationRbl, $maxDepartures)
		{
			$vorRbl = $stationRbl;

			$out = "<ul class='list-group departure border-primary mb-3' >";
									
			foreach($lines as $line) 
			{
			
				$out .= "
				<li class='list-group-item d-flex justify-content-between' style='padding: 12px 1px !important;'><div style='display: flex; align-items: center; '>
					<span class='mr-1 " . $line["typeClass"] . "'>" . $line["name"] . "</span> 
						
					<span class='towards'>" . $line["towards"] . " </span>";
						
				$out .= $line["trafficjam"]  == true ? " &nbsp; <img src='img/icons/alert-triangle-xs-bunt.svg' > " : "";
				$out .= $line["barrierFree"] == true ? " &nbsp; <img src='img/icons/accessibility-xs-grey.svg' > " : "";
				   
				$departureCount = 0;
				
				$out .= "</div><div style='display: flex; flex-direction: row; align-items: center; '>";
				
				foreach($line["departures"] as $departure) 
				{

					// show departure countdown, "*" if countdown = 0
					$out .= "<span class='badge badge-" . $departure['color'] . " m-1 p-1 float-right border ";

					// Show tooltip only if there is a delay
					if (abs($departure["delay"]) > 60) 
					{
						$out .= "border-dark' data-toggle='tooltip' data-placement='top' title='";

						$out .= ($departure["delay"] < 0 ) ? "-" : "+";

						$out .= date('i:s', abs($departure["delay"]));
					}

					else 
					{
						$out .= "border-" . $departure['color'] . " ";
					}

					$out .= "'>";

					$out .= ($departure["countdown"]>0) ? $departure["countdown"] : "<i class='fas fa-star-of-life'></i>";
					$out .= "</span>";

					$departureCount ++;
					if ($departureCount >= $maxDepartures) break;
				}
				$out .= "<div></li>";
			}
					 
			$out .= "</ul>";
		
			return $out;
		}
		
		
		// icons to add or delete favourites
		$favButtons = checkFavorites($rblRAW, ($monitors[0]["title"] ?? "") ?? "Favoriten kaputt");
		echo $favButtons;
		
			
		// Loop trough stations and departures
		$vorHaltestelle = "";
		foreach($monitors as $haltestelle) 
		{
			if ( ($vorHaltestelle != $haltestelle["title"]) ) {
				$vorHaltestelle = $haltestelle["title"];
				
				echo stationHeader($haltestelle["title"], $rblRAW, $haltestelle["rbl"], $haltestelle['line'], $haltestelle["lon"], $haltestelle["len"]);
				
			}
	
			if ($vorRbl != $haltestelle["rbl"]) {
			
				$vorRbl = $haltestelle["rbl"];
				echo lineDepartures($haltestelle["lines"], $haltestelle["rbl"], $maxDepartures);
			}
		}
		
	} // endof outputMonitorData()
	
	

	// MAIN
	// =====================================
	try {
		$RAWcontent = fetchMonitorData($rblRAW, $maxDepartures);
			
	} catch (Exception $e) {
		handleApiError($e);
		$RAWcontent = 'Wiener Linien API is temporarily not available. <br/> <br/> Try again!';
		die($RAWcontent);
	}
			$monitors = processMonitorData($RAWcontent, $maxDepartures, $rblRAW);

	try {
				
	} catch (Exception $e) {
		handleProcessError($e);
		$RAWcontent = 'Fehler beim Verarbeiten der Daten. <br/> <br/> Try again!';
		die($RAWcontent);
	}
	
try {
		outputMonitorData($monitors, $rblRAW, $maxDepartures);
				
	} catch (Exception $e) {
		handleOutputMonitorError($e);
		$RAWcontent = 'Fehler beim Anzeigen. <br/> <br/> Try again!';
		die($RAWcontent);
	}
	
	
	
	
	
	
	
	
