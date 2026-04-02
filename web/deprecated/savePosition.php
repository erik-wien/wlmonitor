<?php

require_once(__DIR__ . '/../include/initialize.php');

if (isset($_POST["lat"]) &&  isset($_POST["lon"]) ) {
	
	$_SESSION["lat"] = $_POST["lat"];
	$_SESSION["lon"] = $_POST["lon"];
	
	appendLog('geo', 'Position saved to Session.', 'web');
} else {

	addAlert ('Error', 'Programmfehler: savePosition.php');
	appendLog('geo', 'Position data empty. Position could not be saved.', 'web');
}







?>