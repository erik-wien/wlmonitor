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
require_once(__DIR__ . '/../include/initialize.php');
	
appendLog('npw', 'New Password request received.', 'web');

// Let's check if the data was submitted, isset() function will check if the data exists.
if (!isset($_POST['oldPassword'], $_POST['newPassword1'], $_POST['newPassword2'])) {
	// Could not get the data that should have been sent.
	$_SESSION["Error"] = 'Bitte füllen Sie das Formular vollständig aus!!';
	appendLog('npw', 'Unsuccessful: Form incomplete.', 'web');
	header('Location: index.php'); exit;
	
} 

// Make sure the submitted registration values are not empty.
if (empty($_POST['oldPassword']) || empty($_POST['newPassword1']) || empty($_POST['newPassword2'])) {
	// One or more values are empty.
	$_SESSION["Error"] = 'Bitte füllen Sie das Formular vollständig aus!!';
	appendLog('npw', 'Unsuccessful: Form incomplete.', 'web');
	header('Location: index.php'); exit;
}

// Make sure the submitted new Passwords match.
if ( $_POST['newPassword1'] != $_POST['newPassword2'] ) {
	// New passwords don't match.
	$_SESSION["Error"] = 'Die neuen Passwörter stimmen nicht überein.';
	appendLog('npw', 'Unsuccessful: New passwords don\'t match.', 'web');
	header('Location: index.php'); exit;
}



// Prepare our SQL, preparing the SQL statement will prevent SQL injection.
if ($stmt = $con->prepare('SELECT id,password FROM wl_accounts WHERE id = ?')) {
	// Bind parameters (s = string, i = int, b = blob, etc), in our case the username is a string so we use "s"
	$stmt->bind_param('s', $_SESSION['id']);
	$stmt->execute();
	
	$stmt->store_result();
	if ($stmt->num_rows > 0) {
		$stmt->bind_result($id,$password);
		$stmt->fetch();
		// Account exists, now we verify the password.
		// Note: remember to use password_hash in your registration file to store the hashed passwords.
		if (password_verify($_POST['oldPassword'], $password)) {
			if ($stmt = $con->prepare('UPDATE wl_accounts SET password = ? WHERE id = ?')) {
				$password = password_hash($_POST['newPassword1'], PASSWORD_DEFAULT);
				$stmt->bind_param('ss', $password, $_SESSION['id']);
				$stmt->execute();
				appendLog('npw', 'Successful: Password updated.', 'web');
				$_SESSION["Success"] = 'Das neue Kennwort wurde gespeichert.';
				header('Location: index.php'); exit;
			}
		} else {
			
			// log incorrect login with correct username
			if ($stmt = $con->prepare('UPDATE `wl_accounts` SET `invalidLogins` = `invalidLogins` + 1 WHERE id = ?')) {
				// Bind parameters (s = string, i = int, b = blob, etc), in our case the username is a string so we use "s"
				$stmt->bind_param('s', $_SESSION['id']);
				$stmt->execute();
			}
			if (isset($_SESSION['failedLogins'])) {
				$_SESSION['failedLogins'] += 1;
			} else {
				$_SESSION['failedLogins'] = 1;
			}
			sleep(5*$_SESSION['failedLogins']);
			$_SESSION['Error'] = 'Falsches Kennwort.';
			appendLog('npw', 'Wrong Password: '. password_hash($_POST["password"]).'/'.$password, 'web');
			header('Location: index.php'); exit;
		}
	} else {
			
		$_SESSION['Error'] = 'Fehler beim Ändern des Kennwortes.';
		appendLog('npw', 'Wrong ID: '.$_SESSION['id'], 'web');
		header('Location: index.php'); exit;
	}
}else {
		
		$_SESSION['Error'] = 'Datenbank Fehler.';
		appendLog('npw', 'Wrong ID: '.$_SESSION['username'], 'web');
		header('Location: index.php'); exit;
	}

?>