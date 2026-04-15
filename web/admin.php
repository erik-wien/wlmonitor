<?php
require_once(__DIR__ . '/../inc/initialize.php');
require_once(__DIR__ . '/../inc/admin.php');
require_once(__DIR__ . '/../inc/colors.php');

if (empty($_SESSION['loggedin']) || ($_SESSION['rights'] ?? '') !== 'Admin') {
    header('Location: index.php'); exit;
}

header('Content-Type: text/html; charset=utf-8');

$page   = max(1, (int) ($_GET['page'] ?? 1));
$filter = htmlspecialchars($_GET['filter'] ?? '', ENT_QUOTES, 'UTF-8');
$data   = wl_admin_list_users($con, $page, 25, $filter);
$users  = $data['users'];
$total  = $data['total'];
$pages  = (int) ceil($total / 25);

$colors    = wl_colors_list($con);
$csrfToken = csrf_token();
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>

<div id="adminAlerts" class="container"></div>

<div class="container admin-page">
  <div class="tabs" role="tablist" aria-label="Admin-Bereich">
    <button type="button" class="tab-btn is-active" role="tab"
            id="tab-colors" aria-controls="panel-colors" aria-selected="true"
            data-tab="colors">
      <?= icon("palette") ?> Farben
    </button>
    <button type="button" class="tab-btn" role="tab"
            id="tab-ogd" aria-controls="panel-ogd" aria-selected="false"
            data-tab="ogd">
      <?= icon("database") ?> Stationsdaten
    </button>
    <button type="button" class="tab-btn" role="tab"
            id="tab-users" aria-controls="panel-users" aria-selected="false"
            data-tab="users">
      <?= icon("users-cog") ?> Benutzerverwaltung
    </button>
  </div>

  <!-- ── Tab: Farben ───────────────────────────────────────────────────── -->
  <section id="panel-colors" class="tab-panel is-active"
           role="tabpanel" aria-labelledby="tab-colors">
    <div class="card">
      <div class="card-header">Favorit-Farben</div>
      <div class="card-body">
        <p class="form-text">
          Die Schaltflächenklassen sind fest im Theme verdrahtet, die deutschen
          Bezeichnungen können hier umbenannt werden. Änderungen wirken sich
          sofort im Favoriten-Editor aus.
        </p>

        <table class="table table-sm color-table">
          <thead>
            <tr>
              <th>Vorschau</th>
              <th>Bezeichnung</th>
              <th>Klasse (outline)</th>
              <th>Klasse (voll)</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="colorTableBody">
          <?php foreach ($colors as $c):
              $color = htmlspecialchars($c['color'],   ENT_QUOTES, 'UTF-8');
              $farbe = htmlspecialchars($c['farbe'],   ENT_QUOTES, 'UTF-8');
              $out   = htmlspecialchars($c['outline'], ENT_QUOTES, 'UTF-8');
              $full  = htmlspecialchars($c['full'],    ENT_QUOTES, 'UTF-8');
          ?>
            <tr data-color="<?= $color ?>">
              <td>
                <span class="btn btn-sm <?= $out ?>"><?= $farbe ?></span>
                <span class="btn btn-sm <?= $full ?>"><?= $farbe ?></span>
              </td>
              <td class="color-label"><?= $farbe ?></td>
              <td><code><?= $out ?></code></td>
              <td><code><?= $full ?></code></td>
              <td class="text-right">
                <button type="button" class="btn btn-sm btn-outline-primary btn-edit-color"
                        data-color="<?= $color ?>"
                        data-farbe="<?= $farbe ?>">
                  Bearbeiten
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ── Tab: Stationsdaten ────────────────────────────────────────────── -->
  <section id="panel-ogd" class="tab-panel"
           role="tabpanel" aria-labelledby="tab-ogd" hidden>
    <div class="card">
      <div class="card-header">Stationsdaten (OGD)</div>
      <div class="card-body">
        <p class="form-text">
          Lädt die aktuellen Haltestellen, Steige und Linien von
          data.wien.gv.at neu und ersetzt die lokalen Tabellen
          <code>ogd_haltestellen</code>, <code>ogd_steige</code>,
          <code>ogd_linien</code>.
        </p>
        <button id="btnOgdUpdate" type="button" class="btn btn-outline-primary">
          <?= icon("sync") ?> Jetzt aktualisieren
        </button>
        <div id="ogdLog" class="ogd-log" hidden>
          <pre id="ogdLogPre"></pre>
        </div>
      </div>
    </div>
  </section>

  <!-- ── Tab: Benutzerverwaltung ───────────────────────────────────────── -->
  <section id="panel-users" class="tab-panel"
           role="tabpanel" aria-labelledby="tab-users" hidden>
    <div class="card">
      <div class="card-header card-header-split">
        <span>Benutzer</span>
        <button class="btn btn-sm btn-success" data-modal-open="createModal">
          <?= icon("user-plus") ?> Benutzer anlegen
        </button>
      </div>
      <div class="card-body">
        <form class="user-filter-form" method="get">
          <input type="text" name="filter" class="form-control form-control-sm"
                 placeholder="Username suchen" value="<?= $filter ?>">
          <button class="btn btn-sm btn-secondary" type="submit">
            <?= icon("search") ?>
          </button>
        </form>

        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead>
              <tr>
                <th>ID</th><th>Username</th><th>E-Mail</th><th>Rechte</th>
                <th>Aktiv</th><th>Abfahrten</th><th>Debug</th><th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td><?= $u['id'] ?></td>
                <td><?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($u['email'],    ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($u['rights'],   ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= $u['disabled'] ? 'gesperrt' : 'aktiv' ?></td>
                <td><?= $u['departures'] ?></td>
                <td><?= $u['debug'] ? 'ja' : '' ?></td>
                <td class="text-nowrap">
                  <button class="btn btn-sm btn-outline-primary btn-edit"
                    data-id="<?= $u['id'] ?>"
                    data-email="<?= htmlspecialchars($u['email'],    ENT_QUOTES, 'UTF-8') ?>"
                    data-rights="<?= htmlspecialchars($u['rights'],  ENT_QUOTES, 'UTF-8') ?>"
                    data-disabled="<?= $u['disabled'] ?>"
                    data-departures="<?= $u['departures'] ?>"
                    data-debug="<?= $u['debug'] ?>"
                    data-modal-open="editModal">
                    Bearbeiten
                  </button>
                  <button class="btn btn-sm btn-outline-warning btn-reset"
                          data-id="<?= $u['id'] ?>">Passwort</button>
                  <button class="btn btn-sm btn-outline-danger btn-delete"
                          data-id="<?= $u['id'] ?>">Löschen</button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($pages > 1): ?>
        <nav class="pagination">
          <?php for ($p = 1; $p <= $pages; $p++): ?>
            <a class="page-link <?= $p === $page ? 'is-active' : '' ?>"
               href="?page=<?= $p ?>&amp;filter=<?= urlencode($filter) ?>"><?= $p ?></a>
          <?php endfor; ?>
        </nav>
        <?php endif; ?>
      </div>
    </div>
  </section>
</div>

<!-- Edit Color Modal -->
<div class="modal" id="colorModal" role="dialog" aria-labelledby="colorModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="colorModalLabel">Farbe bearbeiten</h5>
        <button type="button" class="btn-close" data-modal-close aria-label="Schließen"></button>
      </div>
      <form id="colorForm">
        <div class="modal-body">
          <input type="hidden" name="color" id="colorKey">
          <div class="mb-2">
            <label class="form-label" for="colorFarbe">Bezeichnung</label>
            <input type="text" name="farbe" id="colorFarbe"
                   class="form-control" maxlength="50" required autocomplete="off">
          </div>
          <div class="form-text" id="colorClassHint"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-modal-close>Abbrechen</button>
          <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal" id="editModal" role="dialog" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalLabel">Benutzer bearbeiten</h5>
        <button type="button" class="btn-close" data-modal-close aria-label="Schließen"></button>
      </div>
      <form id="editForm">
        <div class="modal-body">
          <input type="hidden" name="id" id="editId">
          <div class="mb-2">
            <label class="form-label" for="editEmail">E-Mail</label>
            <input type="email" name="email" id="editEmail" class="form-control">
          </div>
          <div class="mb-2">
            <label class="form-label" for="editRights">Rechte</label>
            <select name="rights" id="editRights" class="form-select">
              <option value="User">User</option>
              <option value="Admin">Admin</option>
            </select>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="disabled"
                   id="editDisabled" value="1">
            <label class="form-check-label" for="editDisabled">Gesperrt</label>
          </div>
          <div class="mb-2">
            <label class="form-label" for="editDepartures">Abfahrten</label>
            <input type="number" name="departures" id="editDepartures"
                   class="form-control" min="1" max="10">
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="debug"
                   id="editDebug" value="1">
            <label class="form-check-label" for="editDebug">Debug</label>
          </div>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="totp_reset"
                   id="editTotpReset" value="1">
            <label class="form-check-label" for="editTotpReset">2FA zurücksetzen</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-modal-close>Abbrechen</button>
          <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Create User Modal -->
<div class="modal" id="createModal" role="dialog" aria-labelledby="createModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="createModalLabel">Benutzer anlegen</h5>
        <button type="button" class="btn-close" data-modal-close aria-label="Schließen"></button>
      </div>
      <form id="createForm">
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label" for="createUsername">Benutzername</label>
            <input type="text" name="username" id="createUsername"
                   class="form-control" required autocomplete="off">
          </div>
          <div class="mb-2">
            <label class="form-label" for="createEmail">E-Mail</label>
            <input type="email" name="email" id="createEmail" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label" for="createRights">Rechte</label>
            <select name="rights" id="createRights" class="form-select">
              <option value="User">User</option>
              <option value="Admin">Admin</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-modal-close>Abbrechen</button>
          <button type="submit" class="btn btn-success">Einladung senden</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script nonce="<?= $_cspNonce ?>">
const CSRF = <?= json_encode($csrfToken) ?>;

// ── Tabs ────────────────────────────────────────────────────────────────────

function activateTab(name) {
  document.querySelectorAll('.tab-btn').forEach(b => {
    const active = b.dataset.tab === name;
    b.classList.toggle('is-active', active);
    b.setAttribute('aria-selected', active ? 'true' : 'false');
  });
  document.querySelectorAll('.tab-panel').forEach(p => {
    const active = p.id === 'panel-' + name;
    p.classList.toggle('is-active', active);
    p.hidden = !active;
  });
  try { history.replaceState(null, '', '#' + name); } catch (_) {}
}

document.querySelectorAll('.tab-btn').forEach(b =>
  b.addEventListener('click', () => activateTab(b.dataset.tab))
);

const initialTab = (location.hash || '').replace('#', '');
if (['colors', 'ogd', 'users'].includes(initialTab)) activateTab(initialTab);

// ── Modals ──────────────────────────────────────────────────────────────────

function openModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.add('show'); m.setAttribute('aria-hidden', 'false'); }
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.remove('show'); m.setAttribute('aria-hidden', 'true'); }
}
document.querySelectorAll('[data-modal-open]').forEach(btn =>
  btn.addEventListener('click', () => openModal(btn.dataset.modalOpen))
);
document.querySelectorAll('[data-modal-close]').forEach(btn =>
  btn.addEventListener('click', () => {
    const m = btn.closest('.modal');
    if (m) closeModal(m.id);
  })
);
document.querySelectorAll('.modal').forEach(m =>
  m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); })
);

