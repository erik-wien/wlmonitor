<?php
/**
 * reset test password
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

	require_once(__DIR__ . '/../../include/initialize.php');
	
	
	if (empty($_POST["resetEmail"])) {
		
		addAlert("Error", 'Keine E-Mail angegeben.");
		
	} else {
		// Make sure the submitted new Passwords match.
		if ( $_POST['newPassword1'] != $_POST['newPassword2'] ) {
			// New passwords don't match.
			
			addAlert(["Error"], 'Die neuen Passwörter stimmen nicht überein.');
			appendLog('npw', 'Unsuccessful: New passwords don\'t match.', 'web');
			header('Location: index.php'); exit;
		}


		appendLog('npw', 'Password reset request received.', 'web');
		$resetEmail = $_POST["resetEmail"];
		$resetPassword = $_POST["newPassword1"];
		
		$stmt = $con->prepare('select id, username from wl_accounts WHERE email = ?')
	
		$stmt->bind_param('s', $resetEmail);
		$stmt->bind_result($id, $userName);
		$stmt->fetch();
	
		if ($stmt->rowCount() > 0) {
			
			$con->prepare('UPDATE wl_accounts SET NewPassword = ?, ActivationCode = ? WHERE id = ?')
			$password = password_hash($resetPassword, PASSWORD_DEFAULT);
			$uniqid = bin2hex(openssl_random_pseudo_bytes(127));
			$stmt->bind_param('sss', $password, $uniqid, $id);
			$stmt->execute();
			
			appendLog('PWR', 'Successfully password resetted: ' . $username . '/'.$email'], 'web');
			
			$from	= 'wl-monitor@jardyx.com';
			$to		= $userName;
			$subject = 'Bestätigung Ihrer Mail Adresse';
			$headers .= 'From: ' . $from . "\r\n";
			$headers .= 'Reply-To: ' . $from . "\r\n";
			$headers .= 'X-Mailer: PHP/' . phpversion() . "\r\n";
			$headers .=  'MIME-Version: 1.0' . "\r\n";
			$headers .=  'Content-Type: text/plain; charset=UTF-8' . "\r\n";
			$activate_url = 'http://www.jardyx.com/wl-monitor/executeReset.php?email=' . $resetEmail . '&code=' . $uniqid;
			$activate_link = '<a href="' . $activate_url . '">' . $activate_url . '</a>';
			
			$message = <<<mailtext
Sehr geehrte(r) $to,

Wir haben von unserer website für Ihrer Mail Adresse eine Anforderung für ein neues Kennwort bekommen bekommen. Bitte bestätigen Sie die Anforderung mit diesem Link:

$activate_link

Sollten Sie nichts dergleichen veranlasst haben, ignorieren Sie dieses Mail bitte.



Mit freundlich Grüßen


Team WL-Monitor

..................................................
M: $from 
W: https://www.jardyx.com/wl-monitor/

			
mailtext;
			mail($resetEmail, $subject, $message, $headers);
			
			appendLog('reg', 'Mail sent:'.$resetUsername.'/'.$resetEmail.'/'.$uniqid, 'web');
			addAlert("Success", 'Soferne die von Ihnen angegebene Mail-Adresse registriert ist, haben Sie einen Link bekommen, um die Änderung durchzuführen.');
		}
	}
	
$stmt->close();
header('Location: index.php'); exit;

?>