<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wiener Linien Abfahrtsmonitor</title>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
</head>
<body>
    <p><strong>Orientation:</strong> <span id="orientation"></span></p>
    <p><strong>Size:</strong> <span id="size"></span></p>
    <p><strong>Available Size:</strong> <span id="asize"></span></p>
    <p><strong>Corrected Size:</strong> <span id="csize"></span></p>
    <p><strong>Inner Size:</strong> <span id="isize"></span></p>

    <script>
        function updateScreenSize() {
            const screenSize = `${window.screen.width} x ${window.screen.height}`;
            $("#size").text(screenSize);

            const correctedSize = `${window.screen.width * window.devicePixelRatio} x ${window.screen.height * window.devicePixelRatio} ratio: ${window.devicePixelRatio}`;
            $("#csize").text(correctedSize);

            const availSize = `${window.screen.availWidth} x ${window.screen.availHeight}`;
            $("#asize").text(availSize);

            const innerSize = `${window.innerWidth} x ${window.innerHeight}`;
            $("#isize").text(innerSize);
        }

        $(document).ready(function() {
            // Initial update
            updateScreenSize();

            // Add an event listener for screen orientation changes
            window.addEventListener('resize', updateScreenSize);

            // Display the orientation
            $("#orientation").text(window.orientation);

            // Add an event listener for orientation changes
            window.addEventListener('orientationchange', function() {
                $("#orientation").text(window.orientation);
            });
        });
    </script>
</body>
</html>

<?php
	require_once(__DIR__ . '/../include/initialize.php');
	
	$arr = get_defined_vars();

	$out  = "<p><b>Debug</b></p>\n";
	$out .= "<pre id='debugBody'>\n Defined Vars ";
	$out .= print_r($arr, true);
	$out .= "</pre>";
	echo $out;

?>

</body>
</html>