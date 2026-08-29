<?php
/**
 * web/setpassword.php — Invitation and password-reset flow.
 *
 * GET:  Validates token, shows "set password" form.
 * POST: Validates input, calls invite_complete(), redirects to login.
 */
require_once(__DIR__ . '/../inc/initialize.php');
require_once(__DIR__ . '/../inc/layout.php');

$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error   = '';
$success = false;
$userId  = null;

if ($token !== '') {
    $userId = invite_verify_token($con, $token);
}

if ($userId === null) {
    render_header();
    echo '<div class="container mt-4"><div class="app-alert app-alert-danger">Link ungültig oder abgelaufen.</div></div>';
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw      = $_POST['password']         ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    if (!csrf_verify()) {
        $error = 'Ungültige Anfrage.';
    } elseif (strlen($pw) < 8) {
        $error = 'Passwort muss mindestens 8 Zeichen haben.';
    } elseif ($pw !== $confirm) {
        $error = 'Passwörter stimmen nicht überein.';
    } else {
        invite_complete($con, $userId, $pw);
        $success = true;
    }
}

render_header();
$nonce = htmlspecialchars($_cspNonce ?? '', ENT_QUOTES, 'UTF-8');
?>
<div class="container mt-4" style="max-width:480px">
<?php if ($success): ?>
  <h4 class="mb-3">Passwort gespeichert</h4>
  <div class="app-alert app-alert-success">Dein Passwort wurde gespeichert. Du kannst dich jetzt anmelden.</div>
  <p class="mt-3"><a class="btn btn-primary" href="login.php">Zur Anmeldung</a></p>
<?php else: ?>
  <h4 class="mb-3">Passwort einrichten</h4>
  <?php if ($error): ?>
    <div class="app-alert app-alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <form method="post" id="setpasswordForm">
    <?= csrf_input() ?>
    <input type="hidden" name="token"
           value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
    <div class="mb-3">
      <label class="form-label">Passwort</label>
      <input type="password" name="password" class="form-control" required minlength="8" autofocus>
    </div>
    <div class="mb-3">
      <label class="form-label">Passwort bestätigen</label>
      <input type="password" name="password_confirm" class="form-control" required minlength="8">
    </div>
    <button type="submit" class="btn btn-primary" id="setpasswordSubmit">Passwort speichern</button>
  </form>
<?php endif; ?>
</div>
<?php if (!$success): ?>
<script nonce="<?= $nonce ?>">
  // Verhindert einen zweiten POST mit demselben (dann schon verbrauchten) Token,
  // falls der Redirect nach dem ersten Klick verzoegert wirkt oder nicht sichtbar
  // ist (Nutzerbefund 2026-08-24: wiederholtes Tippen auf "Passwort speichern"
  // fuehrte zu "Link ungueltig", weil der erste Klick bereits erfolgreich war).
  document.getElementById('setpasswordForm').addEventListener('submit', function () {
    var btn = document.getElementById('setpasswordSubmit');
    btn.disabled = true;
    btn.textContent = 'Speichere …';
  });
</script>
<?php endif; ?>
<?php render_footer(); ?>
