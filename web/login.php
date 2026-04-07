<?php
require_once(__DIR__ . '/../inc/initialize.php');

// Already logged in → go to main page
if (!empty($_SESSION['loggedin'])) {
    header('Location: index.php'); exit;
}

$theme    = htmlspecialchars($_COOKIE['theme'] ?? 'auto', ENT_QUOTES, 'UTF-8');
$remembered = htmlspecialchars($_COOKIE['wlmonitor_username'] ?? '', ENT_QUOTES, 'UTF-8');

// Flush session alerts
$alerts = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>
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
  </div>
</nav>

<div class="container mt-5" style="max-width:400px;">

  <?php foreach ($alerts as [$type, $msg]): ?>
    <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show">
      <?= $msg ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>

  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h4 class="card-title mb-4 text-center">
        <i class="fas fa-subway me-2"></i>Anmelden
      </h4>

      <form method="post" action="authentication.php">
        <?= csrf_input() ?>

        <div class="mb-3">
          <label class="form-label" for="login-username">Benutzername</label>
          <input type="text" id="login-username" name="login-username"
                 class="form-control" autocomplete="username"
                 value="<?= $remembered ?>" required autofocus>
        </div>

        <div class="mb-3">
          <label class="form-label" for="login-password">Kennwort</label>
          <input type="password" id="login-password" name="login-password"
                 class="form-control" autocomplete="current-password" required>
        </div>

        <div class="mb-3 form-check">
          <input type="checkbox" class="form-check-input" id="rememberName"
                 name="rememberName" value="1"
                 <?= $remembered !== '' ? 'checked' : '' ?>>
          <label class="form-check-label" for="rememberName">Benutzername merken</label>
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">
          <i class="fas fa-sign-in-alt me-1"></i> Anmelden
        </button>

        <div class="small">
          <a href="forgotPassword.php">Kennwort vergessen?</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous"></script>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
