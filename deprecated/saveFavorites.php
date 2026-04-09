<?php
/**
 * saveFavourits.php
 * 
 * Save the array with the new sort order of favorites
 *
 * supporting code for getFavourites.php
 *
 * Sending the login-Form calls this file with checks the provided Username (his email) and the password.
 *
 * If succesful, the userdata is stored in the SESSIONS Variable and the user can access his favorites.
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
 
require_once(__DIR__ . '/../include/initialize.php');

// Let's check if the user is logged in.
if ( !isset($_SESSION['loggedin']) ) {
	// Could not get the data that should have been sent.
	addAlert ('Error', 'Sie sind nicht angemeldet.');
	appendLog('favSort', 'No user logged in.', 'web');
	echo '<div class="alert alert-danger">Kein User angemeldet</div>';
	exit ('Kein User angemeldet');
}


// Now we check if data was submitted, isset() will check if the data exists.
if ( !isset($_POST['sortArray']) ) {
	// Could not get the data that should have been sent.
	addAlert ('Error', 'Es wurde keine Favoriten gefunden.');
	appendLog('favSort', 'No Data: Favorites couldn\'t be saved.', 'web');
	echo '<div class="alert alert-danger">Favoriten konnten nicht gespeichert werden.</div>';
	exit ('No Data: Favorites couldn\'t be saved.');
}
	$sortArray = $_POST['sortArray';
	echo "Ist es ein Array? " . (is_array($sortArray) ? "Yes!" : "No :-(") . "\n";
	print_r($sortArray);
		
	appendLog('favSort', "Data submitted: ".print_r($sortArray, true), 'web');

	if($_SESSION['debug']){appendLog('favSort', 'Saving Favorites to database ...', 'web');}

// Prepare our SQL, preparing the SQL statement will prevent SQL injection.
if ($stmt = $con->prepare('UPDATE wl_favorites SET sort = ? WHERE id = ? and idUser = ?;')) {
	// Bind parameters (s = string, i = int, b = blob, etc)
	
	foreach ($sortArray as $favorite) {
			
		// foreach here
		$stmt->bind_param('iii', $favorite->sort, $favorite->id, $_SESSION["id"]);
		// $stmt->execute();
		appendLog('favSort', 'favSaved: ' . $favorite->sort."/".$favorite->id."/".$_SESSION["id"], 'web');
	}
}

echo '<div class="alert alert-success">Favoriten gespeichert.</div>';

appendLog('favSort', 'Favorites saved.', 'web');

$stmt->close();

?>