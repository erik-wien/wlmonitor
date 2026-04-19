<?php
require_once(__DIR__ . '/../inc/initialize.php');
require_once(__DIR__ . '/../inc/admin.php');
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
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>

<main id="main-content">
<div id="adminAlerts" class="container"></div>

<div class="container admin-page">
  <nav class="tab-bar" role="tablist" aria-label="Administration">
    <button type="button" class="tab-btn is-active" role="tab"
            id="tab-ogd" aria-controls="panel-ogd" aria-selected="true"
            data-tab="ogd">
      <?= icon("database") ?> Stationsdaten
    </button>
    <button type="button" class="tab-btn" role="tab"
            id="tab-users" aria-controls="panel-users" aria-selected="false"
            data-tab="users">
      <?= icon("users-cog") ?> Benutzerverwaltung
    </button>
    <button type="button" class="tab-btn" role="tab"
            id="tab-log" aria-controls="panel-log" aria-selected="false"
            data-tab="log">
      <?= icon("history") ?> Log
    </button>
  </nav>

  <!-- ── Tab: Stationsdaten ──────────────────────────────────────────── -->
  <section id="panel-ogd" class="tab-panel is-active"
           role="tabpanel" aria-labelledby="tab-ogd">
    <div class="card">
      <div class="card-header">Stationsdaten (OGD)</div>
      <div class="card-body">
        <p class="form-text">
          Lädt die aktuellen Haltestellen, Steige und Linien von
          data.wien.gv.at neu und ersetzt die lokalen Tabellen
          <code>ogd_haltestellen</code>, <code>ogd_steige</code>,
          <code>ogd_linien</code>.
        </p>
        <button id="btnOgdUpdate" type="button" class="btn btn-outline-success">
          <?= icon("sync") ?> Jetzt aktualisieren
        </button>
        <div id="ogdLog" class="ogd-log" hidden>
          <pre id="ogdLogPre"></pre>
        </div>
      </div>
    </div>
  </section>

  <!-- ── Tab: Benutzerverwaltung ─────────────────────────────────────── -->
  <section id="panel-users" class="tab-panel"
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
            [
                'key'    => 'debug',
                'label'  => 'Debug',
                'render' => static fn(array $u): string =>
                    ((int) ($u['debug'] ?? 0) === 1)
                        ? '<span class="badge badge-warning">ja</span>'
                        : '<span class="text-muted">–</span>',
            ],
        ],
    ]); ?>
  </section>

  <!-- ── Tab: Log ────────────────────────────────────────────────────── -->
  <section id="panel-log" class="tab-panel"
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
        [
            'key'   => 'debug',
            'label' => 'Debug-Modus',
            'type'  => 'checkbox',
            'help'  => 'Zeigt zusätzliche Diagnose-Ausgaben in der Aktivitätslog an.',
        ],
    ],
]); ?>

<script nonce="<?= $_cspNonce ?>">
window.CSRF = <?= json_encode($csrfToken) ?>;
</script>
<script src="css/shared/js/admin.js" nonce="<?= $_cspNonce ?>"></script>

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
      document.getElementById('editDebug').checked        = btn.dataset.debug === '1';
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
      if (!confirm('Benutzer «' + btn.dataset.username + '» ' + nextLabel + '?')) return;
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
      if (!confirm('Einladungs-/Reset-E-Mail an «' + btn.dataset.username + '» senden?')) return;
      const res = await adminPost('admin_user_reset', { id: btn.dataset.id });
      showAlert(res.ok ? 'E-Mail versandt.' : errMsg(res), res.ok ? 'success' : 'danger');
    });
  });

  document.querySelectorAll('.btn-revoke-totp').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm('2FA von «' + btn.dataset.username + '» widerrufen? Der Benutzer muss sich neu registrieren.')) return;
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
      if (!confirm('Fehlversuche (' + btn.dataset.count + ') für «' + btn.dataset.username + '» zurücksetzen?')) return;
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
      if (!confirm('Benutzer «' + btn.dataset.username + '» wirklich löschen?')) return;
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

