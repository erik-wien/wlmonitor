<?php
/**
 * web/security.php — Passwort & 2FA self-service page.
 *
 * Sections:
 *  - Change password
 *  - Enable/disable TOTP 2FA
 */
require_once(__DIR__ . '/../inc/initialize.php');

if (empty($_SESSION['loggedin'])) {
    header('Location: login.php'); exit;
}

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

$userId   = (int) $_SESSION['id'];
$username = $_SESSION['username'] ?? '';
$error    = '';
$success  = '';

// ── Read current 2FA status ────────────────────────────────────────────────
$table    = AUTH_DB_PREFIX . 'auth_accounts';
$stmt     = $con->prepare("SELECT totp_secret FROM {$table} WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$acctRow  = $stmt->get_result()->fetch_assoc();
$stmt->close();
$has2fa   = ($acctRow['totp_secret'] ?? null) !== null;

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        addAlert('danger', 'Ungültige Anfrage.');
        header('Location: security.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    // ── Change password ──────────────────────────────────────────────────
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $rowStmt = $con->prepare("SELECT password FROM {$table} WHERE id = ?");
        $rowStmt->bind_param('i', $userId);
        $rowStmt->execute();
        $pwRow = $rowStmt->get_result()->fetch_assoc();
        $rowStmt->close();

        if (!password_verify($current, $pwRow['password'])) {
            $error = 'Aktuelles Kennwort ist falsch.';
        } elseif (strlen($new) < 8 || strlen($new) > 1000) {
            $error = 'Neues Kennwort muss zwischen 8 und 1000 Zeichen lang sein.';
        } elseif ($new !== $confirm) {
            $error = 'Kennwörter stimmen nicht überein.';
        } else {
            auth_change_password($con, $userId, $new);
            $success = 'Kennwort geändert.';
        }
    }

    // ── Enable 2FA step 1: generate secret ──────────────────────────────
    elseif ($action === 'totp_start') {
        $secret = auth_totp_enable($con, $userId);
        if ($secret !== null) {
            $_SESSION['totp_setup_secret'] = ['secret' => $secret, 'until' => time() + 300];
        }
        header('Location: security.php'); exit;
    }

    // ── Enable 2FA step 2: confirm code ─────────────────────────────────
    elseif ($action === 'totp_confirm') {
        $setupData = $_SESSION['totp_setup_secret'] ?? null;
        if ($setupData === null || time() > $setupData['until']) {
            unset($_SESSION['totp_setup_secret']);
            $error = 'Sitzung abgelaufen. Bitte erneut starten.';
        } else {
            $code = trim($_POST['totp_code'] ?? '');
            $ok   = auth_totp_confirm($con, $userId, $setupData['secret'], $code);
            if ($ok) {
                unset($_SESSION['totp_setup_secret']);
                appendLog($con, 'auth', $username . ' enabled 2FA.');
                $success = '2FA ist jetzt aktiv.';
                $has2fa  = true;
            } else {
                $error = 'Code ungültig. Bitte erneut versuchen.';
            }
        }
    }

    // ── Disable 2FA ──────────────────────────────────────────────────────
    elseif ($action === 'totp_disable') {
        auth_totp_disable($con, $userId);
        unset($_SESSION['totp_setup_secret']);
        appendLog($con, 'auth', $username . ' disabled 2FA.');
        $success = '2FA wurde deaktiviert.';
        $has2fa  = false;
    }
}

// ── QR code for setup in progress ────────────────────────────────────────
$setupSecret  = null;
$setupQrHtml  = '';
$setupData    = $_SESSION['totp_setup_secret'] ?? null;
if ($setupData !== null && time() <= $setupData['until']) {
    $setupSecret = $setupData['secret'];
    $appName     = 'WL Monitor';
    $uri         = auth_totp_uri($setupSecret, $username . '@' . $appName, $appName);
    $options     = new QROptions(['outputType' => 'svg', 'imageBase64' => false]);
    $svg         = (new QRCode($options))->render($uri);
    $setupQrHtml = '<img src="data:image/svg+xml;base64,' . base64_encode($svg)
                 . '" width="200" height="200" alt="QR Code">';
}

$csrfToken = csrf_token();
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>

<nav class="navbar" id="mainNav">
  <div class="container-fluid">
    <span class="navbar-brand fw-semibold">
      <?= icon("lock", "me-1") ?> Passwort &amp; 2FA
    </span>
    <a href="./" class="btn btn-sm btn-nav ms-auto">
      <?= icon("arrow-left", "me-1") ?> Monitor
    </a>
  </div>
</nav>

<div class="container-sm mt-4">

  <?php if ($success !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
      <button type="button" class="btn-close" data-dismiss-alert></button>
    </div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
      <button type="button" class="btn-close" data-dismiss-alert></button>
    </div>
  <?php endif; ?>

  <!-- Change password -->
  <div class="card mb-4">
    <div class="card-header"><?= icon("key", "me-1") ?> Kennwort ändern</div>
    <div class="card-body">
      <form method="post" action="security.php">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="change_password">
        <div class="mb-2">
          <label class="form-label" for="current_password">Aktuelles Kennwort</label>
          <input type="password" name="current_password" id="current_password"
                 class="form-control" required autocomplete="current-password">
        </div>
        <div class="mb-2">
          <label class="form-label" for="new_password">Neues Kennwort</label>
          <input type="password" name="new_password" id="new_password"
                 class="form-control" required minlength="8" autocomplete="new-password">
        </div>
        <div class="mb-3">
          <label class="form-label" for="confirm_password">Kennwort bestätigen</label>
          <input type="password" name="confirm_password" id="confirm_password"
                 class="form-control" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-outline-success">Kennwort speichern</button>
      </form>
    </div>
  </div>

  <!-- 2FA management -->
  <div class="card mb-4">
    <div class="card-header"><?= icon("shield", "me-1") ?> Zwei-Faktor-Authentifizierung</div>
    <div class="card-body">
      <?php if ($has2fa): ?>
        <p>
          <span class="badge bg-success">2FA aktiv</span>
          Dein Konto ist mit einem TOTP-Authenticator gesichert.
        </p>
        <form method="post" action="security.php"
              onsubmit="return confirm('2FA wirklich deaktivieren?')">
          <input type="hidden" name="csrf_token"
                 value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="totp_disable">
          <button type="submit" class="btn btn-outline-danger">2FA deaktivieren</button>
        </form>

      <?php elseif ($setupSecret !== null): ?>
        <p>Scanne den QR-Code mit deiner Authenticator-App:</p>
        <div class="mb-3"><?= $setupQrHtml ?></div>
        <p class="small text-muted">
          Oder gib den Code manuell ein:
          <code><?= htmlspecialchars($setupSecret, ENT_QUOTES, 'UTF-8') ?></code>
        </p>
        <form method="post" action="security.php">
          <input type="hidden" name="csrf_token"
                 value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="totp_confirm">
          <div class="mb-2">
            <label class="form-label" for="totp_code">6-stelliger Code zur Bestätigung</label>
            <input type="text" name="totp_code" id="totp_code"
                   class="form-control" inputmode="numeric" maxlength="6"
                   required autofocus autocomplete="one-time-code"
                   style="max-width:160px;">
          </div>
          <button type="submit" class="btn btn-outline-success">Bestätigen</button>
        </form>

      <?php else: ?>
        <p>2FA ist nicht aktiviert.</p>
        <form method="post" action="security.php">
          <input type="hidden" name="csrf_token"
                 value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="totp_start">
          <button type="submit" class="btn btn-success">2FA aktivieren</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
