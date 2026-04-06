<?php
/**
 * web/activate.php
 *
 * Activates a newly registered user account.
 *
 * After registering, new users receive an email with a one-time activation link.
 * This script verifies the code, sets activation_code='activated', and clears
 * the disabled flag so the user can log in.
 */
require_once(__DIR__ . '/../include/initialize.php');

if (!isset($_GET['email'], $_GET['code'])) {
    addAlert('danger', 'Ungültiger Aktivierungslink.');
    header('Location: login.php'); exit;
}

$email = $_GET['email'];
$code  = $_GET['code'];

$stmt = $con->prepare(
    'SELECT id FROM jardyx_auth.auth_accounts WHERE email = ? AND activation_code = ?'
);
$stmt->bind_param('ss', $email, $code);
$stmt->execute();
$stmt->store_result();
$found = $stmt->num_rows > 0;
$stmt->close();

if (!$found) {
    addAlert('danger', 'Dieses Benutzerkonto existiert nicht oder wurde bereits aktiviert.');
    appendLog($con, 'activation', $email . ' not found or already activated.', 'web');
    header('Location: login.php'); exit;
}

$upd = $con->prepare(
    'UPDATE jardyx_auth.auth_accounts SET activation_code = \'activated\', disabled = 0
     WHERE email = ? AND activation_code = ?'
);
$upd->bind_param('ss', $email, $code);
$upd->execute();
$upd->close();

appendLog($con, 'activation', $email . ' successfully activated.', 'web');
addAlert('success', 'Ihr Benutzerkonto wurde aktiviert. Sie können sich jetzt anmelden.');
header('Location: login.php');
exit;
