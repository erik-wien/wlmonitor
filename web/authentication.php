<?php
/**
 * authentication.php
 * 
 * Check the credentials and fill $_SESSIONS
 *
 * supporting code for index.php
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


// Now we check if the data from the login form was submitted, isset() will check if the data exists.
if ( !isset($_POST['login-email'], $_POST['login-password']) ) {
	// Could not get the data that should have been sent.
	addAlert("danger", "Bitte sowohl Benutzername als auch Kennwort ausfüllen.");
	appendLog($con, 'auth', 'Loginform incomplete! ', 'web');
	$stmt->close();
	header('Location: index.php'); exit;
}

// Prepare our SQL, preparing the SQL statement will prevent SQL injection.
if ($stmt = $con->prepare('SELECT id,username,password,email,img,activation_code,disabled,lastlogin,invalidLogins, departures,debug,rights FROM wl_accounts WHERE email = ?')) {
	// Bind parameters (s = string, i = int, b = blob, etc), in our case the username is a string so we use "s"
	$stmt->bind_param('s', $_POST['login-email']);
	$stmt->execute();
	// Store the result so we can check if the account exists in the database.
	$stmt->store_result();

	if ($stmt->num_rows > 0) {
		$stmt->bind_result($id,$username,$password,$email,$img,$activated,$disabled,$lastLogin,$invalidLogins,$departures,$debug,$rights);
		$stmt->fetch();
		// Account exists, now we verify the password.
		// Note: remember to use password_hash in your registration file to store the hashed passwords.
		
		
		if ($activated != "activated") {
			appendLog($con, 'auth', 'Not activated user ' . $username . ' tried to login.', 'web');
			$_SESSION["Error"] = 'User ' . $username . " ist noch nicht aktiviert. Bitte prüfen Sie Ihren Posteingang!";
			$stmt->close();
			header('Location: index.php'); exit;
		}
		
		else if ($disabled == "1") {
			appendLog($con, 'auth', 'Disabled user ' . $username . ' tried to login.', 'web');
			$_SESSION["Error"] = 'User ' . $username . " ist gesperrt. Kontaktieren Sie bitte den Administrator!";
			$stmt->close();
			header('Location: index.php'); exit;
		}
		
		else if (password_verify($_POST['login-password'], $password)) {


			// Verification success! User has loggedin!
			session_regenerate_id();
			$sId = session_id();
			setcookie('sId', $sId, time()+60*60*24*4);
			
			$_SESSION['sId'] = $sId;
			$_SESSION['loggedin'] = TRUE;
			$_SESSION['id'] = $id;
			$_SESSION['username'] = $username;
			$_SESSION['email'] = $email;
			$_SESSION['img'] = $img;
			$_SESSION['disabled'] = $disabled;
			$_SESSION['invalidLogins'] = $invalidLogins;
			$_SESSION['departures'] = $departures;
			$_SESSION['debug'] = $debug;
			$_SESSION['rights'] = $rights;
			unset($_SESSION['failedLogins']);
			unset($_SESSION['Error']);
			
			appendLog($con, 'auth', $username . ' logged in.', 'web');
			addAlert("info", 'Hallo '. $username . ".");
			$_SESSION['lastLogin'] = strftime("%d.%m.%Y %X %Z",time());
			
			// log successful login
			if ($stmt = $con->prepare("UPDATE wl_accounts SET lastLogin = NOW(), invalidLogins = 0 WHERE id = ?")) {
				$stmt->bind_param('i', $id);
				$stmt->execute();
				if ($stmt->error != "") {appendLog($con, 'auth', 'SQL (update last login): ' . $stmt->error ."|". $sId ."|". $id, 'web');};
				
			} else {
				$_SESSION['Error'] = 'Fehler beim Login.';
				appendLog($con, 'auth', 'SQL syntax error (update last login): '.$stmt->error ."| sId=". $sId ."| id=". $id, 'web');
			}
			
			// save session_id
			if ($stmt = $con->prepare("insert into wl_sessions (idUser, session, uniqid) values (?,?,?)")) {
				$uniqid = bin2hex(openssl_random_pseudo_bytes(127));
				$stmt->bind_param('iss', $id, $sId, $uniqid);
				$stmt->execute();
				if ($stmt->error) {appendLog($con, 'auth', 'Error saving session: ' . $stmt->error ."|". $id ."|". $sId ."|". $uniqid, 'web');};
				
			} else {
				$_SESSION['Error'] = 'Fehler beim Login.';
				appendLog($con, 'auth', 'SQL Syntax Error (savong session): '.$stmt->error, 'web');
			}

			
			// Create sessions so we know the user is logged in, they basically act like cookies but remember the data on the server.
			ini_set("session.cookie_lifetime",0); // per browser session
			if ($_POST['stayLoggedin']) { ini_set("session.cookie_lifetime",60*60*24*365);}  // one year
			if ($_POST['rememberName']) { 
				setcookie("wlmonitor_username", $email, time()+10*24*60*60);   // 10 days
			} else {
				setcookie("wlmonitor_username", "", time() - 3600);   // 10 days
			}
			$stmt->close();
			header('Location: index.php'); exit;
		} else {
			
			// log incorrect login with correct username
			if ($stmt = $con->prepare('UPDATE `wl_accounts` SET `invalidLogins` = `invalidLogins` + 1 WHERE email = ?')) {
				// Bind parameters (s = string, i = int, b = blob, etc), in our case the username is a string so we use "s"
				$stmt->bind_param('s', $_POST['login-email']);
				$stmt->execute();
			}
			if (isset($_SESSION['failedLogins'])) {
				$_SESSION['failedLogins'] += 1;
			} else {
				$_SESSION['failedLogins'] = 1;
			}
			sleep(5*$_SESSION['failedLogins']);
			$_SESSION['Error'] = 'Falscher Benutzername oder Kennwort.';
			appendLog($con, 'auth', 'Wrong Password: '.$_POST['login-email'], 'web');
			$stmt->close();
			header('Location: index.php'); exit;
		}
	} else {
		if (isset($_SESSION['failedLogins'])) {
			$_SESSION['failedLogins'] += 1;
		} else {
			$_SESSION['failedLogins'] = 1;
		}
		sleep(5*$_SESSION['failedLogins']);
		$_SESSION['Error'] = 'Falscher Benutzername oder Kennwort.';
		appendLog($con, 'auth', 'Wrong Username: '.$_POST['login-email'], 'web');
		$stmt->close();
		header('Location: index.php'); exit;
	}
	

}

?>