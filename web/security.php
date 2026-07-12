<?php
require_once __DIR__ . '/../inc/initialize.php';
require_once __DIR__ . '/../inc/layout.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

if (empty($_SESSION['loggedin'])) {
    header('Location: login.php'); exit;
}

$userId   = (int) $_SESSION['id'];
$username = $_SESSION['username'] ?? '';
$theme    = htmlspecialchars($_SESSION['theme'] ?? ($_COOKIE['theme'] ?? 'auto'), ENT_QUOTES, 'UTF-8');

$table  = AUTH_DB_PREFIX . 'auth_accounts';
$stmt   = $con->prepare("SELECT totp_secret FROM {$table} WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$has2fa = ($stmt->get_result()->fetch_assoc()['totp_secret'] ?? null) !== null;
$stmt->close();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        addAlert('danger', 'Ungültige Anfrage.');
        header('Location: security.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    // ── Change password ───────────────────────────────────────────────────────
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $rowStmt = $con->prepare("SELECT password FROM {$table} WHERE id = ?");
        $rowStmt->bind_param('i', $userId);
        $rowStmt->execute();
        $pwRow = $rowStmt->get_result()->fetch_assoc();
        $rowStmt->close();

        if (!$pwRow || !password_verify($current, $pwRow['password'])) {
            $errors['password'] = 'Aktuelles Kennwort ist falsch.';
        } elseif (strlen($new) < 8 || strlen($new) > 1000) {
            $errors['password'] = 'Neues Kennwort muss zwischen 8 und 1000 Zeichen lang sein.';
        } elseif ($new !== $confirm) {
            $errors['password'] = 'Kennwörter stimmen nicht überein.';
        } else {
            auth_change_password($con, $userId, $new);
            appendLog($con, 'prefs', $username . ' changed password.');
            addAlert('success', 'Kennwort geändert.');
            header('Location: security.php'); exit;
        }
    }

    // ── Enable 2FA step 1: generate secret ───────────────────────────────────
    if ($action === 'totp_start') {
        $secret = auth_totp_enable($con, $userId);
        if ($secret !== null) {
            $_SESSION['totp_setup_secret'] = ['secret' => $secret, 'until' => time() + 300];
        } else {
            addAlert('danger', '2FA konnte nicht aktiviert werden. Bitte versuchen Sie es erneut.');
        }
        header('Location: security.php'); exit;
    }

    // ── Enable 2FA step 2: confirm code ──────────────────────────────────────
    if ($action === 'totp_confirm') {
        $setupData = $_SESSION['totp_setup_secret'] ?? null;
        if ($setupData === null || time() > $setupData['until']) {
            unset($_SESSION['totp_setup_secret']);
            $errors['totp'] = 'Sitzung abgelaufen. Bitte erneut starten.';
        } else {
            $code = trim($_POST['totp_code'] ?? '');
            $ok   = auth_totp_confirm($con, $userId, $setupData['secret'], $code);
            if ($ok) {
                unset($_SESSION['totp_setup_secret']);
                appendLog($con, 'auth', $username . ' enabled 2FA.');
                addAlert('success', '2FA ist jetzt aktiv.');
                header('Location: security.php'); exit;
            } else {
                $errors['totp'] = 'Code ungültig. Bitte erneut versuchen.';
            }
        }
    }

    // ── Disable 2FA ───────────────────────────────────────────────────────────
    if ($action === 'totp_disable') {
        auth_totp_disable($con, $userId);
        unset($_SESSION['totp_setup_secret']);
        appendLog($con, 'auth', $username . ' disabled 2FA.');
        addAlert('success', '2FA wurde deaktiviert.');
        header('Location: security.php'); exit;
    }

    // ── Revoke all remember-me tokens ─────────────────────────────────────────
    if ($action === 'revoke_all_devices') {
        if (auth_remember_revoke_all($con)) {
            addAlert('success', 'Alle Sitzungen wurden beendet.');
        } else {
            addAlert('danger', 'Konnte Sitzungen nicht beenden.');
        }
        header('Location: security.php'); exit;
    }

    // ── Revoke a single remember-me token ─────────────────────────────────────
    if ($action === 'revoke_one_device') {
        $selector = (string) ($_POST['selector'] ?? '');
        if (auth_remember_revoke_one($con, $userId, $selector)) {
            addAlert('success', 'Sitzung wurde beendet.');
        } else {
            addAlert('danger', 'Konnte Sitzung nicht beenden.');
        }
        header('Location: security.php'); exit;
    }
}

$sessions = auth_remember_list_for_user($con, $userId);

$setupSecret = null;
$setupQrHtml = '';
$setupData   = $_SESSION['totp_setup_secret'] ?? null;
if ($setupData !== null && time() <= $setupData['until']) {
    $setupSecret = $setupData['secret'];
    $appName     = 'WL Monitor';
    $uri         = auth_totp_uri($setupSecret, $username . '@' . $appName, $appName);
    $options     = new QROptions(['outputType' => 'svg', 'imageBase64' => false]);
    $svg         = (new QRCode($options))->render($uri);
    $setupQrHtml = '<img src="data:image/svg+xml;base64,' . base64_encode($svg)
                 . '" width="200" height="200" alt="QR Code">';
}
?>
<?php render_header(); ?>
<script nonce="<?= $_cspNonce ?>">
(function () {
  var t = <?= json_encode($theme) ?>;
  if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
})();
</script>

