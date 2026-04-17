<?php
require_once(__DIR__ . '/../inc/initialize.php');

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Ungültige Anfrage.';
    } elseif (empty(trim($_POST['email'] ?? ''))) {
        $error = 'Bitte E-Mail-Adresse eingeben.';
    } else {
        $ip  = getUserIpAddr();
        $key = 'reset:' . $ip;

        if (rate_limit_check($key, 3, 900)) {
            $error = 'Zu viele Versuche. Bitte warten Sie 15 Minuten.';
        } else {
            rate_limit_record($key);

            $email = trim($_POST['email']);

            $stmt = $con->prepare(
                'SELECT id, username FROM jardyx_auth.auth_accounts
                 WHERE email = ? AND activation_code = "activated" AND disabled = "0"'
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $del = $con->prepare('DELETE FROM jardyx_auth.password_resets WHERE user_id = ?');
                $del->bind_param('i', $row['id']);
                $del->execute();
                $del->close();

                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                $ins = $con->prepare(
                    'INSERT INTO jardyx_auth.password_resets (user_id, token, expires_at) VALUES (?, ?, ?)'
                );
                $ins->bind_param('iss', $row['id'], $token, $expiresAt);
                $ins->execute();
                $ins->close();

                $resetUrl = APP_BASE_URL . '/executeReset.php?token=' . urlencode($token);
                if (mail_send_password_reset($email, $row['username'], $resetUrl)) {
                    appendLog($con, 'pwd_reset', 'Reset mail sent: ' . $row['username']);
                } else {
                    appendLog($con, 'pwd_reset', 'Reset mail failed: ' . $row['username']);
                }
            }

            // Always show the same message to avoid user enumeration.
            $success = true;
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>

<nav class="navbar" id="mainNav">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php">
      <?= icon("subway", "me-1") ?> WL Monitor
    </a>
  </div>
</nav>

<div class="container-sm mt-4">
  <h4 class="mb-3">Kennwort vergessen</h4>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-success">
      Sofern die angegebene E-Mail-Adresse registriert ist, haben Sie einen Link zum Zurücksetzen erhalten.
    </div>
    <a href="login.php" class="btn btn-secondary btn-sm">Zurück zur Anmeldung</a>
  <?php else: ?>
    <form method="post" action="forgotPassword.php">
      <?= csrf_input() ?>
      <div class="mb-3">
        <label for="email" class="form-label">E-Mail-Adresse</label>
        <input type="email" name="email" id="email"
               class="form-control" required autofocus
               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="d-grid gap-2">
        <button type="submit" class="btn btn-primary">Link anfordern</button>
        <a href="login.php" class="btn btn-outline-secondary">Abbrechen</a>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