// ── Alerts ──────────────────────────────────────────────────────────────────

function showAlert(msg, type) {
  const container = document.getElementById('adminAlerts');
  const div = document.createElement('div');
  div.className = 'alert alert-' + (type || 'info');
  div.textContent = msg;
  container.appendChild(div);
  setTimeout(() => div.remove(), 5000);
}

async function adminPost(action, params) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf_token', CSRF);
  for (const [k, v] of Object.entries(params)) fd.append(k, v);
  const r = await fetch('api.php', { method: 'POST', body: fd });
  return r.json();
}

// ── Color editor ────────────────────────────────────────────────────────────

document.querySelectorAll('.btn-edit-color').forEach(btn => {
  btn.addEventListener('click', () => {
    const row = btn.closest('tr');
    document.getElementById('colorKey').value    = btn.dataset.color;
    document.getElementById('colorFarbe').value  = btn.dataset.farbe;
    document.getElementById('colorClassHint').textContent =
      'Schlüssel: ' + btn.dataset.color;
    openModal('colorModal');
  });
});

document.getElementById('colorForm').addEventListener('submit', async e => {
  e.preventDefault();
  const fd  = new FormData(e.target);
  const res = await adminPost('admin_color_edit', Object.fromEntries(fd));
  if (res.ok) {
    showAlert('Gespeichert.', 'success');
    closeModal('colorModal');
    setTimeout(() => location.reload(), 600);
  } else {
    showAlert('Fehler beim Speichern.', 'danger');
  }
});

