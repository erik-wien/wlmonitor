<?php
/**
 * web/totp_verify.php — Mid-login TOTP code entry page.
 *
 * Public page (no auth_require). Reads $_SESSION['auth_totp_pending'].
 * GET:  Show the code entry form, or redirect back to login if session is missing/expired.
 * POST: Call auth_totp_complete(), redirect on success or re-render on failure.
 */
require_once(__DIR__ . '/../inc/initialize.php');

// Already fully logged in → go to app
if (!empty($_SESSION['loggedin'])) {
    header('Location: ./'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        addAlert('danger', 'Ungültige Anfrage.');
        header('Location: totp_verify.php'); exit;
    }

    $code   = trim($_POST['totp_code'] ?? '');
    $result = auth_totp_complete($con, $code);

    if ($result['ok']) {
        // Load wlmonitor-specific preferences after 2FA completes
        $pref = $con->prepare('SELECT departures FROM wl_preferences WHERE user_id = ?');
        $pref->bind_param('i', $_SESSION['id']);
        $pref->execute();
        $prefRow = $pref->get_result()->fetch_assoc();
        $pref->close();
        $_SESSION['departures'] = (int) ($prefRow['departures'] ?? MAX_DEPARTURES);

        addAlert('info', 'Hallo ' . htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8') . '.');
        session_write_close();
        header('Location: ./'); exit;
    }

    $error = $result['error'];
    // If pending session was cleared (max attempts or expired), redirect to login
    if (empty($_SESSION['auth_totp_pending'])) {
        addAlert('danger', $error);
        header('Location: login.php'); exit;
    }
}

// GET: check pending session exists and is not expired
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pending = $_SESSION['auth_totp_pending'] ?? null;
    if ($pending === null || time() > $pending['until']) {
        unset($_SESSION['auth_totp_pending']);
        addAlert('danger', 'Sitzung abgelaufen. Bitte erneut anmelden.');
        header('Location: login.php'); exit;
    }
}

$theme      = htmlspecialchars($_COOKIE['theme'] ?? 'auto', ENT_QUOTES, 'UTF-8');
$csrfToken  = csrf_token();
$alerts     = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>

<div class="container-sm mt-5">

  <?php foreach ($alerts as [$type, $msg]): ?>
    <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show">
      <?= $msg ?>
      <button type="button" class="btn-close" data-dismiss-alert></button>
    </div>
  <?php endforeach; ?>

  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h4 class="card-title mb-4 text-center">
        <?= icon("lock", "me-2") ?> Zwei-Faktor-Authentifizierung
      </h4>
      <p class="text-center text-muted mb-3" style="font-size:.9rem;">
        Bitte gib den 6-stelligen Code aus deiner Authenticator-App ein.
      </p>

      <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" action="totp_verify.php">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="mb-3">
          <label class="form-label" for="totp_code">Authenticator-Code</label>
          <input type="text" id="totp_code" name="totp_code"
                 class="form-control text-center"
                 inputmode="numeric" maxlength="6" autocomplete="one-time-code"
                 required autofocus style="font-size:1.4rem;letter-spacing:.2em;">
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-3">
          <?= icon("sign-in", "me-1") ?> Bestätigen
        </button>
      </form>

      <div class="small text-center">
        <a href="login.php">Abbrechen und neu anmelden</a>
      </div>
    </div>
  </div>
</div>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
