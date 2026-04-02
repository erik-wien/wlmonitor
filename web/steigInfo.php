<?php
/**
 * steigInfo.php
 * 
 * Shows all Infos to a specific rbl number
 *
 *
 * Dependencies:
 *		- see index.php
 *
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
 * @package	wl-monitor
 * @author	 Erik R. Huemer <erik.huemer@jardyx.com>
 * @copyright  2019 Erik R. Huemer
 * @license	http://www.php.net/license/3_01.txt  PHP License 3.01
 * @version	SVN: $Id$
 * @link	   https://www.jardyx.com/wl-monitor/download/wl-monitor.zip
 * @see		https://www.jardyx.com/wlmonitor/
 * @since	  File available since Release 1.2.0
 * @deprecated not depreciated
 */


	$scriptPath = '/home/.sites/765/site679/web/jardyx.com/wlmonitor/';
	include_once($scriptPath . 'sessionStatus.php');
	
	
	date_default_timezone_set('Europe/Vienna');
	
	
	require_once(__DIR__ . '/../include/initialize.php');
	
	if (isset($_GET["rbl"])) {
		$rbl = $_GET["rbl"];
	} else {
		$rbl = 4111;
		$_SESSION['Error'] = "Fehler: Keine rbl übermittelt.";
	}

	$stmt = $con->prepare("SELECT r.lines FROM `ogd_RBL-Nummern` AS r WHERE rbl=? LIMIT 1");
	$stmt->bind_param("s", $rbl);
	$stmt->execute();
	if ($stmt->error) {
		appendLog('auth', 'SQL (getStations): ' . $stmt->error , 'web');
		$_SESSION['Error'] = "SQL Fehler: " . $stmt->error;
	}
	$stmt->bind_result($lines);
	
	$result = $stmt->fetch();
	
	header('Content-Type: text/html; charset=utf-8');
	
?><!DOCTYPE html>
<html lang="de">
<head>
	<title>Abfahrtsmonitor Info Einstiegsstelle</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta http-equiv="X-UA-Compatible" content="ie=edge">
	
	<!-- Font Awesome  -->	
	<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.0/css/all.css" integrity="sha384-lZN37f5QGtY3VHgisS14W3ExzMWZxybE1SJSEsQp9S+oqd12jhcu+A56Ebc1zFSJ" crossorigin="anonymous">
	<!--script src="https://kit.fontawesome.com/175f395c88.js" crossorigin="anonymous"></script-->
	
	<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto|Roboto+Mono|Share+Tech+Mono">

	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
	
	<link rel="stylesheet" href="style/dark/bootstrap.css">
	<link rel="stylesheet" href="style/wl-monitor.css">
	
	
</head>

