<?php
/**
 * web/register.php
 *
 * Registration form. POSTs to registration.php.
 */
require_once(__DIR__ . '/../include/initialize.php');

if (!empty($_SESSION['loggedin'])) {
    header('Location: index.php'); exit;
}

$theme = htmlspecialchars($_COOKIE['theme'] ?? 'auto', ENT_QUOTES, 'UTF-8');

$alerts = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
?>
<?php include_once(__DIR__ . '/../include/html_header.php'); ?>
<script nonce="<?= $_cspNonce ?>">
(function () {
  var t = <?= json_encode($theme) ?>;
  if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
})();
</script>

<nav class="navbar" id="mainNav">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php">
      <i class="fas fa-subway me-1"></i> WL Monitor
    </a>
    <a class="nav-link ms-auto" href="login.php">
      <i class="fas fa-key me-1"></i> Anmelden
    </a>
  </div>
</nav>

<div class="container mt-5" style="max-width:440px;">

  <?php foreach ($alerts as [$type, $msg]): ?>
    <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show">
      <?= $msg ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>

  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h4 class="card-title mb-4 text-center">
        <i class="fas fa-user-plus me-2"></i>Registrieren
      </h4>

      <form method="post" action="registration.php">
        <?= csrf_input() ?>

        <div class="mb-3">
          <label class="form-label" for="reg-username">Benutzername</label>
          <input type="text" id="reg-username" name="username"
                 class="form-control" autocomplete="username"
                 maxlength="60" required autofocus>
        </div>

        <div class="mb-3">
          <label class="form-label" for="reg-email">E-Mail-Adresse</label>
          <input type="email" id="reg-email" name="email"
                 class="form-control" autocomplete="email" required>
        </div>

        <div class="mb-3">
          <label class="form-label" for="reg-password">Kennwort</label>
          <input type="password" id="reg-password" name="password"
                 class="form-control" autocomplete="new-password"
                 minlength="8" required>
          <div class="form-text">Mindestens 8 Zeichen.</div>
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">
          <i class="fas fa-user-plus me-1"></i> Konto erstellen
        </button>

        <div class="text-center small">
          Bereits ein Konto? <a href="login.php">Anmelden</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous"></script>

<?php include_once(__DIR__ . '/../include/html_footer.php'); ?>
