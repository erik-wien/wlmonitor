<?php
require_once(__DIR__ . '/../inc/initialize.php');
require_once(__DIR__ . '/../inc/admin.php');
require_once(__DIR__ . '/../inc/layout.php');
auth_require();
admin_require();

$selfId   = (int) $_SESSION['id'];
$perPage  = 25;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$filter   = trim((string) ($_GET['filter'] ?? ''));
$listing  = wl_admin_list_users($con, $page, $perPage, $filter);
$users    = $listing['users'];
$total    = $listing['total'];

$csrfToken = csrf_token();

$pageUrl = static function (int $p, string $f): string {
    $qs = ['page' => $p];
    if ($f !== '') { $qs['filter'] = $f; }
    return 'admin.php?' . http_build_query($qs) . '#users';
};
?>
<?php render_header(); ?>

<main id="main-content" tabindex="-1">
<div id="adminAlerts" class="container"></div>

<div class="container admin-page">
  <nav class="app-tabs" role="tablist" aria-label="Administration">
    <button type="button" class="app-tab is-active" role="tab"
            id="tab-ogd" aria-controls="panel-ogd" aria-selected="true"
            data-tab="ogd">
      <?= icon("database") ?> Stationsdaten
    </button>
    <button type="button" class="app-tab" role="tab"
            id="tab-users" aria-controls="panel-users" aria-selected="false"
            data-tab="users">
      <?= icon("users-cog") ?> Benutzerverwaltung
    </button>
    <button type="button" class="app-tab" role="tab"
            id="tab-log" aria-controls="panel-log" aria-selected="false"
            data-tab="log">
      <?= icon("history") ?> Log
    </button>
  </nav>

  <!-- ── Tab: Stationsdaten ──────────────────────────────────────────── -->
  <section id="panel-ogd" class="app-tab-panel is-active"
           role="tabpanel" aria-labelledby="tab-ogd">
    <div class="app-card">
      <div class="app-card-header">Stationsdaten (OGD)</div>
      <div class="app-card-body">
        <p class="form-text">
          Lädt die aktuellen Haltestellen, Steige und Linien von
          data.wien.gv.at neu und ersetzt die lokalen Tabellen
          <code>ogd_haltestellen</code>, <code>ogd_steige</code>,
          <code>ogd_linien</code>.
        </p>
        <button id="btnOgdUpdate" type="button" class="btn btn-outline-danger">
          <?= icon("sync") ?> Jetzt aktualisieren
        </button>
        <div id="ogdLog" class="ogd-log" hidden>
          <pre id="ogdLogPre"></pre>
        </div>
      </div>
    </div>
  </section>

  <!-- ── Tab: Benutzerverwaltung ─────────────────────────────────────── -->
  <section id="panel-users" class="app-tab-panel"
           role="tabpanel" aria-labelledby="tab-users" hidden>
    <?php \Erikr\Chrome\Admin\UsersTab::render([
        'users'   => $users,
        'total'   => $total,
        'page'    => $page,
        'perPage' => $perPage,
        'filter'  => $filter,
        'selfId'  => $selfId,
        'pageUrl' => $pageUrl,
        'extraColumns' => [
            ['key' => 'departures', 'label' => 'Abfahrten'],
        ],
    ]); ?>
  </section>

  <!-- ── Tab: Log ────────────────────────────────────────────────────── -->
  <section id="panel-log" class="app-tab-panel"
           role="tabpanel" aria-labelledby="tab-log" hidden>
    <?php \Erikr\Chrome\Admin\LogTab::render(); ?>
  </section>
</div>
</main>

<!-- ── User create/edit modals (shared) ──────────────────────────────── -->
<?php \Erikr\Chrome\Admin\UserModals::render([
    'csrfToken'   => $csrfToken,
    'extraFields' => [
        [
            'key'     => 'departures',
            'label'   => 'Abfahrten pro Linie',
            'type'    => 'number',
            'min'     => 1,
            'max'     => MAX_DEPARTURES,
            'default' => MAX_DEPARTURES,
        ],
    ],
]); ?>

<script nonce="<?= $_cspNonce ?>">
window.CSRF = <?= json_encode($csrfToken) ?>;
</script>
<script src="css/shared/js/admin.js" nonce="<?= $_cspNonce ?>"></script>
<script type="module" src="css/shared/js/dialog.js?v=<?= APP_VERSION . '.' . APP_BUILD ?>" nonce="<?= $_cspNonce ?>"></script>
<script type="module" src="css/shared/js/api-call.js?v=<?= APP_VERSION . '.' . APP_BUILD ?>" nonce="<?= $_cspNonce ?>"></script>

