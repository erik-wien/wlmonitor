<?php
/**

 * watch.php
 *
 * derivated from WL Monitor; shows monitor optimized for extremely small screens like apple watch 
 *
 *
 * Dependencies:
 *		- Bootstrap V.4.3.1
 *		- Fontawesome V.5.7.0
 *		- Google Fonts: Roboto, Roboto Mono, Share Tech Mono
 *		- jQuery V.3.3.1
 *		- Geodys V.2.0.0 curtesy of Chris Veness
 *		- Wiener Linien ogd_realtime monitor
 *			Datenquelle: Stadt Wien – data.wien.gv.at
 *		- wl.json / Wiener Lienien Generator by Patrick "hactar" Wolowicz
 *
 *
 * Sitting on the shoulders of Matthias Bendel who inspired me with his project WL-Monitor-Pi.
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
 * @see		https://www.jardyx.com/wl-monitor/
 * @since	  File available since Release 1.2.0
 * @deprecated not depreciated
 */

?>

<?php
	require_once(__DIR__ . '/../include/initialize.php');
	header('Content-Type: text/html; charset=utf-8');

	$_SESSION['rbl'] = 1718;


?>

<!DOCTYPE html>
<html lang="de">
<head>
	<title>Wiener Linien Abfahrtsmonitor</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="viewport" content="width=200, initial-scale=1.5, user-scalable: yes">
	<meta http-equiv="X-UA-Compatible" content="ie=edge">

	<meta name="application-name" content="WL Monitor">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-title" content="WL Monitor">
	<meta name="theme-color" content="#000000">
	<meta name="apple-mobile-web-app-status-bar-style" content="#000000">
	<meta name="msapplication-config" content="img/browserconfig.xml?v=191025092929">
	<link rel="apple-touch-icon" sizes="57x57" href="img/apple-touch-icon-57x57.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="60x60" href="img/apple-touch-icon-60x60.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="72x72" href="img/apple-touch-icon-72x72.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="76x76" href="img/apple-touch-icon-76x76.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="114x114" href="img/apple-touch-icon-114x114.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="120x120" href="img/apple-touch-icon-120x120.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="144x144" href="img/apple-touch-icon-144x144.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="152x152" href="img/apple-touch-icon-152x152.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="180x180" href="img/apple-touch-icon-180x180.png?v=191025092929">
	<link rel="icon" type="image/png" href="img/favicon-16x16.png?v=191025092929" sizes="16x16">
	<link rel="icon" type="image/png" href="img/favicon-32x32.png?v=191025092929" sizes="32x32">
	<link rel="icon" type="image/png" href="img/favicon-96x96.png?v=191025092929" sizes="96x96">
	<link rel="shortcut icon" type="image/x-icon" href="img/favicon.ico?v=191025092929">
	<link href="img/apple-touch-startup-image-320x460.png?v=191025092929" media="(device-width: 320px) and (device-height: 480px) and (-webkit-device-pixel-ratio: 1)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-640x920.png?v=191025092929" media="(device-width: 320px) and (device-height: 480px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-640x1096.png?v=191025092929" media="(device-width: 320px) and (device-height: 568px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-748x1024.png?v=191025092929" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 1) and (orientation: landscape)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-750x1024.png?v=191025092929" media="" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-750x1294.png?v=191025092929" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-768x1004.png?v=191025092929" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 1) and (orientation: portrait)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-1182x2208.png?v=191025092929" media="(device-width: 414px) and (device-height: 736px) and (-webkit-device-pixel-ratio: 3) and (orientation: landscape)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-1242x2148.png?v=191025092929" media="(device-width: 414px) and (device-height: 736px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-1496x2048.png?v=191025092929" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2) and (orientation: landscape)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-1536x2008.png?v=191025092929" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" rel="apple-touch-startup-image">
	<link rel="manifest" href="img/manifest.json?v=191025092929" />

	<!-- Font Awesome  -->
	<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.0/css/all.css" integrity="sha384-lZN37f5QGtY3VHgisS14W3ExzMWZxybE1SJSEsQp9S+oqd12jhcu+A56Ebc1zFSJ" crossorigin="anonymous">
	
	<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto|Roboto+Mono|Share+Tech+Mono">
	
	<!-- Default CSS -->
	<link rel="stylesheet" id="bootstrapCss" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
	<link rel="stylesheet" href="js/xpull/xpull.css">
	<link rel="stylesheet" href="style/wl-monitor.css">

	<style>
		.list-group-item{
			padding: 3px !important;
		} 
	#towards {
		display: inline-block;
		font-size: 0.92rem;
		width: 160px;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
		}
	</style>
