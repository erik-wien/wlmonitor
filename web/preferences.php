<?php
require_once(__DIR__ . '/../inc/initialize.php');

if (empty($_SESSION['loggedin'])) {
    header('Location: login.php'); exit;
}

$userId     = (int) $_SESSION['id'];
$theme      = htmlspecialchars($_SESSION['theme'] ?? ($_COOKIE['theme'] ?? 'auto'), ENT_QUOTES, 'UTF-8');
$departures = (int) ($_SESSION['departures'] ?? MAX_DEPARTURES);

// Reload email fresh from DB (avoids stale session value)
$stmt = $con->prepare('SELECT email FROM jardyx_auth.auth_accounts WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$currentEmail = htmlspecialchars(
    $stmt->get_result()->fetch_assoc()['email'] ?? '', ENT_QUOTES, 'UTF-8'
);
$stmt->close();

$errors = [];

// ── POST handler ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        addAlert('danger', 'Ungültige Anfrage.');
        header('Location: preferences.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    // ── Profile picture upload ────────────────────────────────────────────────
    if ($action === 'upload_avatar') {
        $res = \Erikr\Chrome\AvatarUpload::handle($con, $userId, $_FILES['avatar'] ?? null);
        header('Content-Type: application/json; charset=utf-8');
        if ($res['ok']) {
            appendLog($con, 'prefs', 'Avatar updated (' . $res['size'] . ' bytes).', 'web');
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

    // ── Change e-mail (sends confirmation to the new address) ─────────────────
    if ($action === 'change_email') {
        $newEmail  = trim($_POST['email'] ?? '');
        $emailPass = $_POST['email_password'] ?? '';

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Ungültige E-Mail-Adresse.';
        } elseif ($emailPass === '') {
            $errors['email'] = 'Bitte Kennwort zur Bestätigung eingeben.';
        } else {
            $stmt = $con->prepare('SELECT password FROM jardyx_auth.auth_accounts WHERE id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($emailPass, $row['password'])) {
                $errors['email'] = 'Das Kennwort ist falsch.';
            } else {
                $chk = $con->prepare('SELECT id FROM jardyx_auth.auth_accounts WHERE email = ? AND id != ?');
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
                        'UPDATE jardyx_auth.auth_accounts SET pending_email = ?, email_change_code = ? WHERE id = ?'
                    );
                    $upd->bind_param('ssi', $newEmail, $code, $userId);
                    $upd->execute();
                    $upd->close();

                    $confirmUrl  = APP_BASE_URL . '/confirm_email.php?code=' . urlencode($code);
                    $htmlBody    = '<p>Hallo ' . htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8') . ',</p>'
                        . '<p>Sie haben eine neue E-Mail-Adresse für Ihr WL-Monitor-Konto beantragt. '
                        . 'Bitte bestätigen Sie sie mit diesem Link:</p>'
                        . '<p><a href="' . htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') . '">'
                        . htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
                        . '<p>Dieser Link ist 24 Stunden gültig.</p>'
                        . '<p>Sollten Sie keine E-Mail-Änderung beantragt haben, ignorieren Sie diese Nachricht.</p>';
                    $textBody    = "Hallo,\n\nBitte bestätigen Sie Ihre neue E-Mail-Adresse:\n$confirmUrl\n\n"
                        . "Dieser Link ist 24 Stunden gültig.\n";

                    try {
                        send_mail($newEmail, $_SESSION['username'] ?? '', 'E-Mail-Adresse bestätigen', $htmlBody, $textBody);
                        appendLog($con, 'prefs', 'Email change requested for ' . ($_SESSION['username'] ?? ''), 'web');
                        addAlert('info', 'Bestätigungslink wurde an die neue E-Mail-Adresse gesendet. Bitte prüfen Sie Ihren Posteingang.');
                    } catch (Throwable $e) {
                        appendLog($con, 'prefs', 'Email send failed: ' . $e->getMessage(), 'web');
                        $errors['email'] = 'Die Bestätigungs-E-Mail konnte nicht gesendet werden. Bitte versuchen Sie es später erneut.';
                    }

                    if (empty($errors['email'])) {
                        header('Location: preferences.php'); exit;
                    }
                }
            }
        }
    }

    // ── Change theme ─────────────────────────────────────────────────────────
    if ($action === 'change_theme') {
        $t = $_POST['theme'] ?? '';
        if (!in_array($t, ['light', 'dark', 'auto'], true)) {
            $errors['theme'] = 'Ungültiges Design.';
        } else {
            $upd = $con->prepare('UPDATE jardyx_auth.auth_accounts SET theme = ? WHERE id = ?');
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
            appendLog($con, 'prefs', 'Theme set to ' . $t, 'web');
            addAlert('success', 'Design gespeichert.');
            header('Location: preferences.php'); exit;
        }
    }

    // ── Change max. departures ────────────────────────────────────────────────
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
            appendLog($con, 'prefs', 'Departures set to ' . $dep, 'web');
            addAlert('success', 'Anzahl der Abfahrten aktualisiert.');
            header('Location: preferences.php'); exit;
        }
    }
}

