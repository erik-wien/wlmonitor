<?php
/**
 * addFavorite.php
 * 
 * Add a station to the favorites table
 *
 * supporting code for index.php
 * 
 * On clicking the favorite button, an ajax request is sent to this file, which adds the station to the favorites table.
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

	// Let'S check if the user is logged in.
	if ( !isset($_SESSION['loggedin']) ) {
		// Could not get the data that should have been sent.
		$_SESSION['Error'] = 'Sie sind nicht angemeldet.';
		appendLog('favAdd', 'User not logged in', 'web');
		die("User not logged in");
	}
	
	if (!isset($_GET['title'])){appendLog('favAdd', 'ERROR: No title provided.', 'web');die('Get Favorites: No title provided.');};
	if (!isset($_GET['rbls'])){appendLog('favAdd', 'ERROR: No rbls provided.', 'web');die('Get Favorites: No rbls provided.');};
	$sort = (isset($_GET['sort'])) ? $_GET['sort'] : 0;
	$bclass = (isset($_GET['bclass'])) ? $_GET['bclass'] : 'btn-outline-success';
	
	$sql  = "INSERT INTO wl_favorites ";
	$sql .= "(idUser, title, sort, rbls, bclass, updated, created) ";
	$sql .= "VALUES (?, ?, ?, ?, ?, SYSDATE(), CURRENT_TIMESTAMP)";
	$stmt = $con->prepare($sql);
	
	
	$stmt->bind_param('isiss', $_SESSION['id'], $_GET['title'], $sort, $_GET['rbls'], $bclass);
	$stmt->execute();
	
	$sql  = "SELECT id ";
	$sql .= "FROM wl_favorites ";
	$sql .= "WHERE idUser=? and rbls=? ";
	$stmt = $con->prepare($sql);

	$stmt->bind_param('ii', $_SESSION['id'], $_GET['rbls']);
	$stmt->execute();
	
	$stmt->bind_result($idStation);
	$stmt->store_result();
	$stmt->fetch();
	
	appendLog($con, 'favAdd', 'Favorite #' . $idStation . " (" . $_GET['title'] . ") hinzugefügt.", 'web');


	$stmt->close();

	echo $idStation;
