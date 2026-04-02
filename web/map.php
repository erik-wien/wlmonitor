<!DOCTYPE html>
<html lang="de">
<head>
	<title>Test Map API</title>
</head>

<body>


<div id="map-canvas" style="background-color: #ddd; min-height: 800px; min-width: 600px;"></div>



<script src="https://maps.googleapis.com/maps/api/js?v=3.exp&sensor=false"></script>

<script>
var geocoder;
var map;
var marker;
var marker2;

function initialize() {
    geocoder = new google.maps.Geocoder();

    var latlng = new google.maps.LatLng(-34.397, 150.644);

    var mapOptions = {
        zoom: 5,
        center: latlng
    }

    map = new google.maps.Map(document.getElementById('map-canvas'), mapOptions);

    google.maps.event.addListener(map, 'click', 
		function (event) {
			alert(event.latLng);          
			geocoder.geocode(
				{'latLng': event.latLng}, 
				function (results, status) {
					if (status == google.maps.GeocoderStatus.OK) {
						if (results[0]) {
							alert(results[1].formatted_address);
						} else {
							alert('No results found');
						}
					} else {
						alert('Geocoder failed due to: ' + status);
					}
				}
			);
		}
	); <!--click event--> 
	
}

    google.maps.event.addDomListener(window, 'load', initialize);
	
</script>

</body>
</html>