$avatarUrl = 'avatar.php?id=' . $userId;
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>
<script nonce="<?= $_cspNonce ?>">
(function () {
  var t = <?= json_encode($theme) ?>;
  if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
})();
</script>

<div class="container-md mt-4">
  <h4 class="mb-4"><?= icon("user-cog", "me-2") ?>Einstellungen</h4>

  <?php foreach ($_SESSION['alerts'] ?? [] as [$type, $msg]): ?>
    <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show">
      <?= $msg ?>
      <button type="button" class="btn-close" data-dismiss-alert></button>
    </div>
  <?php endforeach; unset($_SESSION['alerts']); ?>

  <!-- ── Profilbild ──────────────────────────────────────────────────────── -->
  <link rel="stylesheet" href="css/shared/js/vendor/cropperjs/cropper.min.css">
  <div class="card mb-3">
    <div class="card-header"><?= icon("camera", "me-1") ?> Profilbild</div>
    <div class="card-body">
      <div class="d-flex align-items-center gap-3 mb-3">
        <img src="<?= $avatarUrl ?>" class="rounded-circle"
             style="width:64px;height:64px;object-fit:cover;" alt="Profilbild">
        <div class="text-muted small">JPEG, PNG, GIF oder WebP &middot; max. 5&thinsp;MB. Nach der Auswahl &ouml;ffnet sich der Zuschnitt.</div>
      </div>

      <input type="file" class="form-control" id="avatarFile"
             accept="image/jpeg,image/png,image/gif,image/webp">
    </div>
  </div>

  <div class="modal" id="avatarCropModal" aria-hidden="true" role="dialog"
       style="display:none;position:fixed;inset:0;z-index:1050;background:rgba(0,0,0,.6);
              align-items:center;justify-content:center;padding:1rem">
    <div class="modal-dialog" style="max-width:560px;width:100%;background:var(--color-bg);
         border:1px solid var(--color-border);border-radius:var(--radius);
         box-shadow:var(--shadow-sm);display:flex;flex-direction:column;max-height:90vh">
      <div class="modal-header" style="padding:.75rem 1rem;border-bottom:1px solid var(--color-border)">
        <strong>Profilbild zuschneiden</strong>
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
      csrfToken:   <?= json_encode(csrf_token()) ?>,
    });
  })();
  </script>

  <!-- ── Design ───────────────────────────────────────────────────────────── -->
  <div class="card mb-3">
    <div class="card-header"><?= icon("palette", "me-1") ?> Design</div>
    <div class="card-body">
      <?php if (!empty($errors['theme'])): ?>
        <div class="alert alert-danger py-2">
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
        <button type="submit" class="btn btn-primary">
          <?= icon("save", "me-1") ?> Speichern
        </button>
      </form>
    </div>
  </div>

  <!-- ── Abfahrten ──────────────────────────────────────────────────────── -->
  <div class="card mb-3">
    <div class="card-header"><?= icon("list-ol", "me-1") ?> Abfahrten pro Linie</div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        Wie viele Abfahrten pro Linie und Richtung werden angezeigt (1–5)?
      </p>

      <?php if (!empty($errors['departures'])): ?>
        <div class="alert alert-danger py-2">
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
        <button type="submit" class="btn btn-primary">
          <?= icon("save", "me-1") ?> Speichern
        </button>
      </form>
    </div>
  </div>

  <!-- ── E-Mail ──────────────────────────────────────────────────────────── -->
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
        <div class="alert alert-danger py-2">
          <?= htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form method="post" action="preferences.php">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="change_email">
        <div class="mb-3">
          <label class="form-label" for="newEmail">Neue E-Mail-Adresse</label>
          <input type="email" id="newEmail" name="email" class="form-control"
                 value="<?= isset($errors['email'])
                     ? htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>"
                 autocomplete="email" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="emailPassword">Kennwort zur Bestätigung</label>
          <input type="password" id="emailPassword" name="email_password"
                 class="form-control" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary">
          <?= icon("paper-plane", "me-1") ?> Bestätigungslink senden
        </button>
      </form>
    </div>
  </div>

</div>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
