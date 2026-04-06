<?php
require_once(__DIR__ . '/../include/initialize.php');

if (empty($_POST['login-username']) || empty($_POST['login-password'])) {
    addAlert('danger', 'Bitte sowohl Benutzername als auch Kennwort ausfullen.');
    header('Location: login.php'); exit;
}

if (!csrf_verify()) {
    addAlert('danger', 'Ungultige Anfrage.');
    header('Location: login.php'); exit;
}

$result = auth_login($con, $_POST['login-username'], $_POST['login-password']);

if ($result['ok']) {
    // Load wlmonitor-specific preferences
    $pref = $con->prepare('SELECT departures FROM wl_preferences WHERE user_id = ?');
    $pref->bind_param('i', $_SESSION['id']);
    $pref->execute();
    $prefRow = $pref->get_result()->fetch_assoc();
    $pref->close();
    $_SESSION['departures'] = (int) ($prefRow['departures'] ?? MAX_DEPARTURES);

    if (!empty($_POST['rememberName'])) {
        setcookie('wlmonitor_username', $_POST['login-username'], [
            'expires'  => time() + 10 * 24 * 60 * 60,
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Strict',
        ]);
    } else {
        setcookie('wlmonitor_username', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Strict',
        ]);
    }
    addAlert('info', 'Hallo ' . htmlspecialchars($result['username'], ENT_QUOTES, 'UTF-8') . '.');
    header('Location: index.php'); exit;
} else {
    addAlert('danger', $result['error']);
    header('Location: login.php'); exit;
}
