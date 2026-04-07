<?php
require_once(__DIR__ . '/../inc/initialize.php');

if (empty($_SESSION['loggedin'])) {
    header('Location: login.php'); exit;
}

$userId    = (int) $_SESSION['id'];
$uname     = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$theme     = htmlspecialchars($_SESSION['theme'] ?? ($_COOKIE['theme'] ?? 'auto'), ENT_QUOTES, 'UTF-8');
$hasAvatar = !empty($_SESSION['has_avatar']);
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
        $file = $_FILES['avatar'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $errors['avatar'] = 'Upload fehlgeschlagen (Fehlercode ' . ($file['error'] ?? '?') . ').';
        } else {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $info    = @getimagesize($file['tmp_name']);
            if (!$info || !in_array($info['mime'], $allowed, true)) {
                $errors['avatar'] = 'Nur Bilder (JPEG, PNG, GIF, WebP) sind erlaubt.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors['avatar'] = 'Das Bild darf maximal 2 MB groß sein.';
            } else {
                $data = file_get_contents($file['tmp_name']);
                $mime = $info['mime'];
                $size = strlen($data);

                $upd = $con->prepare(
                    'UPDATE jardyx_auth.auth_accounts SET img_blob = ?, img_type = ?, img_size = ? WHERE id = ?'
                );
                $upd->bind_param('ssii', $data, $mime, $size, $userId);
                $upd->execute();
                $upd->close();

                $_SESSION['has_avatar'] = true;
                appendLog($con, 'prefs', 'Avatar updated (' . $mime . ', ' . $size . ' bytes).', 'web');
                addAlert('success', 'Profilbild aktualisiert.');
                header('Location: preferences.php'); exit;
            }
        }
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

    // ── Change password ───────────────────────────────────────────────────────
    if ($action === 'change_password') {
        $old  = $_POST['oldPassword']  ?? '';
        $new1 = $_POST['newPassword1'] ?? '';
        $new2 = $_POST['newPassword2'] ?? '';

        if ($old === '' || $new1 === '' || $new2 === '') {
            $errors['password'] = 'Bitte alle Felder ausfüllen.';
        } elseif (strlen($new1) < 8) {
            $errors['password'] = 'Das neue Kennwort muss mindestens 8 Zeichen lang sein.';
        } elseif ($new1 !== $new2) {
            $errors['password'] = 'Die neuen Kennwörter stimmen nicht überein.';
        } else {
            $stmt = $con->prepare('SELECT password FROM jardyx_auth.auth_accounts WHERE id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($old, $row['password'])) {
                $upd = $con->prepare(
                    'UPDATE jardyx_auth.auth_accounts SET invalidLogins = invalidLogins + 1 WHERE id = ?'
                );
                $upd->bind_param('i', $userId);
                $upd->execute();
                $upd->close();
                appendLog($con, 'npw', 'Failed: wrong old password for ' . ($_SESSION['username'] ?? ''), 'web');
                $errors['password'] = 'Das alte Kennwort ist falsch.';
            } else {
                $hash = password_hash($new1, PASSWORD_BCRYPT, ['cost' => 13]);
                $upd  = $con->prepare(
                    'UPDATE jardyx_auth.auth_accounts SET password = ?, invalidLogins = 0 WHERE id = ?'
                );
                $upd->bind_param('si', $hash, $userId);
                $upd->execute();
                $upd->close();
                appendLog($con, 'npw', 'Success: password changed for ' . ($_SESSION['username'] ?? ''), 'web');
                addAlert('success', 'Kennwort erfolgreich geändert.');
                header('Location: preferences.php'); exit;
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
                'samesite' => 'Strict',
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

// Re-read flag after a potential upload in this request
$hasAvatar  = !empty($_SESSION['has_avatar']);
$departures = (int) ($_SESSION['departures'] ?? MAX_DEPARTURES);
$avatarUrl  = 'avatar.php?id=' . $userId;
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
      <?= icon("subway", "me-1") ?> WL Monitor
    </a>
    <div class="navbar-nav ms-auto align-items-center gap-2">
      <span class="nav-link d-flex align-items-center gap-2">
        <img src="<?= $avatarUrl ?>" class="nav-avatar" alt="">
        <?= $uname ?>
      </span>
      <a class="nav-link" href="index.php" title="Zurück zur Übersicht">
        <?= icon("arrow-left") ?>
      </a>
    </div>
  </div>
</nav>

<div class="container mt-4" style="max-width:560px;">
  <h4 class="mb-4"><?= icon("user-cog", "me-2") ?>Einstellungen</h4>

  <?php foreach ($_SESSION['alerts'] ?? [] as [$type, $msg]): ?>
    <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show">
      <?= $msg ?>
      <button type="button" class="btn-close" data-dismiss-alert></button>
    </div>
  <?php endforeach; unset($_SESSION['alerts']); ?>

  <!-- ── Profilbild ──────────────────────────────────────────────────────── -->
  <div class="card mb-3">
    <div class="card-header"><?= icon("camera", "me-1") ?> Profilbild</div>
    <div class="card-body">
      <div class="d-flex align-items-center gap-3 mb-3">
        <img src="<?= $avatarUrl ?>" class="rounded-circle"
             style="width:64px;height:64px;object-fit:cover;" alt="Profilbild">
        <div class="text-muted small">JPEG, PNG, GIF oder WebP · max. 2 MB</div>
      </div>

      <?php if (!empty($errors['avatar'])): ?>
        <div class="alert alert-danger py-2">
          <?= htmlspecialchars($errors['avatar'], ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form method="post" action="preferences.php" enctype="multipart/form-data">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="upload_avatar">
        <div class="input-group">
          <input type="file" class="form-control" name="avatar" id="avatarFile"
                 accept="image/jpeg,image/png,image/gif,image/webp" required>
          <button class="btn btn-primary" type="submit">
            <?= icon("upload", "me-1") ?> Hochladen
          </button>
        </div>
      </form>
    </div>
  </div>

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

  <!-- ── Kennwort ────────────────────────────────────────────────────────── -->
  <div class="card mb-4">
    <div class="card-header"><?= icon("key", "me-1") ?> Kennwort ändern</div>
    <div class="card-body">
      <?php if (!empty($errors['password'])): ?>
        <div class="alert alert-danger py-2">
          <?= htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form method="post" action="preferences.php">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="mb-3">
          <label class="form-label" for="oldPassword">Altes Kennwort</label>
          <input type="password" id="oldPassword" name="oldPassword"
                 class="form-control" autocomplete="current-password" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="newPassword1">Neues Kennwort</label>
          <input type="password" id="newPassword1" name="newPassword1"
                 class="form-control" autocomplete="new-password"
                 minlength="8" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="newPassword2">Neues Kennwort bestätigen</label>
          <input type="password" id="newPassword2" name="newPassword2"
                 class="form-control" autocomplete="new-password"
                 minlength="8" required>
        </div>
        <button type="submit" class="btn btn-primary">
          <?= icon("save", "me-1") ?> Speichern
        </button>
      </form>
    </div>
  </div>

</div>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
