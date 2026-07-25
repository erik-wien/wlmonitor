<?php
/**
 * web/profil.php — Profilseite (Erikr\Chrome\Profile, Header's profilHref
 * target). Trägt Profilbild, Benutzername (Anzeige), E-Mail-Änderung und
 * Kennwort-Link (→ security.php) zusammen; ersetzt die früheren
 * "Benutzereinstellungen"/"Profil"-Abschnitte auf preferences.php.
 * Übernimmt außerdem die Theme-Änderung (Header-Thema-Pille POSTet fire-and-
 * forget hierher, s. themeEndpoint in inc/layout.php) sowie die einzige
 * verbliebene App-Einstellung "Abfahrten" (wl_preferences.departures) —
 * beides inline als appSections-Formular, da preferences.php dadurch keinen
 * eigenständigen Inhalt mehr hatte und entfernt wurde.
 *
 * POST-Kontrakt (siehe Chrome\Profile-Docblock):
 *   upload_avatar     → JSON-Antwort, fetch-basiert (avatar-cropper.js)
 *   change_email      → normaler Browser-POST, Redirect bzw. erneutes
 *                        Rendern mit emailError bei Fehler.
 *   change_theme      → fire-and-forget fetch von der Header-Thema-Pille,
 *                        keine Body-Antwort nötig (HTTP 204).
 *   change_departures → normaler Browser-POST (inline appSections-Formular).
 *   token_create      → JSON-Antwort, fetch-basiert (Erikr\Chrome\ApiTokens /
 *                        api-tokens.js).
 *   token_revoke      → JSON-Antwort, fetch-basiert (Erikr\Chrome\ApiTokens /
 *                        api-tokens.js).
 */

require_once(__DIR__ . '/../inc/initialize.php');
require_once(__DIR__ . '/../inc/layout.php');

auth_require();

$userId   = (int) $_SESSION['id'];
$username = $_SESSION['username'] ?? '';

