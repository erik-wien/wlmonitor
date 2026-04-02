<?php
/**
 * monitor_json.php
 *
 * Produces the board of departure times in json
 * 
 *
 * The cordial point of the project are the stopID-Numbers. 
 *
 * stopID numbers can be requested online from [WL stopID Search](https://till.mabe.at/diva/)
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


// Get diva Numbers
// there can be more than one diva-numbers; e.g. "4634,4635""
	
	// Station in URL?
	if(isset($_GET["diva"])) {
		$divaRAW = sanitizeRblInput($_GET["diva"]);
		$_SESSION['diva'] = $divaRAW;
		logDebug('mon', "New JSON Monitor: " . $divaRAW);
	} 
		

	// Station in SESSION?
	elseif (isset($_SESSION['diva'])) {
		$divaRAW = $_SESSION['diva'];
		logDebug('mon', "Reload JSON Monitor: " . $divaRAW);
	} 
	
	// No Station from User; using default Station
	else {
		$divaRAW = "60200103";
	}
	


/* ---------------------------------------
 * Get the Data from the API
 * ---------------------------------------
*/
	
	function fetchMonitorData($divaRAW, $maxDepartures) {
		$apiurl = 'https://www.wienerlinien.at/ogd_realtime/monitor?diva=' . str_replace(",", "&diva=", $divaRAW) . '&sender=' . APIKEY . '&activateTrafficInfo=stoerungkurz&activateTrafficInfo=stoerunglang';
	
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
function processMonitorData($rawContent, $maxDepartures, $divaRaw) {
    // Initialize arrays
    $jsonContent = json_decode($rawContent, true);
    $jsonMonitors = $jsonContent["data"]["monitors"];
    $jsonStatus = $jsonContent["message"];

    // Check if monitors are found
    $monitorCount = count($jsonMonitors);
    if ($monitorCount === 0) {
        throw new Exception('No Monitors found.');
    }

    // Initialize result array
    $monitorData = [];

    $stationIdPrevious = -1;

    // Processing monitor data
    foreach ($jsonMonitors as $monitor) {

        // Extracting station details
        $stationName = $monitor["locationStop"]["properties"]["title"];
        $stationId = $monitor["locationStop"]["properties"]["name"];

        if ($stationId != $stationIdPrevious) {

            $stationIdPrevious = $stationId;
            // Initialize train counter
            $trainCount = 0;
 
            // Initialize station data
            $stationData = [
            "id" => $stationId,
            "station_name" => $stationName
            ];
        }

        // Processing trains
        foreach ($monitor["lines"] as $line) {
            $trainName = $line["name"] . " → " . $line["towards"];

            // Debug Trains
            // echo "<pre>" . $trainName . "</pre>\n";

            // Initialize departure string for each train
            $stationData["train_" . $trainCount] = $trainName;
            $stationData["train_" . $trainCount] = $trainName;
            $stationData["platform_" . $trainCount] = $line["platform"];
            $stationData["departure_" . $trainCount] = '';

            // Processing departures
            $departureCount = 1;
            foreach ($line["departures"]["departure"] as $departure) {
                // Add departure time to the corresponding train
                if ($departureCount <= 3) {
                    if (!empty($stationData["departure_" . $trainCount])) {
                        $stationData["departure_" . $trainCount] .= ", ";
                    }
                    $departureTime = $departure["departureTime"]["countdown"];
                    if ($departureTime == 0) $departureTime = "*";
                    $stationData["departure_" . $trainCount] .= $departureTime;
                    $departureCount++;
                }
            }

            // Increment train counter
            $trainCount++;
        }

        // Store station data
        $monitorData[$stationId] = $stationData;
    }

	    $monitorData["trains"] = $trainCount;


	    $monitorData["update_at"] = date_format(date_create($jsonStatus["serverTime"]), "H:i:s");

        $monitorData["api_ping"] =  strtotime($jsonStatus["serverTime"])- time();
        

    return $monitorData;
}





	

	// MAIN
	// =====================================
	try {
		$RAWcontent = fetchMonitorData($divaRAW, $maxDepartures);
			
	} catch (Exception $e) {
		handleApiError($e);
		$RAWcontent = 'Wiener Linien API is temporarily not available. <br/> <br/> Try again!';
		die($RAWcontent);
	}
			$monitors = processMonitorData($RAWcontent, $maxDepartures, $divaRAW);

	try {
				
	} catch (Exception $e) {
		handleProcessError($e);
		$RAWcontent = 'Fehler beim Verarbeiten der Daten. <br/> <br/> Try again!';
		die($RAWcontent);
	}
	
try {
        $json_string = json_encode($monitors, JSON_PRETTY_PRINT);
    	header('Content-Type: application/json; charset=utf-8');
        echo ($json_string);
				
	} catch (Exception $e) {
		handleOutputMonitorError($e);
		$RAWcontent = 'Fehler beim Anzeigen. <br/> <br/> Try again!';
		die($RAWcontent);
	}
	
	


	
	
	
	
