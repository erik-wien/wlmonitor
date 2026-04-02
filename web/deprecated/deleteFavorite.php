<?php
/**
 * deleteFavorite.php
 * 
 * Delete a favorite from the favorites table
 * 
 * supporting code for index.php
 *
 * Klicking on the bin besides the stations name, an ajax call is made to this file removing the favorite from the table.
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
 
	require_once(__DIR__ . '/../include/initialize.php');
	
	// Let's check if the user is logged in.
	if ( !isset($_SESSION['loggedin']) ) {
		// Could not get the data that should have been sent.
		$_SESSION['Error'] = 'Sie sind nicht angemeldet.';
		appendLog('favDel', 'User not logged in', 'web');
		header('Location: index.php'); exit;
	}
	
	if (!isset($_GET['id'])){die('Add Favorites: No id provided.');};
	
	
	$sql  = "DELETE FROM wl_favorites ";
	$sql .= "WHERE id = ? and idUser = ?";
	$stmt = $con->prepare($sql);
	
	$stmt->bind_param('ii', $_GET['id'], $_SESSION['id']); // SessID just for saftey ...
	$stmt->execute();
	$stmt->close();
	
	appendLog($con, 'favDel', 'Favorite #'.$_GET['id'].' deleted.', 'web');
	$_SESSION["Success"] = 'Der Favorit wurde gelöscht.';
	header('Location: index.php'); exit;
	