<script nonce="<?= $_cspNonce ?>">
// Shared helpers from css/shared/js/admin.js:
//   adminPost, showAlert, clearAlerts, openModal, closeModal, activateTab
// Tabs and modal open/close/backdrop/Escape are auto-wired by the shared script.

// ── Users tab: row actions + create/edit ────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const editForm   = document.getElementById('editForm');
  const createForm = document.getElementById('createForm');

  const errorMessages = {
    duplicate_or_invalid: 'Benutzername oder E-Mail bereits vergeben.',
    missing_fields:       'Bitte alle Pflichtfelder ausfüllen.',
    missing_id:           'Ungültige ID.',
    cannot_delete_self:   'Sie können sich nicht selbst löschen.',
    csrf:                 'Sitzung abgelaufen — Seite neu laden.',
    forbidden:            'Keine Berechtigung.',
    server_error:         'Serverfehler — bitte Log prüfen.',
  };
  const errMsg = res => errorMessages[res.error] || res.error || 'Unbekannter Fehler.';

  // ── Edit modal: pre-populate from data-* attributes ──
  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('editId').value             = btn.dataset.id;
      document.getElementById('editUsername').textContent = btn.dataset.username;
      document.getElementById('editEmail').value          = btn.dataset.email;
      document.getElementById('editRights').value         = btn.dataset.rights;
      document.getElementById('editDisabled').checked     = btn.dataset.disabled === '1';
      document.getElementById('editDepartures').value     = btn.dataset.departures || '<?= MAX_DEPARTURES ?>';
    });
  });

  editForm?.addEventListener('submit', async e => {
    e.preventDefault();
    clearAlerts('editAlerts');
    const fd = new FormData(e.target);
    fd.delete('csrf_token');
    const res = await adminPost('admin_user_edit', Object.fromEntries(fd));
    if (res.ok) {
      showAlert('Gespeichert.', 'success');
      closeModal('editModal');
      setTimeout(() => location.reload(), 700);
    } else {
      showAlert(errMsg(res), 'danger', 'editAlerts');
    }
  });

  createForm?.addEventListener('submit', async e => {
    e.preventDefault();
    clearAlerts('createAlerts');
    const fd = new FormData(e.target);
    fd.delete('csrf_token');
    const res = await adminPost('admin_user_create', Object.fromEntries(fd));
    if (res.ok) {
      showAlert('Einladung versandt an ' + fd.get('email') + '.', 'success');
      closeModal('createModal');
      e.target.reset();
      setTimeout(() => location.reload(), 700);
    } else {
      showAlert(errMsg(res), 'danger', 'createAlerts');
    }
  });

  document.querySelectorAll('.btn-toggle-disabled').forEach(btn => {
    btn.addEventListener('click', async () => {
      const isDisabled = btn.dataset.disabled === '1';
      const nextLabel  = isDisabled ? 'aktivieren' : 'deaktivieren';
      if (!await window.confirmDialog('Benutzer «' + btn.dataset.username + '» ' + nextLabel + '?', {
        titel: isDisabled ? 'Benutzer aktivieren' : 'Benutzer deaktivieren',
        okLabel: isDisabled ? 'Aktivieren' : 'Deaktivieren',
        gefahr: 'neutral',
      })) return;
      const res = await adminPost('admin_user_toggle_disabled', {
        id: btn.dataset.id,
        disabled: isDisabled ? '' : '1',
      });
      if (res.ok) {
        showAlert(isDisabled ? 'Aktiviert.' : 'Deaktiviert.', 'success');
        setTimeout(() => location.reload(), 700);
      } else {
        showAlert(errMsg(res), 'danger');
      }
    });
  });

  document.querySelectorAll('.btn-reset').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!await window.confirmDialog('Einladungs-/Reset-E-Mail an «' + btn.dataset.username + '» senden?', {
        titel: 'E-Mail senden',
        okLabel: 'Senden',
        gefahr: 'secondary',
      })) return;
      const res = await adminPost('admin_user_reset', { id: btn.dataset.id });
      showAlert(res.ok ? 'E-Mail versandt.' : errMsg(res), res.ok ? 'success' : 'danger');
    });
  });

  document.querySelectorAll('.btn-revoke-totp').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!await window.confirmDialog('2FA von «' + btn.dataset.username + '» widerrufen? Der Benutzer muss sich neu registrieren.', {
        titel: '2FA widerrufen',
        okLabel: 'Widerrufen',
        gefahr: 'secondary',
      })) return;
      const res = await adminPost('admin_user_revoke_totp', { id: btn.dataset.id });
      if (res.ok) {
        showAlert('2FA widerrufen.', 'success');
        setTimeout(() => location.reload(), 700);
      } else {
        showAlert(errMsg(res), 'danger');
      }
    });
  });

  document.querySelectorAll('.btn-invalid-reset').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!await window.confirmDialog('Fehlversuche (' + btn.dataset.count + ') für «' + btn.dataset.username + '» zurücksetzen?', {
        titel: 'Fehlversuche zurücksetzen',
        okLabel: 'Zurücksetzen',
        gefahr: 'secondary',
      })) return;
      const res = await adminPost('admin_user_reset_invalid', { id: btn.dataset.id });
      if (res.ok) {
        showAlert('Fehlversuche zurückgesetzt.', 'success');
        setTimeout(() => location.reload(), 700);
      } else {
        showAlert(errMsg(res), 'danger');
      }
    });
  });

  document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!await window.confirmDialog('Benutzer «' + btn.dataset.username + '» wirklich löschen?', {
        titel: 'Benutzer löschen',
        okLabel: 'Löschen',
        gefahr: 'commit',
      })) return;
      const res = await adminPost('admin_user_delete', { id: btn.dataset.id });
      if (res.ok) {
        showAlert('Gelöscht.', 'success');
        setTimeout(() => location.reload(), 700);
      } else {
        showAlert(errMsg(res), 'danger');
      }
    });
  });
});