// ── User editor ─────────────────────────────────────────────────────────────

document.querySelectorAll('.btn-edit').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('editId').value          = btn.dataset.id;
    document.getElementById('editEmail').value       = btn.dataset.email;
    document.getElementById('editRights').value      = btn.dataset.rights;
    document.getElementById('editDisabled').checked  = btn.dataset.disabled === '1';
    document.getElementById('editDepartures').value  = btn.dataset.departures;
    document.getElementById('editDebug').checked     = btn.dataset.debug === '1';
    document.getElementById('editTotpReset').checked = false;
  });
});

document.getElementById('editForm').addEventListener('submit', async e => {
  e.preventDefault();
  const fd  = new FormData(e.target);
  const res = await adminPost('admin_user_edit', Object.fromEntries(fd));
  if (res.ok) {
    showAlert('Gespeichert.', 'success');
    closeModal('editModal');
    setTimeout(() => location.reload(), 900);
  } else {
    showAlert('Fehler beim Speichern.', 'danger');
  }
});

document.querySelectorAll('.btn-reset').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!confirm('Passwort-Reset-E-Mail an Benutzer #' + btn.dataset.id + ' senden?')) return;
    const res = await adminPost('admin_user_reset', { id: btn.dataset.id });
    if (res.ok) showAlert('E-Mail versandt.', 'success');
    else        showAlert('Fehler beim Zurücksenden.', 'danger');
  });
});

