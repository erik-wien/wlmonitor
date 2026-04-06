<?php
require_once(__DIR__ . '/../include/initialize.php');
require_once(__DIR__ . '/../inc/admin.php');

if (empty($_SESSION['loggedin']) || ($_SESSION['rights'] ?? '') !== 'Admin') {
    header('Location: index.php'); exit;
}

header('Content-Type: text/html; charset=utf-8');

$page   = max(1, (int) ($_GET['page'] ?? 1));
$filter = htmlspecialchars($_GET['filter'] ?? '', ENT_QUOTES, 'UTF-8');
$data   = admin_list_users($con, $page, 25, $filter);
$users  = $data['users'];
$total  = $data['total'];
$pages  = (int) ceil($total / 25);

$csrfToken = csrf_token();
?>
<?php include_once(__DIR__ . '/../include/html_header.php'); ?>

<nav class="navbar" id="mainNav">
  <div class="container-fluid">
    <span class="navbar-brand fw-semibold">
      <i class="fas fa-users-cog me-1"></i> Benutzerverwaltung
    </span>
    <a href="index.php" class="btn btn-sm btn-nav ms-auto">
      <i class="fas fa-arrow-left me-1"></i> Monitor
    </a>
  </div>
</nav>

<div id="adminAlerts" class="container-fluid"></div>

<div class="container-fluid mb-3">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="fas fa-database me-1"></i> Stationsdaten (OGD)</span>
      <button id="btnOgdUpdate" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-sync-alt me-1"></i> Jetzt aktualisieren
      </button>
    </div>
    <div id="ogdLog" class="card-body p-2" style="display:none">
      <pre id="ogdLogPre" class="mb-0 small" style="white-space:pre-wrap"></pre>
    </div>
  </div>
</div>

<div class="container-fluid">
  <form class="d-flex gap-2 mb-3" method="get">
    <input type="text" name="filter" class="form-control form-control-sm w-auto"
           placeholder="Username suchen" value="<?= $filter ?>">
    <button class="btn btn-sm btn-secondary" type="submit">
      <i class="fas fa-search"></i>
    </button>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-hover">
      <thead class="table-dark">
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
              data-bs-toggle="modal" data-bs-target="#editModal">
              Bearbeiten
            </button>
            <button class="btn btn-sm btn-outline-warning btn-reset"
                    data-id="<?= $u['id'] ?>">Passwort</button>
            <button class="btn btn-sm btn-outline-danger btn-delete"
                    data-id="<?= $u['id'] ?>">Loschen</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <nav><ul class="pagination pagination-sm">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link"
           href="?page=<?= $p ?>&amp;filter=<?= urlencode($filter) ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
  </ul></nav>
  <?php endif; ?>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalLabel">Benutzer bearbeiten</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="editForm">
        <div class="modal-body">
          <input type="hidden" name="id" id="editId">
          <input type="hidden" name="csrf_token"
                 value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
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
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary"
                  data-bs-dismiss="modal">Abbrechen</button>
          <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous"></script>
<script nonce="<?= $_cspNonce ?>">
const CSRF = <?= json_encode($csrfToken) ?>;

function showAlert(msg, type) {
  const container = document.getElementById('adminAlerts');
  const div = document.createElement('div');
  div.className = 'alert alert-' + (type || 'info') + ' alert-dismissible fade show';
  div.textContent = msg;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn-close';
  btn.dataset.bsDismiss = 'alert';
  div.appendChild(btn);
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

// Populate edit modal from button data attributes
document.querySelectorAll('.btn-edit').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('editId').value          = btn.dataset.id;
    document.getElementById('editEmail').value       = btn.dataset.email;
    document.getElementById('editRights').value      = btn.dataset.rights;
    document.getElementById('editDisabled').checked  = btn.dataset.disabled === '1';
    document.getElementById('editDepartures').value  = btn.dataset.departures;
    document.getElementById('editDebug').checked     = btn.dataset.debug === '1';
  });
});

document.getElementById('editForm').addEventListener('submit', async e => {
  e.preventDefault();
  const fd  = new FormData(e.target);
  const res = await adminPost('admin_user_edit', Object.fromEntries(fd));
  if (res.ok) {
    showAlert('Gespeichert.', 'success');
    bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
    setTimeout(() => location.reload(), 900);
  } else {
    showAlert('Fehler beim Speichern.', 'danger');
  }
});

document.querySelectorAll('.btn-reset').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!confirm('Passwort fur Benutzer #' + btn.dataset.id + ' zurucksetzen?')) return;
    const res = await adminPost('admin_user_reset', { id: btn.dataset.id });
    if (res.password) {
      showAlert('Neues Passwort: ' + res.password, 'warning');
    } else {
      showAlert('Fehler beim Zurucksetzen.', 'danger');
    }
  });
});

document.getElementById('btnOgdUpdate').addEventListener('click', async () => {
  const btn    = document.getElementById('btnOgdUpdate');
  const logBox = document.getElementById('ogdLog');
  const logPre = document.getElementById('ogdLogPre');

  btn.disabled = true;
  btn.textContent = 'Läuft...';
  logBox.style.display = 'block';
  logPre.textContent = 'Verbinde...';

  try {
    const res = await adminPost('admin_ogd_update', {});
    logPre.textContent = (res.log ?? []).join('\n');
    if (res.ok) {
      showAlert('OGD-Daten aktualisiert.', 'success');
    } else {
      showAlert('Fehler: ' + (res.error ?? 'Unbekannt'), 'danger');
    }
  } catch (e) {
    logPre.textContent = 'Netzwerkfehler: ' + e.message;
    showAlert('Netzwerkfehler beim OGD-Update.', 'danger');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Jetzt aktualisieren';
  }
});

document.querySelectorAll('.btn-delete').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!confirm('Benutzer #' + btn.dataset.id + ' wirklich loschen?')) return;
    const res = await adminPost('admin_user_delete', { id: btn.dataset.id });
    if (res.ok) {
      showAlert('Geloscht.', 'success');
      setTimeout(() => location.reload(), 900);
    } else {
      showAlert('Fehler beim Loschen.', 'danger');
    }
  });
});
</script>

<?php include_once(__DIR__ . '/../include/html_footer.php'); ?>
