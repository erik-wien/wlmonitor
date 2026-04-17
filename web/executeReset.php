<?php
require_once(__DIR__ . '/../inc/initialize.php');

$token = trim($_GET['token'] ?? '');
$error = '';

// Validate token on every request (GET and POST)
$resetRow = null;
if ($token !== '') {
    $stmt = $con->prepare(
        'SELECT pr.id, pr.user_id, a.username
         FROM jardyx_auth.password_resets pr
         JOIN jardyx_auth.auth_accounts a ON a.id = pr.user_id
         WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > UTC_TIMESTAMP()'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $resetRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($resetRow === null) {
    $error = 'Dieser Link ist ungültig oder abgelaufen.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $resetRow !== null) {
    if (!csrf_verify()) {
        $error = 'Ungültige Anfrage.';
    } else {
        $pw1 = $_POST['password1'] ?? '';
        $pw2 = $_POST['password2'] ?? '';

        if (strlen($pw1) < 8) {
            $error = 'Das Kennwort muss mindestens 8 Zeichen lang sein.';
        } elseif ($pw1 !== $pw2) {
            $error = 'Die Kennwörter stimmen nicht überein.';
        } else {
            auth_change_password($con, (int) $resetRow['user_id'], $pw1);

            $mark = $con->prepare('UPDATE jardyx_auth.password_resets SET used = 1 WHERE id = ?');
            $mark->bind_param('i', $resetRow['id']);
            $mark->execute();
            $mark->close();

            appendLog($con, 'pwd_reset', 'Password reset: ' . $resetRow['username']);
            addAlert('success', 'Kennwort wurde geändert. Sie können sich jetzt anmelden.');
            header('Location: login.php'); exit;
        }
    }
}

$theme = htmlspecialchars($_COOKIE['theme'] ?? 'auto', ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>
<script nonce="<?= $_cspNonce ?>">
(function () {
  var t = <?= json_encode($theme) ?>;
  if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
})();
</script>

<nav class="navbar">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php">
      <?= icon("subway", "me-1") ?> WL Monitor
    </a>
  </div>
</nav>

<div class="container-sm mt-4">
  <h4 class="mb-3">Neues Kennwort setzen</h4>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <a href="forgotPassword.php" class="btn btn-secondary btn-sm">Neuen Link anfordern</a>
  <?php else: ?>
    <form method="post" action="executeReset.php?token=<?= urlencode($token) ?>">
      <?= csrf_input() ?>
      <div class="mb-3">
        <label for="password1" class="form-label">Neues Kennwort</label>
        <input type="password" name="password1" id="password1"
               class="form-control" required autofocus minlength="8">
      </div>
      <div class="mb-3">
        <label for="password2" class="form-label">Kennwort wiederholen</label>
        <input type="password" name="password2" id="password2"
               class="form-control" required minlength="8">
      </div>
      <div class="d-grid">
        <button type="submit" class="btn btn-primary">Kennwort ändern</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