document.getElementById('createForm').addEventListener('submit', async e => {
  e.preventDefault();
  const fd  = new FormData(e.target);
  const res = await adminPost('admin_user_create', Object.fromEntries(fd));
  if (res.ok) {
    showAlert('Einladung versandt an ' + fd.get('email') + '.', 'success');
    closeModal('createModal');
    e.target.reset();
  } else {
    showAlert('Fehler: ' + (res.error ?? 'Unbekannt'), 'danger');
  }
});

document.querySelectorAll('.btn-delete').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!confirm('Benutzer #' + btn.dataset.id + ' wirklich löschen?')) return;
    const res = await adminPost('admin_user_delete', { id: btn.dataset.id });
    if (res.ok) {
      showAlert('Gelöscht.', 'success');
      setTimeout(() => location.reload(), 900);
    } else {
      showAlert('Fehler beim Löschen.', 'danger');
    }
  });
});

// ── OGD updater ─────────────────────────────────────────────────────────────

document.getElementById('btnOgdUpdate').addEventListener('click', async () => {
  const btn    = document.getElementById('btnOgdUpdate');
  const logBox = document.getElementById('ogdLog');
  const logPre = document.getElementById('ogdLogPre');

  btn.disabled = true;
  btn.textContent = 'Läuft...';
  logBox.hidden = false;
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
    btn.disabled = false;
    btn.textContent = 'Jetzt aktualisieren';
  }
});
</script>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
