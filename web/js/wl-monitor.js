/**
 * wl-monitor.js
 * 
 * some tools for wl-monitor
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
 * @see        https://www.jardyx.com/wlmonitor/
 * @since      File available since Release 1.2.0
 * @deprecated not depreciated
 */
 
 
// When the user scrolls down 20px from the top of the document, show the button
window.onscroll = function() {scrollFunction()};

function scrollFunction() {
	if (document.body.scrollTop > 20 || document.documentElement.scrollTop > 20) {
		document.getElementById("topBtn").style.display = "block";
	} else {
		document.getElementById("topBtn").style.display = "none";
	}
}

// When the user clicks on the button, scroll to the top of the document
function topFunction() {
	document.body.scrollTop = 0; // For Safari
	document.documentElement.scrollTop = 0; // For Chrome, Firefox, IE and Opera
}

function GetURLParameter(sParam) {
	
	var sPageURL = window.location.search.substring(1);
	var sURLVariables = sPageURL.split('&');
	
	for (var i = 0; i < sURLVariables.length; i++) {
		
		var sParameterName = sURLVariables[i].split('=');
		
		if (sParameterName[0] == sParam) {
			return sParameterName[1];
		} 
	}
}



// Cookies
function setCookie(cname, cvalue, exdays) {
	var acceptCookies = getCookie('acceptCookies');
	var d = new Date();

	d.setTime(d.getTime());
	var updated =  d.toUTCString();

	d.setTime(d.getTime() + (exdays*24*60*60*24*10));
	var expires = "expires="+ d.toUTCString();

	if (acceptCookies == "true" || cname == 'acceptCookies') {
		document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
		document.cookie = "updated =" + updated + ";" + expires + ";path=/";

		if (debug) { console.log("Cookie "+cname+" set to " + cvalue)};
	} else {
		console.log("Cookie ["+cname+"] can not set to " + cvalue + ". Status of acceptCookies is '" + acceptCookies + "'");
	}

}


function getCookie(cname) {
	var name = cname + "=";
	var decodedCookie = decodeURIComponent(document.cookie);
	var ca = decodedCookie.split(';');
	for(var i = 0; i <ca.length; i++) {
		var c = ca[i];
		while (c.charAt(0) == ' ') {
			c = c.substring(1);
		}
		if (c.indexOf(name) == 0) {
			cvalue = c.substring(name.length, c.length);
			if(debug){console.log("Cookie "+cname+" found to be " + cvalue)};
			return cvalue;
		}
	}
	return "";
}


function checkCookie() {
var rbl = getCookie("rbl");

if (rbl != "") {
	if(debug){console.log("Welcome back!")};
	setCookie("rbl", rbl, 3);
} else {
	if(debug){console.log("First time you're here?")};
	rbl=4111;
	setCookie("rbl", rbl, 3);
}

return rbl;
}

function removeCookies() {
	var aString = '';
	var res = document.cookie;
	var multiple = res.split("; ");
	for(var i = 0; i < multiple.length; i++) {
		var key = multiple[i].split("=");
		document.cookie = key[0]+" =; expires = Thu, 01 Jan 1970 00:00:00 UTC" + ";path=/";
		aString += i + ': ' + key[0] + " deleted?\n";
	 }
	 return aString;
}

function listCookies() {
	 var theCookies = document.cookie.split('; ');
	 var aString = '';
	 for (var i = 1; i <= theCookies.length; i++) {
	 	var key = theCookies[i-1].split("=");
	 	if(key[0] == 'PHPSESSID') {
	 		aString += i + ': ' + key[0] + " = ********** \n";
	 	} else {
	 		aString += i + ': ' + key[0] + ' = ' + key[1] + "\n";
	 	}
	 }
	return aString;
}



//  Full Screen 
function toggleFullScreen() {
	var doc = window.document;
	var docEl = doc.documentElement;
	
	var requestFullScreen = docEl.requestFullscreen || docEl.mozRequestFullScreen || docEl.webkitRequestFullScreen || docEl.msRequestFullscreen;
	var cancelFullScreen = doc.exitFullscreen || doc.mozCancelFullScreen || doc.webkitExitFullscreen || doc.msExitFullscreen;
	
	if(!doc.fullscreenElement && !doc.mozFullScreenElement && !doc.webkitFullscreenElement && !doc.msFullscreenElement) {
		requestFullScreen.call(docEl);
	}
	else {
		cancelFullScreen.call(doc);
	}
}