<main class="container-md mt-4" id="main-content" tabindex="-1">
  <h4 class="mb-3"><?= icon("shield", "me-2") ?>Sicherheit</h4>

  <?php foreach ($_SESSION['alerts'] ?? [] as [$type, $msg]): ?>
    <div class="app-alert app-alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
      <button type="button" class="btn-close" data-dismiss-alert></button>
    </div>
  <?php endforeach; unset($_SESSION['alerts']); ?>

  <div class="app-card mb-3">
    <div class="app-card-header"><?= icon("key", "me-1") ?> Kennwort ändern</div>
    <div class="app-card-body">
      <?php if (!empty($errors['password'])): ?>
        <div class="app-alert app-alert-danger py-2" role="alert">
          <?= htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>
      <form method="post" action="security.php">
        <?= csrf_input() ?>
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
        <button type="submit" class="btn btn-outline-danger">Kennwort speichern</button>
      </form>
    </div>
  </div>

  <div class="app-card mb-3">
    <div class="app-card-header"><?= icon("shield", "me-1") ?> Zwei-Faktor-Authentifizierung</div>
    <div class="app-card-body">
      <?php if (!empty($errors['totp'])): ?>
        <div class="app-alert app-alert-danger py-2" role="alert">
          <?= htmlspecialchars($errors['totp'], ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <?php if ($has2fa): ?>
        <p>
          <span class="app-badge app-badge-success">2FA aktiv</span>
          Dein Konto ist mit einem TOTP-Authenticator gesichert.
        </p>
        <form method="post" action="security.php" id="totpDisableForm">
          <?= csrf_input() ?>
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
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="totp_confirm">
          <div class="mb-2">
            <label class="form-label" for="totp_code">6-stelliger Code zur Bestätigung</label>
            <input type="text" name="totp_code" id="totp_code"
                   class="form-control" inputmode="numeric" maxlength="6"
                   required autofocus autocomplete="one-time-code"
                   style="max-width:160px;">
          </div>
          <button type="submit" class="btn btn-outline-danger">Bestätigen</button>
        </form>

      <?php else: ?>
        <p>2FA ist nicht aktiviert.</p>
        <form method="post" action="security.php">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="totp_start">
          <button type="submit" class="btn btn-outline-danger">2FA aktivieren</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="app-card mb-3">
    <div class="app-card-header"><?= icon("shield-off", "me-1") ?> Aktive Sitzungen</div>
    <div class="app-card-body">
      <?php if (!empty($sessions)): ?>
        <div class="table-responsive mb-3">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Gerät</th>
                <th>IP</th>
                <th>Ausgestellt</th>
                <th>Läuft ab</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sessions as $s): ?>
                <tr<?= $s['is_current'] ? ' class="is-current"' : '' ?>>
                  <td>
                    <?= htmlspecialchars($s['browser_os'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($s['user_agent'] !== ''): ?>
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" class="icon-info-circle" tabindex="0" role="img"><title><?= htmlspecialchars($s['user_agent'], ENT_QUOTES, 'UTF-8') ?></title><circle cx="8" cy="8" r="7" fill="currentColor"/><text x="8" y="12" text-anchor="middle" font-family="'Times New Roman', Times, serif" font-size="11" font-weight="bold" font-style="italic" fill="#fff">i</text></svg>
                    <?php endif; ?>
                    <?php if ($s['is_current']): ?>
                      <span class="app-badge app-badge-info">Diese Sitzung</span>
                    <?php endif; ?>
                  </td>
                  <td><code><?= htmlspecialchars($s['ip'], ENT_QUOTES, 'UTF-8') ?></code></td>
                  <td><?= htmlspecialchars(substr($s['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars(substr($s['expires_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <form method="post" action="security.php"
                          <?= $s['is_current'] ? 'class="revoke-one-device-form"' : '' ?>>
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="revoke_one_device">
                      <input type="hidden" name="selector" value="<?= htmlspecialchars($s['selector'], ENT_QUOTES, 'UTF-8') ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">Abmelden</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <p class="text-muted small">
        Aktive Sitzungen auf anderen Apps bleiben bis zu 4 Tage bestehen;
        um sie sofort zu beenden, ändern Sie Ihr Kennwort.
      </p>
      <form method="post" action="security.php" id="revokeAllDevicesForm">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="revoke_all_devices">
        <button type="submit" class="btn btn-outline-danger">Von allen Geräten abmelden</button>
      </form>
    </div>
  </div>

</main>

<script type="module" src="css/shared/js/dialog.js?v=<?= APP_VERSION . '.' . APP_BUILD ?>" nonce="<?= $_cspNonce ?>"></script>
<script nonce="<?= $_cspNonce ?>">
(function () {
  function guard(form, text, opts) {
    if (!form) return;
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      if (await window.confirmDialog(text, opts)) form.submit();
    });
  }

  guard(document.getElementById('totpDisableForm'),
    '2FA wirklich deaktivieren?',
    { titel: '2FA deaktivieren', okLabel: 'Deaktivieren', gefahr: 'commit' });

  guard(document.querySelector('.revoke-one-device-form'),
    'Das ist Ihre aktuelle Sitzung. Wirklich abmelden?',
    { titel: 'Sitzung abmelden', okLabel: 'Abmelden', gefahr: 'commit' });

  guard(document.getElementById('revokeAllDevicesForm'),
    'Wirklich von allen Geräten abmelden?',
    { titel: 'Alle Geräte abmelden', okLabel: 'Abmelden', gefahr: 'commit' });
})();
</script>

<?php render_footer(); ?>