</head>

<body style="padding: 0; margin: 0;">

	<!-- Body -->
	
	<!-- Reload -->
	<div class="xpull">
		<div class="xpull__start-msg">
			<p class="xpull__start-msg-text" style="color:#bbb;">Zum Aktualisieren nach unten ziehen &amp; los lassen. </p>
			<div class="xpull__arrow"></div>
		</div>
		<div class="xpull__spinner">
			<div class="xpull__spinner-circle"></div>
		</div>
	</div>
	
	
	<!-- Monitor -->

	<div id="monitor"><i class="fas fa-spinner fa-spin"></i></div>
		


	<!-- Footer -->
	<div class="text-muted mt-5">
		<div class="col-sm-12 col-md-5 ml-auto text-center small">
			Version 2.1.2 20.09.2023 &copy; 2023 by Erik R. Huemer 
		</div>

		<div class="col-sm-12 col-md-5 mr-auto text-center">
			<small>Datenquelle: Stadt Wien – data.wien.gv.at</small>
		</div>
	</div>
	

	<!-- Scripts 	-->
	<script>
		let userName = <?= json_encode($_SESSION['username'] ?? '') ?>;
		let userID = <?= json_encode($_SESSION['id'] ?? 1) ?>;
		userLoggedin = 0;
		let debug = 0;
		let rbl = 1718;
	</script>
	
	<!-- jQuery library -->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>

	<!-- Popper JS -->
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>

	<!-- bootstrap -->
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>

	<!-- JQuery Plugin zum Validieren von Formularen -->
	<script src="https://ajax.aspnetcdn.com/ajax/jquery.validate/1.11.1/jquery.validate.min.js"></script>
	
	<!-- Rubberband Refresh -->
	<script src="js/xpull/xpull.js"></script>

	<script src="js/wl-monitor.js"></script>

	<script>

	// 	As soon as the document is ready loading and rendered, get the monitor and the buttons
	// =======================================================================================
		$(document).ready(function(){

			// Get monitors, favourites and station list
			// =========================================================
			if (GetURLParameter	('rbl'))	{rbl = GetURLParameter('rbl')	} 
			else						{rbl = checkCookie();		}

			// load monitor
			getMonitor(rbl);


			// Initialise UI
			// ================================

			
		}); // end $(document).ready 



		// Functions
		// =================================
		
		// set new cookie for the station number rbl and reload the monitor
		function changeMonitor(rbl, id) {
		
			if (rbl == undefined) {rbl=1718}
			if (id == undefined) {id=1}
			
			if(debug){console.log("Change rbl to " + rbl + ' for ' + id)};
			
			// reset automatic monitor refresh
			if (document.cookie.indexOf("monitorTimerID=") >= 0) {
				var monitorTimerID = getCookie("monitorTimerID");
				clearInterval(monitorTimerID);
			}
			// clearing all intervals
			var interval_id = window.setInterval("", 9999); // Get a reference to the last
															// interval +1
			for (var i = 1; i < interval_id; i++)
				window.clearInterval(i);
			
			monitorTimerID = setInterval(function(){reloadMonitor();},20000);
			setCookie("rbl", rbl, 3);
			setCookie("monitorTimerID", monitorTimerID, 3);
				
			getMonitor(rbl, id);
			
		}


		function getMonitor(rbl, id) {
			// you can call index with a rbl parameter; but there are problems getting rid of this parameter afterwards …
// 			if ((rbl==undefined) && (GetURLParameter('rbl') != ""))	{rbl = GetURLParameter('rbl'); if(debug){console.log("URL: Get Monitor for rbl " + rbl)};}
			if (rbl == undefined) 										{rbl = getCookie("rbl"); if(debug){console.log("Cookie_: Get Monitor for rbl " + rbl)};}
			if (rbl == undefined) 										{rbl = 4111; if(debug){console.log("Default: Get Monitor for rbl " + rbl)};}
			
			if (id == undefined) 										{id = userID}
			var apiurl = 'monitor.php?rbl='+rbl+'&id='+id;
			
			// Loading Spinner
			$("#monitor").html('<div class="spinner"><span class="spinner-border spinner-border-sm"></span> Abfahrtsdaten werden geladen ...</div>');
			
			// get readymade html from php script
			$("#monitor").load(apiurl);
			
		}

		function reloadMonitor() {
			
			var apiurl = 'monitor.php?id='+userID;
			
			// get readymade html from php script
			$("#monitor").load(apiurl);
			
		}

	</script>
	
</body>
</html>