function sendAlert(message,color ) {
	$("#alerts").append('<div class="alert alert-' + color + ' alert-dismissable animated fadeIn"><button type="button" class="close" data-dismiss="alert">&times;</button>' + message + '</div>').delay(5000).animate({opacity:0, height:0},600, function() {$(this).alert('close');});
}




		// outdatedBrowsers
		// ------------------------------------------------------
		function addLoadEvent(func) {
			var oldonload = window.onload;
			if (typeof window.onload != 'function') {
				window.onload = func;
			} else {
				window.onload = function() {
					if (oldonload) {
						oldonload();
					}
					func();
				}
			}
		}
		
		
		// Function to toggle the Themes
		function changeTheme() {
			selectedTheme = $("input[name='themePreference']:checked").val();

			if (selectedTheme == "auto") {

				if (window.matchMedia && window.matchMedia('(prefers-color-scheme:dark)').matches) {
					console.log("Automatically Switching to Dark Mode.");
					$('#bootstrapCss').attr("href", "style/bootstrap-darkly.css");

				} else {
					console.log("Automatically Switching to Light Mode.");
					$('#bootstrapCss').attr("href", "/bootstrap/dist/css/custom/light.css");
				}

			} else {
				console.log("Switching CSS from " + $('#bootstrapCss').attr("href"));
				
				if (selectedTheme == "light") {
					$('#bootstrapCss').attr("href", "/bootstrap/dist/css/custom/light.css");
				
				} else {
					$('#bootstrapCss').attr("href", "style/bootstrap-darkly.css");
				}
				console.log("to " + $('#bootstrapCss').attr("href"));
			}
			return selectedTheme;
		}
		
			
		// set new cookie for the station number rbl and reload the monitor
		function changeMonitor(rbl, id) {

			if (rbl == undefined) {rbl=4111}
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
			// $("#monitor").html('<div class="spinner"><span class="spinner-border spinner-border-sm"></span> Abfahrtsdaten werden geladen ...</div>');

			// get readymade html from php script
			$("#monitor").load(apiurl);

		}

		function reloadMonitor() {

			var apiurl = 'monitor.php?id='+userID;

			// get readymade html from php script
			$("#monitor").load(apiurl);

		}



		function addButton(title, rbl, bclass){
			$("#buttons").append('<button onclick="changeMonitor(\''+rbl+'\');" type="button" class="btn '+bclass+' btn-block">'+title+'</button>');
		}


		function getFavorites() {

			// delete current list of buttons
			if(debug){console.log("Deleting buttons...")};
			$("#buttons").html("");

			// Fetch favourite stations from database (readymade html)
			if(debug){console.log("Fetching buttons from database...")};

			// try to load buttons from database

			$.ajax({
				async: false,
				cache: false,
				url : "getFavorites.php",
				timeout: 2000,
				success: function (data) {
					$("#buttons").html(data);
				},
				error: function (xhr,status,error) {
					if (debug)	{console.log('Error loading Favorites: '+status +': '+ error)};
					$('#buttons').append('<div class="alarm alarm-danger">Fehler: '+status +': '+ error +'</div>');
				}
			});

			// build favourite buttons from local array
			/* if(debug){console.log("Rendering favourite buttons...")};
			var i;
			for (i in jsonButtons) {
				addButton( jsonButtons[i].title, jsonButtons[i].rbl, jsonButtons[i].bclass);
			}
			*/
			if(debug){console.log("Done favourite buttons.")};


			// 	Activate Save Function after re-sorting
			$("#btnSaveFavorites").click(function(){

				if(debug){console.log('Loading favorites order...')};

				var listItems = $("#buttons button");
				var i = 0;
				var sortArray = "[";

				listItems.each(function(idx, button) {
					i++;
					var htmlBtnID = $(button).attr('id');
					var btnID = htmlBtnID.split("-");
					if (i > 1) sortArray += ", ";
					sortArray += "('sort':" + i + ",'id':" + btnID[1] + ")";
				});
				sortArray += "]";

				// send sql
				if(debug){console.log("Trying to save Favorites.")};
				if(debug){console.log(sortArray)};

				$.ajax({
					type:"POST",
					cache:false,
					url:"saveFavorites.php",
					data:{sortArray: sortArray},	// multiple data sent using ajax
					timeout: 2000,
					success: (function (html) {
						$('#frmSaveFavorites').prepend(html);
						if(debug){console.log("Favorites saved.")};
					}),
					error:( function (xhr,status,error) {
						if(debug){console.log('Error saving Favorites: '+status +': '+ error)};
						$('#frmSaveFavorites').prepend('<div class="alarm alarm-danger">Fehler: '+status +': '+ error +'</div>');
					})
				})
			});


		}


		// if geo location can't be retrieved, fill search filter alpabetically
		function positionError(error) {
			console.warn('ERROR (' + error.code + '): ' + error.message);

			switch(error.code) {
				case error.PERMISSION_DENIED:
					sendAlert("User denied the request for Geolocation.", "warning");
					break;
				case error.POSITION_UNAVAILABLE:
					sendAlert("Location information is unavailable.", "warning");
					break;
				case error.TIMEOUT:
					sendAlert("The request to get user location timed out.", "warning");
					break;
				case error.UNKNOWN_ERROR:
					sendAlert("An unknown error occurred.", "warning");
					break;
			}

			getStationsAlpha();
		}


		function getStationsDist(myPosition) {

			$("#stationSortDist").removeClass("d-none");
			$("#stationFilter").attr("placeholder", "Stationen in der Nähe suchen");

			console.log("Position acquired: " + myPosition.coords.latitude + ", " + myPosition.coords.longitude);
			$.post("savePosition.php", {lat:myPosition.coords.latitude, lon:myPosition.coords.longitude});

			sendAlert("Ihre Position: <? echo ($_SESSION['lat'] . ", " . $_SESSION['lon'] ); ?>", "secondary");

			$.getJSON("getStations.php",
				{ "lat":myPosition.coords.latitude, "lon":myPosition.coords.longitude },
				function(responseTxt, statusTxt, xhr){

					if (statusTxt == "success") {
						console.log("Data transferred.");

						$("#stationList").html("");

						$.each(responseTxt, function(i, row){
							dist = row.distance
							if (dist > 1000) {
								dist = parseFloat(dist/1000).toFixed(2) + ' km';
							} else {
								dist = parseFloat(dist).toFixed(0) + ' m';
							}
							var li = $('<li>');
							var p = $('<p class="mb-xs-1 mb-md-0">');
							var mapsUrl = 'https://www.google.com/maps/dir/?api=1&origin=' + encodeURIComponent(myPosition.coords.latitude + ',' + myPosition.coords.longitude) + '&destination=' + encodeURIComponent(row.lat + ',' + row.lon) + '&travelmode=walking';
							var a = $('<a target="wlmonitor">').attr('href', mapsUrl).append('<i class="fas fa-location-arrow mr-3"></i>');
							var span = $('<span>').text(row.station).on('click', (function(rbls){ return function(){ changeMonitor(rbls, userID); }; })(row.rbls));
							p.append(a).append(' ').append(span).append(' (' + dist + ') ');
							li.append(p);
							$("#stationList").append(li);
						});
					}

					$("#stationSortDist").addClass("d-none");
					if (statusTxt == "error")
						console.log("Error: " + xhr.status + ": " + xhr.statusText);
				}


			);
		}

		function getStationsAlpha() {
			$("#stationFilter").attr("placeholder", "Stationen A-Z suchen");

			$("#stationSortAlpha").removeClass("d-none");

			$.getJSON("getStations.php",
				function(responseTxt, statusTxt, xhr){

					if (statusTxt == "success") {
						console.log("Data transferred.");

						$("#stationList").html("");

						$.each(responseTxt, function(i, row){
							var li = $('<li>');
							var p = $('<p class="mb-xs-1 mb-md-0">').text(row.station).on('click', (function(rbls){ return function(){ changeMonitor(rbls, userID); }; })(row.rbls));
							li.append(p);
							$("#stationList").append(li);
						});
					}

					$("#stationSortAlpha").addClass("d-none");

					if (statusTxt == "error")
						console.log("Error: " + xhr.status + ": " + xhr.statusText);
				}
			);
		}
