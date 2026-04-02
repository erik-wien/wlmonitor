<?php
/**
 * activate.php
 * 
 * activates newly registered users
 *
 * After registering, new users get a mail with a on time code-Link
 * 
 * The link calls this document, which activates the users account, of the code provided is valid.
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


if (isset($_GET['email'], $_GET['code'])) {
	if ($stmt = $con->prepare('SELECT * FROM wl_accounts WHERE email = ? AND activation_code = ?')) {
		$stmt->bind_param('ss', $_GET['email'], $_GET['code']);
		$stmt->execute();
		// Store the result so we can check if the account exists in the database.
		$stmt->store_result();
		if ($stmt->num_rows > 0) {
			// Account exists with the requested email and code.
			if ($stmt = $con->prepare('UPDATE wl_accounts SET activation_code = ?, password=newPassword, newPassword='' WHERE email = ? AND activation_code = ?')) {
				// Set the new activation code to 'activated', this is how we can check if the user has activated their account.
				$newcode = 'activated';
				$stmt->bind_param('sss', $newcode, $_GET['email'], $_GET['code']);
				$stmt->execute();
				
				addAlert("Error", 'Ihr Benutzerkonto wurde aktiviert. Sie können Sich jetzt <br><a href="index.php">anmelden</a>.');
				appendLog('Activation', $_GET['email'] . ' successfully activated.', 'web');
				header('Location: index.php'); exit;
			}
		} else {
			addAlert("Error", 'Dieses Benutzerkonto existiert nicht oder wurde bereits aktiviert.');
			appendLog('Activation', $_GET['email'] . ' doesn\'t exist or is already activated.', 'web');

		}
	}
}

$stmt->close();
header('Location: index.php'); exit;

?>