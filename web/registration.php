<?php
/**
 * web/registration.php
 *
 * Handles new-user registration.
 *
 * Creates the account with disabled=1 and a random activation_code, then
 * sends an activation email. The account becomes usable only after the user
 * clicks the link in activate.php (which sets activation_code='activated'
 * and disabled=0).
 *
 * Password rules: minimum 8 characters.
 */
require_once(__DIR__ . '/../inc/initialize.php');

if (!csrf_verify()) {
    addAlert('danger', 'Ungültige Anfrage.');
    header('Location: login.php'); exit;
}

$_regKey = 'reg:' . getUserIpAddr();
if (rate_limit_check($_regKey, 3, 900)) {
    addAlert('danger', 'Zu viele Registrierungsversuche. Bitte warten Sie 15 Minuten.');
    header('Location: register.php'); exit;
}
rate_limit_record($_regKey);

// ── Field presence ────────────────────────────────────────────────────────────
if (empty($_POST['username']) || empty($_POST['password']) || empty($_POST['email'])) {
    addAlert('danger', 'Bitte füllen Sie das Registrierungsformular vollständig aus.');
    appendLog($con, 'reg', 'Unsuccessful: Form incomplete.');
    header('Location: login.php'); exit;
}

$username = trim($_POST['username']);
$password = $_POST['password'];
$email    = trim($_POST['email']);

// ── Validate e-mail ───────────────────────────────────────────────────────────
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    addAlert('danger', 'Die E-Mail-Adresse hat eine ungültige Form.');
    appendLog($con, 'reg', 'Unsuccessful: invalid email.');
    header('Location: login.php'); exit;
}

// ── Validate password ─────────────────────────────────────────────────────────
if (strlen($password) < 8) {
    addAlert('danger', 'Das Kennwort muss mindestens 8 Zeichen lang sein.');
    appendLog($con, 'reg', 'Unsuccessful: password too short.');
    header('Location: login.php'); exit;
}

// ── Duplicate e-mail check ────────────────────────────────────────────────────
$chk = $con->prepare('SELECT id FROM jardyx_auth.auth_accounts WHERE email = ?');
$chk->bind_param('s', $email);
$chk->execute();
$chk->store_result();
$exists = $chk->num_rows > 0;
$chk->close();

if ($exists) {
    addAlert('danger', 'Diese E-Mail-Adresse ist bereits registriert. Bitte melden Sie sich an oder lassen Sie sich ein neues Kennwort zusenden.');
    appendLog($con, 'reg', 'Unsuccessful: email already exists (' . $email . ').');
    header('Location: login.php'); exit;
}

// ── Insert account ────────────────────────────────────────────────────────────
$hash = auth_hash_password($password);
$code = bin2hex(random_bytes(64));    // 128-char hex activation code

$ins = $con->prepare(
    'INSERT INTO jardyx_auth.auth_accounts (username, password, email, activation_code, lastLogin, disabled)
     VALUES (?, ?, ?, ?, NOW(), 1)'
);
$ins->bind_param('ssss', $username, $hash, $email, $code);
$ins->execute();
$ins->close();

$newId = (int) $con->insert_id;
$pref = $con->prepare(
    'INSERT INTO wl_preferences (user_id, departures) VALUES (?, 2)
     ON DUPLICATE KEY UPDATE departures = 2'
);
$pref->bind_param('i', $newId);
$pref->execute();
$pref->close();

appendLog($con, 'reg', 'Account created: ' . $username . ' / ' . $email);

// ── Send activation e-mail ────────────────────────────────────────────────────
$activateUrl  = 'https://' . $_SERVER['HTTP_HOST']
    . rtrim(dirname($_SERVER['PHP_SELF']), '/')
    . '/activate.php?email=' . urlencode($email) . '&code=' . urlencode($code);

$htmlBody = '<p>Sehr geehrte(r) ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>'
    . '<p>Vielen Dank für Ihre Registrierung bei WL Monitor. '
    . 'Bitte aktivieren Sie Ihr Benutzerkonto mit diesem Link:</p>'
    . '<p><a href="' . htmlspecialchars($activateUrl, ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($activateUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
    . '<p>Sollten Sie sich nicht registriert haben, ignorieren Sie diese Nachricht.</p>'
    . '<p>Mit freundlichen Grüßen<br>Team WL-Monitor</p>';

$textBody = "Hallo $username,\n\nBitte aktivieren Sie Ihr Konto:\n$activateUrl\n\n"
    . "Sollten Sie sich nicht registriert haben, ignorieren Sie diese Nachricht.\n";

try {
    send_mail($email, $username, 'Bestätigung Ihrer E-Mail-Adresse', $htmlBody, $textBody);
    appendLog($con, 'reg', 'Activation email sent to ' . $email);
} catch (Throwable $e) {
    appendLog($con, 'reg', 'Email send failed: ' . $e->getMessage());
    // Account was created; user can request another email later (not yet implemented).
}

addAlert('success', 'Registrierung erfolgreich. Bitte prüfen Sie Ihren E-Mail-Posteingang und aktivieren Sie Ihr Konto.');
header('Location: login.php');
exit;