<body>
	
	<!--
	Alerts
	-------------------------------------------------------------------------
	-->

	<div id="alerts"></div>
	
	
	<!--
	Body
	------------------------------------------------------------------------- -->
	<div class="container">
		<div class="row" >
			<div class="col-12 col-sm-12 col-md-10 mx-auto" id="stationInfo">
				
				<h4><?=$station ?></h4>
				<p>Hier hält: <?=$lines ?></p>
			</div>
		</div>
		
		<div class="row" >
			<div class="col-12 col-sm-12 col-md-5 ml-auto" id="stationMonitor">loading ...</div> 
			<div class="col-12 col-sm-12 col-md-5 mr-auto" id="googleMap">
				<a class="text-body" target="wlmonitor" href='https://www.google.com/maps/dir/?api=1&origin=<? echo($_SESSION["lat"] . "," . $_SESSION["lon"]) ?>&destination=<? echo($lat . "," . $lon); ?>&travelmode=walking'><i class="fas fa-location-arrow mr-3"></i> Wegbeschreibung</a>
			</div> 
		</div>
		
	</div>
	
		<!--
		Footer
		====================================================================== -->
		<div class="footer fixed-bottom pb-2">
			<div class="col-12 col-sm-12 col-md-5 ml-auto text-center">
				<small>Version 2.0 13.11.2019 &copy; 2019 by Erik R. Huemer </small>
			</div>
			
			<div class="col-12 col-sm-12 col-md-5 mr-auto text-center">
				<small>Datenquelle: Stadt Wien – data.wien.gv.at</small>
			</div>
		</div>
	
	<!-- Helper Elements 
	=========================================================================	-->
	
	<!-- Go Back Top Button-->
	<button onclick="topFunction()" id="topBtn" title="Go to top" class="btn btn-danger"><span class="fas fa-arrow-alt-circle-up"></span></button>
	
	
	<!--
	Debug Information
	-->
	<pre id="debug">
	
	</pre>
	

	<!-- 
	+----------------------------------
	|
	|  Scripts 
	|
	+----------------------------------
	-->
	
	<!-- jQuery library -->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
	
	<!-- Popper JS -->
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
	
	<!-- Latest compiled JavaScript -->
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
	
	
	<script src="js/wl-monitor.js"></script>


	<script>		
	
	// 	As soon as the document is ready loaded and rendered, get the monitor and the buttons
	// =======================================================================================
		
		$(document).ready(function(){
			
			
			<?php

			
			echo "var rbl = '" . $rbl . "';\n";
			
			if (isset($_SESSION['loggedin'])) {
				echo "var userName = '" . $_SESSION['username'] . "';\n";
				echo "var userID = " . $_SESSION['id'] . ";\n";
				echo "var userLoggedin = 1;\n";
				echo "var debug = 0" . $_SESSION['debug'] . ";\n";
			} else {
				
				$_SESSION['debug'] = FALSE;
				echo "var userName = '';\n";
				echo "var userID = 1;\n";
				echo "var userLoggedin = 0;\n";
				echo "var debug = 0" . $_SESSION['debug'] . ";\n";
			}
			?>
			
			
			// Initialize Tooltips	
			// ------------------------------------------------------			
			$('[data-toggle="tooltip"]').tooltip(); 
			
			
			
			// Render Monitor
			getMonitor(rbl);
			
<?php			
			// Check for alerts 
			// ----------------------------------------------------------------------------------------
			if (isset($_SESSION['Error'])) {
				echo('$("#alerts").append(\'<div class="alert alert-danger alert-dismissible fade show">'.$_SESSION['Error'] . ' <button type="button" class="close" data-dismiss="alert">&times;</button></div>\');');
				unset($_SESSION['Error']);
			}
		
			if (isset($_SESSION['Success'])) {
				echo('$("#alerts").append(\'<div class="alert alert-success alert-dismissible fade show">'.$_SESSION['Success'] . ' <button type="button" class="close" data-dismiss="alert">&times;</button></div>\');');
				unset($_SESSION['Success']);
			}
		
			if (isset($_SESSION['Notice'])) {
				echo('$("#alerts").append(\'<div class="alert alert-secondary alert-dismissible fade show">'.$_SESSION['Notice'] . ' <button type="button" class="close" data-dismiss="alert">&times;</button></div>\');');
				unset($_SESSION['Notice']);
			}
		
			if (($_SESSION['invalidLogins']>0) && isset($_SESSION['loggedin']) ) {
				echo('$("#alerts").append(\'<div class="alert alert-warning alert-dismissible"><b>Warnung!</b> Es gab '.$_SESSION['invalidLogins'] . ' ungültige Loginversuche. <button type="button" class="close" data-dismiss="alert">&times;</button></div>\');');
				$_SESSION['invalidLogins']=0;
			} 
			
			echo "$('.alert').delay(10000).slideUp(200, function() {
				$(this).alert('close');
				});";
			
			//output session if in debug mode 
			if ($_SESSION['debug']) {
				$arr = get_defined_vars();
				$out  = "<p id='debugHeader'><b>Debug</b></p>\n";
				$out .= "<pre id='debugBody'>\n _SESSION ";
				$out .= print_r($arr, true);
				$out .= "</pre>";
				
				echo "\nvar debugOut = ` \n";
				echo $out;
				echo "`;\n";
				
				echo "$('#debug').append(debugOut);\n";
								
				echo "$('#debugBody').append('Cookies ');\n";
				echo "$('#debugBody').append(listCookies());\n";
				
			}
			?>
			
		}); <!-- end $(document).ready-->

		
		// Functions
		// =================================


		
		function getMonitor(rbl) {

			var apiurl = 'monitor.php?rbl='+rbl;
			
			// Loading Spinner
			// $("#monitor").html('<div class="spinner"><span class="spinner-border spinner-border-sm"></span> Abfahrtsdaten werden geladen ...</div>');
			
			// get readymade html from php script			
			$("#stationMonitor").load(apiurl);
			
		}
		
		

		
	</script>
	
	
	<?php
		$stmt->close();
	?>
	
</body>
</html>

	