// ── Log tab: AJAX load, filter, paginate (shared, erikr/chrome §15.1) ───────
initLogTab({
  endpoint:  'api.php',
  csrfToken: window.CSRF,
  perPage:   50,
});

// ── OGD updater (app-specific) ──────────────────────────────────────────────
// Uses window.apiForm (css/shared/js/api-call.js, loaded as a module above)
// instead of adminPost so a client-side timeout is distinguishable from a
// real server error — the server runs with set_time_limit(120) and
// ignore_user_abort(true), so a dropped connection does not necessarily mean
// the update itself failed.
document.getElementById('btnOgdUpdate').addEventListener('click', async () => {
  const btn    = document.getElementById('btnOgdUpdate');
  const logBox = document.getElementById('ogdLog');
  const logPre = document.getElementById('ogdLogPre');

  btn.disabled    = true;
  btn.textContent = 'Läuft...';
  logBox.hidden   = false;
  logPre.textContent = 'Aktualisiere Stationsdaten — kann bis zu 2 Minuten dauern, Seite nicht schließen…';

  try {
    const res = await window.apiForm('api.php?action=admin_ogd_update', { csrf_token: window.CSRF }, { timeoutMs: 130000 });
    logPre.textContent = (res.log ?? []).join('\n');
    if (res.ok) showAlert('OGD-Daten aktualisiert.', 'success');
    else        showAlert('Fehler: ' + (res.error ?? 'Unbekannt'), 'danger');
  } catch (e) {
    if (e.kind === 'timeout' || e.kind === 'network' || e.kind === 'abort') {
      logPre.textContent = 'Verbindung unterbrochen (' + e.message + ') — das Update kann serverseitig trotzdem durchgelaufen sein. Bitte Log prüfen.';
      showAlert('Verbindung unterbrochen — Update läuft ggf. serverseitig weiter, bitte Log prüfen.', 'danger');
    } else {
      // On a real server error (HTTP 500), api.php puts the full step-by-step
      // log into e.detail (ApiError, css/shared/js/api-call.js) — show that
      // in the log box instead of just the short e.message so the admin sees
      // the completed steps AND the error, like before the timeout/error
      // split (Review TASK-13).
      logPre.textContent = e.detail ? e.detail : 'Fehler: ' + e.message;
      // Trägt e.detail den mehrzeiligen Log, wäre e.message eine Log-Wand im
      // Alert-Banner — dann nur kurze Headline; sonst (z. B. Lock-Contention
      // "Update already in progress.") ist e.message kurz und konkret.
      showAlert(e.detail
        ? 'Fehler beim OGD-Update (HTTP ' + (e.status || '?') + ') — Details im Log unten.'
        : 'Fehler beim OGD-Update: ' + e.message, 'danger');
    }
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Jetzt aktualisieren';
  }
});
</script>

<?php render_footer(); ?>
