<?php
require_once(__DIR__ . '/../inc/initialize.php');

// Functionality moved to preferences.php
header('Location: preferences.php'); exit;

appendLog($con, 'npw', 'Change password page loaded.', 'web');

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['alerts'][] = ['danger', 'Ungültige Anfrage.'];
        header('Location: index.php'); exit;
    }

    $old  = $_POST['oldPassword']  ?? '';
    $new1 = $_POST['newPassword1'] ?? '';
    $new2 = $_POST['newPassword2'] ?? '';

    if ($old === '' || $new1 === '' || $new2 === '') {
        $error = 'Bitte füllen Sie das Formular vollständig aus.';
    } elseif ($new1 !== $new2) {
        $error = 'Die neuen Passwörter stimmen nicht überein.';
    } else {
        $stmt = $con->prepare('SELECT password FROM jardyx_auth.auth_accounts WHERE id = ?');
        $stmt->bind_param('i', $_SESSION['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($old, $row['password'])) {
            $upd = $con->prepare('UPDATE jardyx_auth.auth_accounts SET invalidLogins = invalidLogins + 1 WHERE id = ?');
            $upd->bind_param('i', $_SESSION['id']);
            $upd->execute();
            $upd->close();
            appendLog($con, 'npw', 'Failed: wrong old password for ' . ($_SESSION['username'] ?? ''), 'web');
            $error = 'Das alte Kennwort ist falsch.';
        } else {
            $hash = password_hash($new1, PASSWORD_BCRYPT, ['cost' => 13]);
            $upd = $con->prepare('UPDATE jardyx_auth.auth_accounts SET password = ? WHERE id = ?');
            $upd->bind_param('si', $hash, $_SESSION['id']);
            $upd->execute();
            $upd->close();
            appendLog($con, 'npw', 'Success: password changed for ' . ($_SESSION['username'] ?? ''), 'web');
            $_SESSION['alerts'][] = ['success', 'Das neue Kennwort wurde gespeichert.'];
            header('Location: index.php'); exit;
        }
    }
}

$uname  = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$rights = htmlspecialchars($_SESSION['rights']   ?? '', ENT_QUOTES, 'UTF-8');
$theme  = htmlspecialchars($_SESSION['theme'] ?? ($_COOKIE['theme'] ?? 'auto'), ENT_QUOTES, 'UTF-8');
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>
<script>
(function() {
  var t = <?= json_encode($theme) ?>;
  if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
})();
</script>

<nav class="navbar">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php"><?= icon("subway", "me-1") ?> WL Monitor</a>
    <div class="navbar-nav ms-auto align-items-center gap-1">
      <span class="nav-link"><?= $uname ?></span>
      <a class="nav-link" href="index.php" title="Zurück"><?= icon("arrow-left") ?></a>
    </div>
  </div>
</nav>

<div class="container mt-4" style="max-width:480px">
  <h4 class="mb-3">Kennwort ändern</h4>

  <?php if ($error !== null): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="post" action="changePassword.php">
    <?= csrf_input() ?>
    <div class="mb-3">
      <label class="form-label" for="oldPassword">Altes Kennwort</label>
      <input type="password" id="oldPassword" name="oldPassword" class="form-control" required autocomplete="current-password">
    </div>
    <div class="mb-3">
      <label class="form-label" for="newPassword1">Neues Kennwort</label>
      <input type="password" id="newPassword1" name="newPassword1" class="form-control" required autocomplete="new-password">
    </div>
    <div class="mb-3">
      <label class="form-label" for="newPassword2">Neues Kennwort bestätigen</label>
      <input type="password" id="newPassword2" name="newPassword2" class="form-control" required autocomplete="new-password">
    </div>
    <button type="submit" class="btn btn-primary"><?= icon("save", "me-1") ?> Speichern</button>
    <a href="index.php" class="btn btn-secondary ms-2">Abbrechen</a>
  </form>
</div>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
