<?php
require_once(__DIR__ . '/../inc/initialize.php');

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

if (empty($_SESSION['loggedin'])) {
    header('Location: login.php'); exit;
}

$userId     = (int) $_SESSION['id'];
$username   = $_SESSION['username'] ?? '';
$theme      = htmlspecialchars($_SESSION['theme'] ?? ($_COOKIE['theme'] ?? 'auto'), ENT_QUOTES, 'UTF-8');
$departures = (int) ($_SESSION['departures'] ?? MAX_DEPARTURES);

// Reload email fresh from DB
$stmt = $con->prepare('SELECT email FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$currentEmail = htmlspecialchars(
    $stmt->get_result()->fetch_assoc()['email'] ?? '', ENT_QUOTES, 'UTF-8'
);
$stmt->close();

// 2FA status
$table   = AUTH_DB_PREFIX . 'auth_accounts';
$stmt    = $con->prepare("SELECT totp_secret FROM {$table} WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$acctRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$has2fa  = ($acctRow['totp_secret'] ?? null) !== null;

$errors    = [];
$activeTab = 'design';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        addAlert('danger', 'Ungültige Anfrage.');
        header('Location: preferences.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    $activeTab = match(true) {
        in_array($action, ['change_password', 'totp_start', 'totp_confirm', 'totp_disable'], true) => 'sicherheit',
        $action === 'change_email'      => 'email',
        $action === 'change_departures' => 'abfahrten',
        $action === 'upload_avatar'     => 'profilbild',
        default                         => 'design',
    };

    // ── Avatar upload (AJAX) ──────────────────────────────────────────────
    if ($action === 'upload_avatar') {
        $res = \Erikr\Chrome\AvatarUpload::handle($con, $userId, $_FILES['avatar'] ?? null);
        header('Content-Type: application/json; charset=utf-8');
        if ($res['ok']) {
            appendLog($con, 'prefs', 'Avatar updated (' . $res['size'] . ' bytes).');
            echo json_encode(['ok' => true]);
            exit;
        }
        $msg = match ($res['error']) {
            'upload_failed'  => 'Upload fehlgeschlagen.',
            'too_large'      => 'Das Bild darf maximal 5 MB groß sein.',
            'not_image'      => 'Nur Bilder (JPEG, PNG, GIF, WebP) sind erlaubt.',
            'too_small'      => 'Das Bild muss mindestens 64×64 Pixel groß sein.',
            'decode_failed',
            'encode_failed'  => 'Das Bild konnte nicht verarbeitet werden.',
            default          => 'Fehler beim Hochladen.',
        };
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }

    // ── Change e-mail ─────────────────────────────────────────────────────
    if ($action === 'change_email') {
        $newEmail  = trim($_POST['email'] ?? '');
        $emailPass = $_POST['email_password'] ?? '';

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Ungültige E-Mail-Adresse.';
        } elseif ($emailPass === '') {
            $errors['email'] = 'Bitte Kennwort zur Bestätigung eingeben.';
        } else {
            $stmt = $con->prepare('SELECT password FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($emailPass, $row['password'])) {
                $errors['email'] = 'Das Kennwort ist falsch.';
            } else {
                $chk = $con->prepare('SELECT id FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE email = ? AND id != ?');
                $chk->bind_param('si', $newEmail, $userId);
                $chk->execute();
                $chk->store_result();
                $taken = $chk->num_rows > 0;
                $chk->close();

                if ($taken) {
                    $errors['email'] = 'Diese E-Mail-Adresse ist bereits vergeben.';
                } else {
                    $code = bin2hex(random_bytes(32));
                    $upd  = $con->prepare(
                        'UPDATE ' . AUTH_DB_PREFIX . 'auth_accounts SET pending_email = ?, email_change_code = ? WHERE id = ?'
                    );
                    $upd->bind_param('ssi', $newEmail, $code, $userId);
                    $upd->execute();
                    $upd->close();

                    $confirmUrl = APP_BASE_URL . '/confirm_email.php?code=' . urlencode($code);
                    if (mail_send_email_change_confirmation($newEmail, $username, $confirmUrl)) {
                        appendLog($con, 'prefs', 'Email change requested for ' . $username);
                        addAlert('info', 'Bestätigungslink wurde an die neue E-Mail-Adresse gesendet. Bitte prüfen Sie Ihren Posteingang.');
                        header('Location: preferences.php#email'); exit;
                    }
                    appendLog($con, 'prefs', 'Email send failed for ' . $username);
                    $errors['email'] = 'Die Bestätigungs-E-Mail konnte nicht gesendet werden. Bitte versuchen Sie es später erneut.';
                }
            }
        }
    }

    // ── Change theme ──────────────────────────────────────────────────────
    if ($action === 'change_theme') {
        $t = $_POST['theme'] ?? '';
        if (!in_array($t, ['light', 'dark', 'auto'], true)) {
            $errors['theme'] = 'Ungültiges Design.';
        } else {
            $upd = $con->prepare('UPDATE ' . AUTH_DB_PREFIX . 'auth_accounts SET theme = ? WHERE id = ?');
            $upd->bind_param('si', $t, $userId);
            $upd->execute();
            $upd->close();
            $_SESSION['theme'] = $t;
            $theme = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
            setcookie('theme', $t, [
                'expires'  => time() + 60 * 60 * 24 * 365,
                'path'     => '/',
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            appendLog($con, 'prefs', 'Theme set to ' . $t);
            addAlert('success', 'Design gespeichert.');
            header('Location: preferences.php#design'); exit;
        }
    }

    // ── Change max. departures ────────────────────────────────────────────
    if ($action === 'change_departures') {
        $dep = (int) ($_POST['departures'] ?? 0);
        if ($dep < 1 || $dep > 5) {
            $errors['departures'] = 'Bitte einen Wert zwischen 1 und 5 wählen.';
        } else {
            $upd = $con->prepare(
                'INSERT INTO wl_preferences (user_id, departures) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE departures = VALUES(departures)'
            );
            $upd->bind_param('ii', $userId, $dep);
            $upd->execute();
            $upd->close();
            $_SESSION['departures'] = $dep;
            $departures = $dep;
            appendLog($con, 'prefs', 'Departures set to ' . $dep);
            addAlert('success', 'Anzahl der Abfahrten aktualisiert.');
            header('Location: preferences.php#abfahrten'); exit;
        }
    }

    // ── Change password ───────────────────────────────────────────────────
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
            addAlert('success', 'Kennwort geändert.');
            header('Location: preferences.php#sicherheit'); exit;
        }
    }

    // ── Enable 2FA step 1: generate secret ───────────────────────────────
    if ($action === 'totp_start') {
        $secret = auth_totp_enable($con, $userId);
        if ($secret !== null) {
            $_SESSION['totp_setup_secret'] = ['secret' => $secret, 'until' => time() + 300];
        }
        header('Location: preferences.php#sicherheit'); exit;
    }

    // ── Enable 2FA step 2: confirm code ──────────────────────────────────
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
                header('Location: preferences.php#sicherheit'); exit;
            } else {
                $errors['totp'] = 'Code ungültig. Bitte erneut versuchen.';
            }
        }
    }

    // ── Disable 2FA ───────────────────────────────────────────────────────
    if ($action === 'totp_disable') {
        auth_totp_disable($con, $userId);
        unset($_SESSION['totp_setup_secret']);
        appendLog($con, 'auth', $username . ' disabled 2FA.');
        addAlert('success', '2FA wurde deaktiviert.');
        header('Location: preferences.php#sicherheit'); exit;
    }
}

