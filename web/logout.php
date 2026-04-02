<?php
/**
 * logout.php
 * 
 *
 * log out by destroying the session
 * 
 * supporting code for index.php
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

// Ensure session is properly managed
$sId = manageUserSession();

// Append log before destroying the session
if ($_SESSION['username']) {
    appendLog($con, 'log', $_SESSION['username'] . ' logged out.', 'web');
}

addAlert("Notice", "Sie wurden abgemeldet.");

// Unset and delete the 'sId' cookie
unset($_COOKIE['sId']);

// Destroy the session
session_destroy();

// Redirect to the login page:
header('Location: index.php'); exit;
?>
