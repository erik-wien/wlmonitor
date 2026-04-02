<?php
/**
 * register data sent with registration form from index.php
 * 
 * User will be the email Address; Username is just for curtesy purposes.
 * 
 * Password rules apply: 8-12 characters, One upeercase letter, one Number.
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



appendLog('reg', 'Received: '.$_POST['username'].'/'.$_POST['email'], 'web');

// Let's check if the data was submitted, isset() function will check if the data exists.
if (!isset($_POST['username'], $_POST['password'], $_POST['email'])) {
	// Could not get the data that should have been sent.
	$_SESSION["Error"] = 'Bitte füllen Sie das Registrierungsformular vollständig aus!!';
	appendLog('reg', 'Unsuccessful: Form incomplete.', 'web');
	
	header('Location: index.php'); exit;
	
} else {
	$_SESSION["Error"] = '';
}

// Make sure the submitted registration values are not empty.
if (empty($_POST['username']) || empty($_POST['password']) || empty($_POST['email'])) {
	// One or more values are empty.
	$_SESSION["Error"] = 'Bitte füllen Sie das Registrierungsformular vollständig aus!!';
	appendLog('reg', 'Unsuccessful: Form incomplete.', 'web');
	
	header('Location: index.php'); exit;
}

// Check Mail adress for valid characters and form
if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
	$_SESSION["Error"] = 'Die Mail Adresse enthält ungewöhnliche Zeichen oder hat eine ungewöhnliche Form.';
	appendLog('reg', 'Unsuccessful: Mail address not valid.', 'web');
	
	header('Location: index.php'); exit;
}

// Check password rules
$pattern = '/^(?=.*\d)(?=.*[A-Za-z])[0-9A-Za-z!@#$%]{8,12}$/';
if (!preg_match($pattern,$_POST['password'] ) ) {
	$_SESSION["Error"] = 'Kennwort 8-12 Zeichen: mind. 1 Zeichen, mind. 1 Nummer, folgende Zeichen zulässig: !@#$%.';
	appendLog('reg', 'Unsuccessful: Password does not comply with our rules.', 'web');
	
	header('Location: index.php'); exit;
}

// Check if the account with that username already exists.
if ($stmt = $con->prepare('SELECT id, password FROM wl_accounts WHERE email = ?')) {
	// Bind parameters (s = string, i = int, b = blob, etc), hash the password using the PHP password_hash function.
	$stmt->bind_param('s', $_POST['email']);
	$stmt->execute();
	$stmt->store_result();
	// Store the result so we can check if the account exists in the database.
	if ($stmt->num_rows > 0) {
		// Username already exists
		$_SESSION["Error"] = 'Diese mail Adresse existiert bereits. Bitte wählen Sie einen anderen oder lassen Sie sich ein neues Passwort zusenden!';
		appendLog('reg', 'Unsuccessful: Mail address already exists.', 'web');
		
		header('Location: index.php'); exit;
	} else {

		// Username doesnt exists, insert new account
		if ($stmt = $con->prepare('INSERT INTO wl_accounts (username,password,email,activation_code,lastLogin,disabled) VALUES (?, ?, ?, ?, NOW(), 1)')) {
			// We do not want to expose passwords in our database, so hash the password and use password_verify when a user logs in.
			$password = password_hash($_POST['password'], PASSWORD_DEFAULT);
			$uniqid = bin2hex(openssl_random_pseudo_bytes(127));
			$stmt->bind_param('ssss', $_POST['username'], $password, $_POST['email'], $uniqid);
			$stmt->execute();
			
			appendLog('reg', 'Successful:'.$_POST['username'].'/'.$_POST['email'], 'web');
			
			$from	= 'wl-monitor@jardyx.com';
			$to		= $_POST['username'];
			$subject = 'Bestätigung Ihrer Mail Adresse';
			$headers = 'From: ' . $from . "\r\n" . 'Reply-To: ' . $from . "\r\n" . 'X-Mailer: PHP/' . phpversion() . "\r\n" . 'MIME-Version: 1.0' . "\r\n" . 'Content-Type: text/html; charset=UTF-8' . "\r\n";
			$activate_url = 'http://www.jardyx.com/wl-monitor/activate.php?email=' . $_POST['email'] . '&code=' . $uniqid;
			$activate_link = '<a href="' . $activate_url . '">' . $activate_url . '</a>';
			
			$message = <<<mailtext
<p>Sehr geehrte(r) $to,</p>

<p>Wir haben von unserer website für Ihrer Mail Adresse eine Anforderung für ein neues Benutzerkonto bekommen. Bitte bestätigen Sie die Anforderung mit diesem Link: </p>

<p>$activate_link</p>

<p>Sollten Sie nichts dergleichen veranlasst haben, ignorieren Sie dieses Mail bitte.</p>
			
<p>Mit freundlich Grüßen <br /><br /><br />

Team WL-Monitor</p>
			
<p>..................................................<br />
$from <br />
<a href="https://www.jardyx.com/wl-monitor/">https://www.jardyx.com/wl-monitor/</a>
</p>
			
mailtext;
			mail($_POST['email'], $subject, $message, $headers);
			$_SESSION["Success"] = 'Registrierung erfolgreich. Bitte prüfen Sie Ihren Mail-Eingang und aktivieren Sie Ihr Benutzerkonto.';
			appendLog('reg', 'Mail sent:'.$_POST['username'].'/'.$_POST['email'].'/'.$uniqid, 'web');
			header('Location: index.php'); exit;
			/*
			session_regenerate_id();
			$_SESSION["loggedin"] = TRUE;
			$_SESSION["username"] = $_POST['username'];
			$_SESSION["email"] = $_POST['password'];
			*/
			
		} else {
			// Something is wrong with the sql statement, check to make sure accounts table exists with all 3 fields.
			$_SESSION["Error"] = 'Datenbankfehler. Bitte verständigen Sie den Administrator';
			appendLog('Registration', 'Database Error:'.$_POST['username'].'/'.$_POST['email'].'/'.$uniqid, 'web');
			header('Location: index.php'); exit;
		}
		
		$stmt->close();
	} 
	
} else {
	// Something is wrong with the sql statement, check to make sure accounts table exists with all 3 fields.
	$_SESSION["Error"] = 'Datenbankfehler. Bitte verständigen Sie den Administrator';
	appendLog('Registration', 'Database Error:'.$_POST['username'].'/'.$_POST['email'].'/'.$uniqid, 'web');
	header('Location: index.php'); exit;
}
$con->close();
?>