// ── Log tab: AJAX load, filter, paginate ───────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const form      = document.getElementById('logFilterForm');
  const tbody     = document.getElementById('logTbody');
  const paginate  = document.getElementById('logPagination');
  const totalEl   = document.getElementById('logTotal');
  const appSel    = document.getElementById('log_app');
  const ctxSel    = document.getElementById('log_context');
  const fromInput = document.getElementById('log_from');
  const toInput   = document.getElementById('log_to');
  const resetBtn  = document.getElementById('logReset');

  let filtersInitialised = false;
  let loaded             = false;

  const today   = new Date();
  const weekAgo = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000);
  const ymd = d => d.toISOString().slice(0, 10);

  fromInput.value = ymd(weekAgo);
  toInput.value   = ymd(today);

  function addOption(sel, value) {
    const opt = document.createElement('option');
    opt.value = value;
    opt.textContent = value;
    sel.appendChild(opt);
  }

  function populateFilters(apps, contexts) {
    if (filtersInitialised) return;
    filtersInitialised = true;
    (apps     || []).forEach(a => addOption(appSel, a));
    (contexts || []).forEach(c => addOption(ctxSel, c));
  }

  function currentFilters() {
    return {
      app:     appSel.value,
      context: ctxSel.value,
      user:    document.getElementById('log_user').value.trim(),
      from:    fromInput.value.trim(),
      to:      toInput.value.trim(),
      q:       document.getElementById('log_q').value.trim(),
      fail:    document.getElementById('log_fail').checked ? '1' : '',
    };
  }

  function setPlaceholderRow(text) {
    tbody.replaceChildren();
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = 6;
    td.className = 'text-muted';
    td.textContent = text;
    tr.appendChild(td);
    tbody.appendChild(tr);
  }

  function renderRows(rows) {
    tbody.replaceChildren();
    if (!rows.length) { setPlaceholderRow('Keine Einträge gefunden.'); return; }
    for (const r of rows) {
      const tr = document.createElement('tr');
      const mk = (cls, text, muted) => {
        const td = document.createElement('td');
        if (cls) td.className = cls;
        if (text === null || text === undefined) {
          const sp = document.createElement('span');
          sp.className = 'text-muted';
          sp.textContent = '—';
          td.appendChild(sp);
        } else {
          td.textContent = text;
        }
        return td;
      };
      tr.appendChild(mk('log-time', r.logTime ?? ''));
      tr.appendChild(mk('', r.origin ?? ''));
      tr.appendChild(mk('', r.context ?? ''));
      tr.appendChild(mk('', r.username ?? null));
      tr.appendChild(mk('', r.ip ?? null));
      tr.appendChild(mk('log-activity', r.activity ?? ''));
      tbody.appendChild(tr);
    }
  }

  function renderPagination(page, total, perPage, onClick) {
    paginate.replaceChildren();
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    if (lastPage <= 1) return;
    for (let p = 1; p <= lastPage; p++) {
      const a = document.createElement('a');
      a.className = 'page-link' + (p === page ? ' active' : '');
      a.href = '#log';
      a.textContent = String(p);
      a.addEventListener('click', e => { e.preventDefault(); onClick(p); });
      paginate.appendChild(a);
    }
  }

  async function loadPage(page) {
    setPlaceholderRow('Lade…');
    const res = await adminPost('admin_log_list', { page, ...currentFilters() });
    if (!res.ok) {
      setPlaceholderRow('Fehler beim Laden.');
      showAlert(res.error || 'Log konnte nicht geladen werden.', 'danger');
      return;
    }
    populateFilters(res.apps, res.contexts);
    totalEl.textContent = String(res.total);
    renderRows(res.rows || []);
    renderPagination(res.page, res.total, res.per_page, loadPage);
  }

  form.addEventListener('submit', e => { e.preventDefault(); loadPage(1); });

  resetBtn.addEventListener('click', e => {
    e.preventDefault();
    appSel.value = '';
    ctxSel.value = '';
    document.getElementById('log_user').value   = '';
    document.getElementById('log_q').value      = '';
    document.getElementById('log_fail').checked = false;
    fromInput.value = ymd(weekAgo);
    toInput.value   = ymd(today);
    loadPage(1);
  });

  function maybeLoad() {
    if (loaded) return;
    if (location.hash === '#log') { loaded = true; loadPage(1); }
  }
  document.querySelectorAll('.tab-btn[data-tab="log"]').forEach(btn =>
    btn.addEventListener('click', () => { if (!loaded) { loaded = true; loadPage(1); } })
  );
  window.addEventListener('hashchange', maybeLoad);
  maybeLoad();
});

// ── OGD updater (app-specific) ──────────────────────────────────────────────
document.getElementById('btnOgdUpdate').addEventListener('click', async () => {
  const btn    = document.getElementById('btnOgdUpdate');
  const logBox = document.getElementById('ogdLog');
  const logPre = document.getElementById('ogdLogPre');

  btn.disabled    = true;
  btn.textContent = 'Läuft...';
  logBox.hidden   = false;
  logPre.textContent = 'Verbinde...';

  try {
    const res = await adminPost('admin_ogd_update', {});
    logPre.textContent = (res.log ?? []).join('\n');
    if (res.ok) showAlert('OGD-Daten aktualisiert.', 'success');
    else        showAlert('Fehler: ' + (res.error ?? 'Unbekannt'), 'danger');
  } catch (e) {
    logPre.textContent = 'Netzwerkfehler: ' + e.message;
    showAlert('Netzwerkfehler beim OGD-Update.', 'danger');
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Jetzt aktualisieren';
  }
});
</script>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
