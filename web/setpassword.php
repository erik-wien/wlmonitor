<?php
/**
 * web/setpassword.php — Invitation and password-reset flow.
 *
 * GET:  Validates token, shows "set password" form.
 * POST: Validates input, calls invite_complete(), redirects to login.
 */
require_once(__DIR__ . '/../inc/initialize.php');

$token  = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error  = '';
$userId = null;

if ($token !== '') {
    $userId = invite_verify_token($con, $token);
}

if ($userId === null) {
    include_once(__DIR__ . '/../inc/html_header.php');
    echo '<div class="container mt-4"><div class="alert alert-danger">Link ungültig oder abgelaufen.</div></div>';
    include_once(__DIR__ . '/../inc/html_footer.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw      = $_POST['password']         ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    if (strlen($pw) < 8) {
        $error = 'Passwort muss mindestens 8 Zeichen haben.';
    } elseif ($pw !== $confirm) {
        $error = 'Passwörter stimmen nicht überein.';
    } else {
        invite_complete($con, $userId, $pw);
        header('Location: login.php?msg=password_set');
        exit;
    }
}

include_once(__DIR__ . '/../inc/html_header.php');
?>
<div class="container mt-4" style="max-width:480px">
  <h4 class="mb-3">Passwort einrichten</h4>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <form method="post">
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
    <button type="submit" class="btn btn-primary">Passwort speichern</button>
  </form>
</div>
<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
