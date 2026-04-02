<?php
/**
 * store new password
 * 
 * 
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
session_start();
require_once(__DIR__ . '/../include/initialize.php');
	
appendLog('edf', 'Edit favourite.', 'web');

?>
<html lang="de">
<head>
  <title>Favorit bearbeiten - WL Monitor</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet"
        href="https://use.fontawesome.com/releases/v5.7.0/css/all.css"
        integrity="sha384-lZN37f5QGtY3VHgisS14W3ExzMWZxybE1SJSEsQp9S+oqd12jhcu+A56Ebc1zFSJ"
        crossorigin="anonymous">
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="style/wl-monitor.css">
</head>

<body>
	
	<!--
	Alerts
	-------------------------------------------------------------------------
	 -->

<div class="container shadow border my-3">

    <div class="jumbotron px-0 py-md-4 mb-0 bg-white " >
        <h2>Favoriten Editor</h2>
    </div>

    <div class="row" >
        <div class="col-md-12" >
	        <div id="alerts"></div>
        </div>
    </div>

    <div class="row " >
        <div class="col-md-12" >

<?php

if (isset( $_POST['favID']) ) {
    if (!csrf_verify()) {
        $_SESSION['Error'] = 'Ungultige Anfrage.';
        header('Location: index.php'); exit;
    }

	// Let's check if the data was submitted, isset() function will check if the data exists.
	if (!isset($_POST['title'], $_POST['rbls'], $_POST['bclass'], $_POST['favID'])) {
		// Could not get the data that should have been sent.
		$_SESSION["Error"] = 'Bitte füllen Sie das Formular vollständig aus!!';
		appendLog('edf', 'Unsuccessful: Fields missing.', 'web');
		header('Location: index.php'); exit;
	}
	
	
	// Make sure the submitted registration values are not empty.
	if (empty($_POST['title']) || empty($_POST['rbls']) || empty($_POST['bclass']) || empty($_POST['favID'])) {
		// One or more values are empty.
		$_SESSION["Error"] = 'Bitte füllen Sie das Formular vollständig aus!!';
		appendLog('edf', 'Unsuccessful: Fields empty.', 'web');
		header('Location: index.php'); exit;
	}

	 $favID =  $_POST['favID'];
	 $userID = $_SESSION['id'];
	 $title =  $_POST['title'];
	 $rbls =   $_POST['rbls'];
	 $bclass = $_POST['bclass'];
	 $sort = $_POST['sort'];

	// check if favourite exists.
	if ($stmt = $con->prepare('SELECT id FROM wl_favorites WHERE idUser = ? AND id = ?')) {
		// Bind parameters (s = string, i = int, b = blob, etc), in our case the username is a string so we use "s"
		$stmt->bind_param('ii', $userID, $favID);
		$stmt->execute();
		
		$stmt->store_result();
		if ($stmt->num_rows > 0) {

				if ($stmt = $con->prepare('UPDATE wl_favorites SET title = ?, rbls = ?, bclass = ?, sort = ? WHERE id = ?')) {
					$stmt->bind_param('sssii', $title, $rbls, $bclass, $sort, $favID);
					$stmt->execute();
					
					appendLog('edf', 'Successful: Favourite updated.', 'web');
					$_SESSION["Success"] = 'Der Favorit wurde gespeichert.';
					header('Location: index.php'); exit;
				} else {
					appendLog('edf', 'Error: Could not update #'.$_POST['id']." ".$_POST['title'], 'web');
					$_SESSION['Error'] = 'Fehler beim Ändern des Favoriten #' . $favID . '.';
					header('Location: index.php'); exit;
				}

		} else {
				
			$_SESSION['Error'] = 'Favorit #'.$favID . "/". $userID.' nicht gefunden.';
			appendLog('edf', 'Wrong ID #'.$favID . "/". $userID, 'web');
			header('Location: index.php'); exit;
		}
	} else {
			
			$_SESSION['Error'] = 'Datenbank Fehler.';
			appendLog('edf', 'Wrong ID: '.$_SESSION['id'], 'web');
			header('Location: index.php'); exit;
			
	}
} else {
		
	// Did we get an ID to edit?
	if (!isset($_GET['favID'])) {
		// Could not get the data that should have been sent.
		$_SESSION["Error"] = 'Programmfehler: no favID provided.';
		appendLog('edf', 'Error: no favID provided.', 'web');
		header('Location: index.php'); exit;
	} else {
	
		$favID = $_GET['favID'];
		
		// Get current data for favourite
		if ($stmt = $con->prepare('SELECT id,idUser,title,rbls,bclass, sort FROM wl_favorites WHERE id = ? ORDER BY sort,id')) {
			// Bind parameters (s = string, i = int, b = blob, etc), in our case the username is a string so we use "s"
			$stmt->bind_param('i', $favID);
			$stmt->execute();
			
			$stmt->bind_result($id,$idUser,$title,$rbls,$bclass,$sort);
		
			// Store the result so we can check if the data exists in the database.
			$stmt->store_result();
			
			if ($stmt->num_rows>0) {
				$stmt->fetch()
	?>
				<div class="alert alert-danger invisible" id="changeFavouriteError"></div>
				
				<!--Body-->
				<form action='editFavourite.php?favID=<? echo $id; ?>' id='changeFavorite' method='post'>
<?= csrf_input() ?>

						<label id="title-error" for="title" class="error text-danger"></label>
						<div class="input-group has-warning mb-5 mt-2 has-error has-success">
							<span class="input-group-text p-2 bg-dark text-light"><i class="fas fa-map-marker-alt prefix"></i></span>
							<input type="text" id="title" name="title" class="form-control validate" placeholder="Stationsbezeichnung" autocomplete="on" value="<? echo $title; ?>">
							<label id="title-success" for="title" class="text-success invisible"> <i class='fas fa-check'></i></label>
						</div>

						
						<label id="rbls-error" for="rbls" class="error text-danger"></label>
						<div class="input-group mb-5 has-warning has-error has-success">
							<span class="input-group-text p-2 bg-dark text-light"><i class="fas fa-bookmark prefix"></i></span>
							<input type="text" id="rbls" name="rbls"  class="form-control validate" placeholder="Stationsnummern" autocomplete="on" value="<? echo $rbls; ?>">
							<label id="rbls-success" for="rbls" class="text-success invisible"> <i class='fas fa-check'></i></label>
						</div>

						<label id="sort-error" for="sort" class="error text-danger"></label>
						<div class="input-group mb-5 has-warning has-error has-success">
							<span class="input-group-text p-2 bg-dark text-light"><i class="fas fa-sort-amount-down prefix"></i></span>
							<input type="text" id="sort" name="sort"  class="form-control validate" placeholder="Rang" autocomplete="on" value="<? echo $sort; ?>">
							<label id="sort-success" for="sort" class="text-success invisible"> <i class='fas fa-check'></i></label>
						</div>

						
						<label id="bclass-error" for="sort" class="error text-danger"></label>
						<div class="input-group mb-5 has-warning has-error has-success">
							<span class="input-group-text p-2 bg-dark text-light" ><i class="fas fa-palette"></i></span>
							
							<select id="bclass" name="bclass" class="form-control" placeholder="Farbe">
								<option class="bg-default"	title="weiss"	<? echo ($bclass == "btn-outline-default"	? "selected" : "") ?> > btn-outline-default </option>
								<option class="bg-primary"	title="türkis"	<? echo ($bclass == "btn-outline-primary"	? "selected" : "") ?> > btn-outline-primary </option>
								<option class="bg-success"	title="grün"	<? echo ($bclass == "btn-outline-success"	? "selected" : "") ?> > btn-outline-success </option>
								<option class="bg-info"		title="lila"	<? echo ($bclass == "btn-outline-info"		? "selected" : "") ?> > btn-outline-info	</option>
								<option class="bg-warning"	title="orange"	<? echo ($bclass == "btn-outline-warning"	? "selected" : "") ?> > btn-outline-warning	</option>
								<option class="bg-danger"	title="rot" 	<? echo ($bclass == "btn-outline-danger"	? "selected" : "") ?> > btn-outline-danger	</option>
								<option class="bg-danger"	title="rot" 	<? echo ($bclass == "btn-outline-danger"	? "selected" : "") ?> > btn-outline-secondary	</option>
								<option class="bg-danger"	title="rot" 	<? echo ($bclass == "btn-outline-danger"	? "selected" : "") ?> > btn-outline-dark	</option>
							</select>
							<label id="bclass-success" for="bclass" class="text-success invisible"> <i class='fas fa-check'></i></label>
						</div>
						
						<div class="text-center form-sm mt-2">
							<button class="btn btn-primary"> <i class="fas fa-save ml-1"></i> Speichern</button>
						</div>
					
					</div>
					
					<input type="hidden" name="favID" value="<? echo $id; ?>">
		
				</form>
			
			<?php
			
			} else {
				$_SESSION["Error"] = 'Fehler: Favorit #' . $favID . ' ' . $title . '  nicht gefunden.';
				appendLog('edf', 'Error: Favourite #'. $favID. ' '.$title.' not found.', 'web');
				header('Location: index.php'); exit;
			}
		
		} else {
			$_SESSION["Error"] = "Fehler : Datenbank Fehler.";
			appendLog('edf', 'SQL Error:', 'web');
			header('Location: index.php'); exit;
		}
	}
}


?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmB6rVQO0ViiINFjyRUNLnCIE3T"
        crossorigin="anonymous"></script>
	
	<script>		
		
		$(document).ready(function(){
		
			// Validate Change Password Form
			$("#xxxchangeFavorite").validate({
				rules: {
					title: {
						required: true,
						minlength: 3
					},
					rbls: {
						required: true,
						minlength: 3,
						pattern: /^\S*$/
					},
					sort: {
						required: true,
						min: 0,
						max: 999
					}
				},
				messages: {
					title: {
						required: "Bitte geben Sie einen Titel ein",
						minlength: "Bitte geben Sie einen Titel mit mindestens drei Buchstaben ein"
						},
					rbls: {
						required: "Bitte geben Sie eine Stationsnummer ein",
						minlength: "Bitte geben Sie eine gültige Stationsnummer ein",
						pattern: "Bitte geben Sie keine Leerzeichen ein!"
						},
					sort: {
						required: "Bitte geben Sie eine Rangnummer ein",
						min: "Bitte geben Sie eine Rangnummer zwischen 0 und 999 ein",
						max: "Bitte geben Sie eine Rangnummer zwischen 0 und 999 ein"
						}
				},
				submitHandler: function(form) {
					// do things for a valid form
					form.submit;
				},
				success: function(){
					// $("#changeFavorite .text-success").removeClass("invisible");
				},
				invalidHandler: function(event, validator) {
					var errors = validator.numberOfInvalids();
					if (errors) {
						var message = errors == 1
							? "Es gibt einen Fehler. Das betroffene Feld wurde markiert."
							: "Es gibt "+ errors +" Fehler. Die betroffenen Felder wurden markiert.";
						$("#changeFavoriteError").html(message);
						$("#changeFavoriteError").show();
					} else {
						$("#changeFavoriteError").hide();
					}
				}
			});
		
		
		});
		<?php
		// Check for alerts 
		// ----------------------------------------------------------------------------------------
		if (isset($_SESSION['Error'])) {
			echo('$("#alerts").append(\'<div class="alert alert-danger alert-dismissible fade show">'.$_SESSION['Error'] . ' <button type="button" class="close" data-bs-dismiss="alert">&times;</button></div>\');');
			unset($_SESSION['Error']);
		}
		?>

	</script>

	<?php
	
		$stmt->close();
		
		if ($_SESSION['debug']) {
			
			$arr = get_defined_vars();
			$out  = "<p id='debugHeader'><b>Debug</b></p>\n";
			$out .= "<pre id='debugBody'>\n _SESSION ";
			$out .= print_r($arr, true);
			$out .= "</pre>";
			
		echo $out;
		}
		
	?>

</body>
</html>