// Reload email fresh from DB
$stmt = $con->prepare('SELECT email FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$currentEmail = (string) ($stmt->get_result()->fetch_assoc()['email'] ?? '');
$stmt->close();

$departures      = (int) ($_SESSION['departures'] ?? MAX_DEPARTURES);
$emailError      = null;
$departuresError = null;

// ── POST handler ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        appendLog($con, 'prefs', 'CSRF check failed on profil.php.');
        if (in_array($_POST['action'] ?? '', ['upload_avatar', 'clear_avatar', 'token_create', 'token_revoke'], true)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage (CSRF-Token abgelaufen). Bitte Seite neu laden.']);
            exit;
        }
        addAlert('danger', 'Ungültige Anfrage.');
        header('Location: profil.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    // ── Avatar upload (AJAX) ────────────────────────────────────────────────
    if ($action === 'upload_avatar') {
        $res = \Erikr\Chrome\AvatarUpload::handle($con, $userId, $_FILES['avatar'] ?? null);
        header('Content-Type: application/json; charset=utf-8');
        if ($res['ok']) {
            appendLog($con, 'prefs', 'Avatar updated (' . $res['size'] . ' bytes).');
            echo json_encode(['ok' => true]);
            exit;
        }
        appendLog($con, 'prefs', 'Avatar upload failed: ' . $res['error']);
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

    // ── Avatar entfernen ─────────────────────────────────────────────────────
    if ($action === 'clear_avatar') {
        \Erikr\Chrome\AvatarUpload::clear($con, $userId);
        appendLog($con, 'prefs', 'Avatar removed.');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── API-Token anlegen (AJAX) ────────────────────────────────────────────
    if ($action === 'token_create') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $token = auth_api_token_issue($con, $userId, $label, 'web', null);
        $item  = auth_api_tokens_list($con, $userId)[0] ?? null;
        appendLog($con, 'prefs', 'API token created' . ($label !== '' ? " ({$label})." : '.'));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'token' => $token, 'item' => $item]);
        exit;
    }

    // ── API-Token widerrufen (AJAX) ─────────────────────────────────────────
    if ($action === 'token_revoke') {
        $id      = (int) ($_POST['id'] ?? 0);
        $deleted = auth_api_token_revoke($con, $userId, $id);
        header('Content-Type: application/json; charset=utf-8');
        if (!$deleted) {
            appendLog($con, 'prefs', "API token revoke failed (id {$id}).");
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Token nicht gefunden oder bereits widerrufen.']);
            exit;
        }
        appendLog($con, 'prefs', "API token revoked (id {$id}).");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Change e-mail ────────────────────────────────────────────────────────
    if ($action === 'change_email') {
        $newEmail  = trim($_POST['email'] ?? '');
        $emailPass = $_POST['email_password'] ?? '';

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $emailError = 'Ungültige E-Mail-Adresse.';
        } elseif ($emailPass === '') {
            $emailError = 'Bitte Kennwort zur Bestätigung eingeben.';
        } else {
            $stmt = $con->prepare('SELECT password FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($emailPass, $row['password'])) {
                $emailError = 'Das Kennwort ist falsch.';
            } else {
                $chk = $con->prepare('SELECT id FROM ' . AUTH_DB_PREFIX . 'auth_accounts WHERE email = ? AND id != ?');
                $chk->bind_param('si', $newEmail, $userId);
                $chk->execute();
                $chk->store_result();
                $taken = $chk->num_rows > 0;
                $chk->close();

                if ($taken) {
                    $emailError = 'Diese E-Mail-Adresse ist bereits vergeben.';
                } else {
                    $code = auth_email_confirmation_issue($con, $userId, $newEmail)['token'];

                    $confirmUrl = APP_BASE_URL . '/confirm_email.php?code=' . urlencode($code);
                    if (mail_send_email_change_confirmation($newEmail, $username, $confirmUrl)) {
                        appendLog($con, 'prefs', 'Email change requested for ' . $username);
                        addAlert('info', 'Bestätigungslink wurde an die neue E-Mail-Adresse gesendet. Bitte prüfen Sie Ihren Posteingang.');
                        header('Location: profil.php'); exit;
                    }
                    appendLog($con, 'prefs', 'Email send failed for ' . $username);
                    $emailError = 'Die Bestätigungs-E-Mail konnte nicht gesendet werden. Bitte versuchen Sie es später erneut.';
                }
            }
        }
    }

    // ── Change theme (fire-and-forget from the header's theme pill) ────────────
    if ($action === 'change_theme') {
        $t = $_POST['theme'] ?? '';
        if (in_array($t, ['light', 'dark', 'auto'], true)) {
            $upd = $con->prepare('UPDATE ' . AUTH_DB_PREFIX . 'auth_accounts SET theme = ? WHERE id = ?');
            $upd->bind_param('si', $t, $userId);
            $upd->execute();
            $upd->close();
            $_SESSION['theme'] = $t;
            setcookie('theme', $t, [
                'expires'  => time() + 60 * 60 * 24 * 365,
                'path'     => '/',
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            appendLog($con, 'prefs', 'Theme set to ' . $t);
        } else {
            appendLog($con, 'prefs', 'Theme change rejected: invalid value.');
        }
        http_response_code(204);
        exit;
    }

    // ── Change max. departures ───────────────────────────────────────────────
    if ($action === 'change_departures') {
        $dep = (int) ($_POST['departures'] ?? 0);
        if ($dep < 1 || $dep > 5) {
            appendLog($con, 'prefs', 'Departures change rejected: invalid value.');
            $departuresError = 'Bitte einen Wert zwischen 1 und 5 wählen.';
        } else {
            $upd = $con->prepare(
                'INSERT INTO wl_preferences (user_id, departures) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE departures = VALUES(departures)'
            );
            $upd->bind_param('ii', $userId, $dep);
            $upd->execute();
            $upd->close();
            $_SESSION['departures'] = $dep;
            appendLog($con, 'prefs', 'Departures set to ' . $dep);
            addAlert('success', 'Anzahl der Abfahrten aktualisiert.');
            header('Location: profil.php'); exit;
        }
    }
}

$csrfToken = csrf_token();
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// ── Abfahrten: einzelnes Feld, daher inline als appSections-Formular
//    (Chrome\Profile-Kontrakt: ['html' => …] ist vertrauenswürdiges,
//    selbst gebautes Markup — hier vollständig escaped außer Struktur). ────────
ob_start();
?>
<form method="post" action="profil.php" id="departuresForm">
  <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
  <input type="hidden" name="action" value="change_departures">
  <div class="form-group">
    <label class="form-label" for="departuresRange">
      Abfahrten pro Linie: <strong id="depVal"><?= (int) $departures ?></strong>
    </label>
    <?php if ($departuresError !== null): ?>
      <div class="app-alert app-alert-danger py-2" role="alert"><?= $e($departuresError) ?></div>
    <?php endif; ?>
    <input type="range" class="form-range" id="departuresRange" name="departures"
           min="1" max="5" value="<?= (int) $departures ?>">
    <div class="d-flex justify-content-between small text-muted">
      <span>1</span><span>2</span><span>3</span><span>4</span><span>5</span>
    </div>
  </div>
  <button type="submit" class="btn btn-outline-danger">Speichern</button>
</form>
<script nonce="<?= $e($_cspNonce) ?>">
(function () {
  var range = document.getElementById('departuresRange');
  var out   = document.getElementById('depVal');
  if (!range || !out) return;
  range.addEventListener('input', function () { out.textContent = range.value; });
})();
</script>
<?php
$departuresHtml = ob_get_clean();
?>
<?php render_header(); ?>

<main class="container-md mt-4" id="main-content" tabindex="-1">
  <?php foreach ($_SESSION['alerts'] ?? [] as [$type, $msg]): ?>
    <div class="app-alert app-alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
      <button type="button" class="btn-close" data-dismiss-alert></button>
    </div>
  <?php endforeach; unset($_SESSION['alerts']); ?>

  <?php
  \Erikr\Chrome\Profile::render([
      'avatarSrc'           => 'avatar.php?id=' . $userId,
      'username'            => $username,
      'email'               => $currentEmail,
      'emailEditAction'     => 'profil.php',
      'avatarChangeAction'  => 'profil.php',
      'avatarClearAction'   => 'profil.php',
      'passwordHref'        => 'security.php',
      'csrfToken'           => $csrfToken,
      'cspNonce'            => $_cspNonce,
      'emailError'          => $emailError,
      'cropperCssPath'      => 'css/shared/js/vendor/cropperjs/cropper.min.css',
      'cropperJsPath'       => 'css/shared/js/vendor/cropperjs/cropper.min.js',
      'avatarCropperJsPath' => 'css/shared/js/avatar-cropper.js',
      'tokens'              => auth_api_tokens_list($con, $userId),
      'tokenAction'         => 'profil.php',
      'appSections'         => [
          ['html' => $departuresHtml],
      ],
  ]);
  ?>

</main>

<?php render_footer(); ?>
