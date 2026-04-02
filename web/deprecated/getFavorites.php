<?php
/**
 * getFavorites.php
 * 
 * produces a bunch of buttons with all the users favorite stations
 * 
 * supporting code for index.php
 *
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
 
 
 require_once(__DIR__ . '/../include/initialize.php');

$idUser = (isset($_SESSION['id']) ? $_SESSION['id'] : 1);

$sql = 'SELECT id,idUser,title,rbls,bclass FROM wl_favorites WHERE idUser = ? ORDER BY sort,id';
$stmt = $con->prepare($sql);
$stmt->bind_param('i', $idUser);
$stmt->execute();

if ($stmt->error) {
    $_SESSION['Error'] = 'Favoriten konnten nicht geladen werden:'. $stmt->error;
}
$result = $stmt->get_result();
$sortIndex = 0;

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $sortIndex += 1;
        $safeRbls   = htmlspecialchars($row["rbls"],   ENT_QUOTES, 'UTF-8');
        $safeTitle  = htmlspecialchars($row["title"],  ENT_QUOTES, 'UTF-8');
        $safeBclass = htmlspecialchars($row["bclass"], ENT_QUOTES, 'UTF-8');
        echo "<button id='btnFav-{$row["id"]}' sortIndex='$sortIndex' onclick='changeMonitor(`$safeRbls`, $idUser);' type='button' class='btn $safeBclass btn-block' ";

		if ( isset($_SESSION['id']) ) {
			echo (" ondblclick=\"window.location = 'editFavourite.php?favID=" . $row["id"] . "' \"");
		}
		echo ">$safeTitle</button>\n";
	}
	
	
	
	
} else {
		$_SESSION['Error'] = 'Favoriten konnten nicht geladen werden:'. $stmt->error;
}

	$stmt->close();
	$con->close();

?>