// QR code for 2FA setup in progress
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

$avatarUrl = 'avatar.php?id=' . $userId;
$csrfToken = csrf_token();
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>
<script nonce="<?= $_cspNonce ?>">
(function () {
  var t = <?= json_encode($theme) ?>;
  if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
})();
</script>
<script nonce="<?= $_cspNonce ?>">
window.wlPrefsTab      = <?= json_encode($activeTab) ?>;
window.wlPrefsFromPost = <?= json_encode($_SERVER['REQUEST_METHOD'] === 'POST') ?>;
</script>

<link rel="stylesheet" href="css/shared/js/vendor/cropperjs/cropper.min.css">

<div class="container-md mt-4">
  <h4 class="mb-3"><?= icon("user-cog", "me-2") ?>Einstellungen</h4>

  <?php foreach ($_SESSION['alerts'] ?? [] as [$type, $msg]): ?>
    <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
      <button type="button" class="btn-close" data-dismiss-alert></button>
    </div>
  <?php endforeach; unset($_SESSION['alerts']); ?>

  <nav class="tab-bar mb-3" role="tablist" aria-label="Einstellungen">
    <button class="tab-btn" role="tab" id="tab-design"
            aria-controls="panel-design" aria-selected="true"
            data-tab="design">Design</button>
    <button class="tab-btn" role="tab" id="tab-abfahrten"
            aria-controls="panel-abfahrten" aria-selected="false"
            data-tab="abfahrten">Abfahrten</button>
    <button class="tab-btn" role="tab" id="tab-email"
            aria-controls="panel-email" aria-selected="false"
            data-tab="email">E-Mail</button>
    <button class="tab-btn" role="tab" id="tab-sicherheit"
            aria-controls="panel-sicherheit" aria-selected="false"
            data-tab="sicherheit">Sicherheit</button>
    <button class="tab-btn" role="tab" id="tab-profilbild"
            aria-controls="panel-profilbild" aria-selected="false"
            data-tab="profilbild">Profilbild</button>
  </nav>

  <!-- ── Design ───────────────────────────────────────────────────────────── -->
  <div id="panel-design" role="tabpanel" aria-labelledby="tab-design">
    <div class="card mb-3">
      <div class="card-header"><?= icon("palette", "me-1") ?> Design</div>
      <div class="card-body">
        <?php if (!empty($errors['theme'])): ?>
          <div class="alert alert-danger py-2" role="alert">
            <?= htmlspecialchars($errors['theme'], ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>
        <form method="post" action="preferences.php">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="change_theme">
          <div class="btn-group w-100 mb-3" role="group" aria-label="Farbschema">
            <?php foreach (['light' => ['sun', 'Hell'], 'auto' => ['adjust', 'Automatisch'], 'dark' => ['moon', 'Dunkel']] as $val => [$iconId, $label]): ?>
              <input type="radio" class="btn-check" name="theme" id="theme_<?= $val ?>"
                     value="<?= $val ?>" autocomplete="off" <?= $theme === $val ? 'checked' : '' ?>>
              <label class="btn btn-outline-secondary" for="theme_<?= $val ?>">
                <?= icon($iconId, 'me-1') ?><?= $label ?>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="btn btn-outline-success">
            <?= icon("save", "me-1") ?> Speichern
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Abfahrten ────────────────────────────────────────────────────────── -->
  <div id="panel-abfahrten" role="tabpanel" aria-labelledby="tab-abfahrten" hidden>
    <div class="card mb-3">
      <div class="card-header"><?= icon("list-ol", "me-1") ?> Abfahrten pro Linie</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Wie viele Abfahrten pro Linie und Richtung werden angezeigt (1–5)?
        </p>
        <?php if (!empty($errors['departures'])): ?>
          <div class="alert alert-danger py-2" role="alert">
            <?= htmlspecialchars($errors['departures'], ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>
        <form method="post" action="preferences.php">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="change_departures">
          <div class="mb-3">
            <label class="form-label" for="departuresRange">
              Anzahl: <strong id="depVal"><?= $departures ?></strong>
            </label>
            <input type="range" class="form-range" id="departuresRange" name="departures"
                   min="1" max="5" value="<?= $departures ?>"
                   oninput="document.getElementById('depVal').textContent=this.value">
            <div class="d-flex justify-content-between small text-muted">
              <span>1</span><span>2</span><span>3</span><span>4</span><span>5</span>
            </div>
          </div>
          <button type="submit" class="btn btn-outline-success">
            <?= icon("save", "me-1") ?> Speichern
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── E-Mail ───────────────────────────────────────────────────────────── -->
  <div id="panel-email" role="tabpanel" aria-labelledby="tab-email" hidden>
    <div class="card mb-3">
      <div class="card-header"><?= icon("envelope", "me-1") ?> E-Mail-Adresse</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Aktuelle Adresse: <strong><?= $currentEmail ?></strong>
        </p>
        <p class="text-muted small mb-3">
          Nach dem Speichern erhalten Sie einen Bestätigungslink an die neue Adresse.
          Die Änderung wird erst nach Bestätigung aktiv.
        </p>
        <?php if (!empty($errors['email'])): ?>
          <div class="alert alert-danger py-2" role="alert">
            <?= htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>
        <form method="post" action="preferences.php">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="change_email">
          <div class="mb-3">
            <label class="form-label" for="newEmail">Neue E-Mail-Adresse</label>
            <input type="email" id="newEmail" name="email" class="form-control"
                   value="<?= !empty($errors['email'])
                       ? htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>"
                   autocomplete="email" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="emailPassword">Kennwort zur Bestätigung</label>
            <input type="password" id="emailPassword" name="email_password"
                   class="form-control" autocomplete="current-password" required>
          </div>
          <button type="submit" class="btn btn-outline-success">
            <?= icon("paper-plane", "me-1") ?> Bestätigungslink senden
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Sicherheit ───────────────────────────────────────────────────────── -->
  <div id="panel-sicherheit" role="tabpanel" aria-labelledby="tab-sicherheit" hidden>

    <div class="card mb-3">
      <div class="card-header"><?= icon("key", "me-1") ?> Kennwort ändern</div>
      <div class="card-body">
        <?php if (!empty($errors['password'])): ?>
          <div class="alert alert-danger py-2" role="alert">
            <?= htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>
        <form method="post" action="preferences.php">
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
          <button type="submit" class="btn btn-outline-success">Kennwort speichern</button>
        </form>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><?= icon("shield", "me-1") ?> Zwei-Faktor-Authentifizierung</div>
      <div class="card-body">
        <?php if (!empty($errors['totp'])): ?>
          <div class="alert alert-danger py-2" role="alert">
            <?= htmlspecialchars($errors['totp'], ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <?php if ($has2fa): ?>
          <p>
            <span class="badge bg-success">2FA aktiv</span>
            Dein Konto ist mit einem TOTP-Authenticator gesichert.
          </p>
          <form method="post" action="preferences.php"
                onsubmit="return confirm('2FA wirklich deaktivieren?')">
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
          <form method="post" action="preferences.php">
            <?= csrf_input() ?>
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
          <form method="post" action="preferences.php">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="totp_start">
            <button type="submit" class="btn btn-outline-success">2FA aktivieren</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /panel-sicherheit -->

  <!-- ── Profilbild ───────────────────────────────────────────────────────── -->
  <div id="panel-profilbild" role="tabpanel" aria-labelledby="tab-profilbild" hidden>
    <div class="card mb-3">
      <div class="card-header"><?= icon("camera", "me-1") ?> Profilbild</div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" class="rounded-circle"
               style="width:64px;height:64px;object-fit:cover;" alt="Profilbild">
          <div class="text-muted small">JPEG, PNG, GIF oder WebP &middot; max. 5&thinsp;MB. Nach der Auswahl &ouml;ffnet sich der Zuschnitt.</div>
        </div>
        <input type="file" class="form-control" id="avatarFile"
               accept="image/jpeg,image/png,image/gif,image/webp">
      </div>
    </div>
  </div>

</div><!-- /container-md -->

<!-- Avatar crop modal (outside container, position:fixed) -->
<div class="modal" id="avatarCropModal" aria-hidden="true" role="dialog"
     aria-modal="true" aria-labelledby="avatarCropTitle"
     style="display:none;position:fixed;inset:0;z-index:1050;background:rgba(0,0,0,.6);
            align-items:center;justify-content:center;padding:1rem">
  <div class="modal-dialog" style="max-width:560px;width:100%;background:var(--color-bg);
       border:1px solid var(--color-border);border-radius:var(--radius);
       box-shadow:var(--shadow-sm);display:flex;flex-direction:column;max-height:90vh">
    <div class="modal-header" style="padding:.75rem 1rem;border-bottom:1px solid var(--color-border)">
      <strong id="avatarCropTitle">Profilbild zuschneiden</strong>
    </div>
    <div class="modal-body" style="padding:1rem;overflow:auto;min-height:0">
      <div style="max-height:60vh">
        <img id="avatarCropImage" alt="" style="display:block;max-width:100%">
      </div>
    </div>
    <div class="modal-footer" style="padding:.75rem 1rem;border-top:1px solid var(--color-border);display:flex;gap:.5rem;justify-content:flex-end">
      <button type="button" class="btn" id="avatarCropCancel">Abbrechen</button>
      <button type="button" class="btn btn-outline-success" id="avatarCropConfirm">Speichern</button>
    </div>
  </div>
</div>

<script nonce="<?= $_cspNonce ?>" src="css/shared/js/vendor/cropperjs/cropper.min.js"></script>
<script nonce="<?= $_cspNonce ?>" src="css/shared/js/avatar-cropper.js"></script>
<script nonce="<?= $_cspNonce ?>">
(function () {
  const modal = document.getElementById('avatarCropModal');
  new MutationObserver(function () {
    modal.style.display = modal.classList.contains('show') ? 'flex' : 'none';
  }).observe(modal, { attributes: true, attributeFilter: ['class'] });
  initAvatarCropper({
    fileInputId: 'avatarFile',
    modalId:     'avatarCropModal',
    imageId:     'avatarCropImage',
    confirmId:   'avatarCropConfirm',
    cancelId:    'avatarCropCancel',
    formAction:  'preferences.php',
    csrfToken:   <?= json_encode($csrfToken) ?>,
  });
})();
</script>

<script nonce="<?= $_cspNonce ?>">
(function () {
  var TABS = ['design', 'abfahrten', 'email', 'sicherheit', 'profilbild'];

  function activateTab(name) {
    if (!TABS.includes(name)) name = 'design';
    TABS.forEach(function (t) {
      var btn   = document.getElementById('tab-' + t);
      var panel = document.getElementById('panel-' + t);
      var active = t === name;
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
      btn.classList.toggle('active', active);
      if (active) panel.removeAttribute('hidden');
      else        panel.setAttribute('hidden', '');
    });
    if (location.hash.slice(1) !== name) {
      history.replaceState(null, '', '#' + name);
    }
  }

  TABS.forEach(function (t) {
    document.getElementById('tab-' + t).addEventListener('click', function () {
      activateTab(t);
    });
  });

  // On POST re-render (always means validation error — success redirects), use the
  // PHP-derived tab. On GET, use the URL hash so direct links and back-button work.
  var initial = window.wlPrefsFromPost
    ? window.wlPrefsTab
    : (location.hash.slice(1) || 'design');
  activateTab(initial);

  window.addEventListener('hashchange', function () {
    activateTab(location.hash.slice(1));
  });
})();
</script>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
