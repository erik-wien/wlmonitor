<?php
require_once(__DIR__ . '/../inc/initialize.php');
require_once(__DIR__ . '/../inc/stations.php');
require_once(__DIR__ . '/../inc/favorites.php');
require_once(__DIR__ . '/../inc/colors.php');

if (empty($_SESSION['loggedin'])) {
    header('Location: index.php'); exit;
}

$userID = (int) $_SESSION['id'];

// --- POST: save changes ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['alerts'][] = ['danger', 'Ungültige Anfrage.'];
        header('Location: index.php'); exit;
    }

    $favID  = (int) ($_POST['favID']  ?? 0);
    $title  = trim($_POST['title']    ?? '');
    $diva   = trim($_POST['diva']     ?? '');
    $bclass = trim($_POST['bclass']   ?? '');
    $sort   = (int) ($_POST['sort']   ?? 0);

    if ($favID === 0 || $title === '' || $diva === '') {
        $_SESSION['alerts'][] = ['danger', 'Bitte füllen Sie das Formular vollständig aus.'];
        header('Location: index.php'); exit;
    }

    // Ownership check
    $chk = $con->prepare('SELECT id FROM wl_favorites WHERE id = ? AND idUser = ?');
    $chk->bind_param('ii', $favID, $userID);
    $chk->execute();
    $chk->store_result();
    $found = $chk->num_rows > 0;
    $chk->close();

    if (!$found) {
        appendLog($con, 'edf', 'Unauthorized edit attempt on fav #' . $favID);
        $_SESSION['alerts'][] = ['danger', 'Favorit nicht gefunden.'];
        header('Location: index.php'); exit;
    }

    $diva   = sanitizeDivaInput($diva);
    $title  = mb_substr($title, 0, 100);
    $bclass = preg_replace('/[^a-z0-9\-]/', '', $bclass);

    $filterJson = isset($_POST['filter_json']) && $_POST['filter_json'] !== ''
        ? favorites_validate_filter((string) $_POST['filter_json'])
        : null;

    $stmt = $con->prepare('UPDATE wl_favorites SET title = ?, diva = ?, bclass = ?, sort = ?, filter_json = ? WHERE id = ? AND idUser = ?');
    $stmt->bind_param('sssisii', $title, $diva, $bclass, $sort, $filterJson, $favID, $userID);
    $stmt->execute();
    $stmt->close();

    appendLog($con, 'edf', 'Favourite #' . $favID . ' updated.');
    $_SESSION['alerts'][]  = ['success', 'Der Favorit wurde gespeichert.'];
    $_SESSION['loadFavId'] = $favID;
    header('Location: index.php'); exit;
}

// --- GET: show edit form ---
$favID = (int) ($_GET['favID'] ?? 0);
if ($favID === 0) {
    $_SESSION['alerts'][] = ['danger', 'Programmfehler: keine favID angegeben.'];
    header('Location: index.php'); exit;
}

$stmt = $con->prepare('SELECT id, title, diva, bclass, sort, filter_json FROM wl_favorites WHERE id = ? AND idUser = ?');
$stmt->bind_param('ii', $favID, $userID);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$res->free();
$stmt->close();

if (!$row) {
    appendLog($con, 'edf', 'Favourite #' . $favID . ' not found for user #' . $userID);
    $_SESSION['alerts'][] = ['danger', 'Favorit #' . $favID . ' nicht gefunden.'];
    header('Location: index.php'); exit;
}

// Extract filter entries
$existingFilter     = $row['filter_json'] ? json_decode($row['filter_json'], true) : null;
$existingFilterJson = json_encode($existingFilter ?? [], JSON_HEX_TAG | JSON_HEX_AMP);

// Build initial pill data from stored DIVAs
$existingDivas  = array_filter(array_map('trim', explode(',', $row['diva'])));
$divaDetails    = diva_info($con, $existingDivas);
$initialPills   = [];
foreach ($existingDivas as $d) {
    $initialPills[] = $divaDetails[$d]
        ?? ['diva' => $d, 'station' => $d, 'lines' => '', 'directions' => ''];
}
$initialPillsJson = json_encode($initialPills, JSON_HEX_TAG | JSON_HEX_AMP);

$theme = htmlspecialchars($_SESSION['theme'] ?? ($_COOKIE['theme'] ?? 'auto'), ENT_QUOTES, 'UTF-8');

$bclassOptions = [];
foreach (wl_palette_list() as $entry) {
    $bclassOptions[$entry['class']] = $entry['label'];
}
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>
<script nonce="<?= $_cspNonce ?>">
(function() {
  var t = <?= json_encode($theme) ?>;
  if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
})();
</script>

<div class="container-md mt-4">
  <h4 class="mb-3">Favorit bearbeiten</h4>

  <form method="post" action="editFavorite.php?favID=<?= $row['id'] ?>">
    <?= csrf_input() ?>
    <input type="hidden" name="favID" value="<?= $row['id'] ?>">

    <div class="mb-3">
      <label class="form-label" for="bclass">Farbe</label>
      <select id="bclass" name="bclass" class="form-select">
        <?php foreach ($bclassOptions as $val => $label): ?>
          <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>"
            <?= $row['bclass'] === $val ? 'selected' : '' ?>>
            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="mb-3">
      <label class="form-label" for="title">Bezeichnung</label>
      <input type="text" id="title" name="title"
             class="btn <?= htmlspecialchars($row['bclass'], ENT_QUOTES, 'UTF-8') ?> w-100 text-start"
             style="font-size:1rem"
             value="<?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>"
             maxlength="100" required autocomplete="off">
    </div>

    <div class="mb-3">
      <label class="form-label">Haltestellen</label>
      <input type="hidden" name="diva" id="divaHidden"
             value="<?= htmlspecialchars($row['diva'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="filter_json" id="filterHidden" value="">
      <div id="divaPills" class="d-flex flex-wrap gap-1 mb-2"
           style="min-height:1.5rem"></div>

      <div style="position:relative">
        <input type="text" id="divaSearch" class="form-control form-control-sm"
               placeholder="Haltestelle hinzufügen …" autocomplete="off">
        <ul id="divaResults" class="list-unstyled mb-0"
            style="display:none;position:absolute;z-index:200;width:100%;max-height:240px;
                   overflow-y:auto;background:var(--color-bg);border:1px solid var(--color-border);
                   border-radius:var(--radius);box-shadow:var(--shadow-sm)"></ul>
      </div>

      <div id="stationEditor" class="mt-2 p-2 border rounded" style="display:none">
        <div class="d-flex align-items-center gap-2 mb-2">
          <strong id="stationEditorName" class="flex-grow-1 text-truncate"></strong>
          <button type="button" id="stationEditorClose"
                  style="background:none;border:none;font-size:1.2em;line-height:1;cursor:pointer;color:var(--color-muted);padding:0"
                  title="Abbrechen">×</button>
        </div>
        <div id="filterLines" class="d-flex flex-column gap-1 mb-2"
             style="max-height:220px;overflow-y:auto;min-height:1.5rem"></div>
        <div class="form-text mb-2">Keine Auswahl = alle Linien dieser Haltestelle.</div>
        <button type="button" id="stationEditorOk" class="btn btn-sm btn-outline-success">OK</button>
      </div>

      <div class="form-text mt-1">Mindestens eine Haltestelle erforderlich.</div>
    </div>

    <div class="mb-3">
      <label class="form-label" for="sort">Rang</label>
      <input type="number" id="sort" name="sort" class="form-control"
             value="<?= (int) $row['sort'] ?>" min="0">
    </div>

    <button type="submit" class="btn btn-outline-success"><?= icon("save", "me-1") ?> Speichern</button>
    <a href="index.php" class="btn btn-secondary ms-2">Abbrechen</a>
  </form>
</div>

<script nonce="<?= $_cspNonce ?>">
(function () {
  // ── Color preview ─────────────────────────────────────────────────────────────
  const bclassSelect = document.getElementById('bclass');
  const titleInput   = document.getElementById('title');

  function applyColor() {
    const val = bclassSelect.value;
    titleInput.className = titleInput.className
      .split(' ').filter(c => !c.startsWith('btn')).join(' ');
    titleInput.classList.add('btn', val, 'w-100', 'text-start');
  }
  bclassSelect.addEventListener('change', applyColor);

  // ── Refs ─────────────────────────────────────────────────────────────────────
  const divaHidden    = document.getElementById('divaHidden');
  const filterHidden  = document.getElementById('filterHidden');
  const filterLines   = document.getElementById('filterLines');
  const stationEditor = document.getElementById('stationEditor');
  const editorName    = document.getElementById('stationEditorName');
  const pillBox       = document.getElementById('divaPills');
  const searchInput   = document.getElementById('divaSearch');
  const resultsList   = document.getElementById('divaResults');
  const saveBtn       = document.querySelector('button[type="submit"]');

  // ── Per-station filter state ──────────────────────────────────────────────────
  // {diva: [{line, platform}, …]}  — loaded from DB on page load
  let perStationFilter = <?= $existingFilterJson ?>;
  let editingDiva   = null;  // DIVA currently open in the editor
  let editingIsNew  = false; // true if this station is not yet in pills

  // ── Helpers ───────────────────────────────────────────────────────────────────
  function dirArrow(d) {
    if (!d || d.length !== 1) return '';
    return d === 'H' ? ' \u2192' : ' \u2190';
  }

  function makeLabel(info) {
    return info.station
      + (info.lines ? ' \u2013 ' + info.lines : '')
      + dirArrow(info.directions);
  }

  function makeSpinner() {
    const outer = document.createElement('span');
    outer.className = 'text-muted';
    outer.style.fontSize = '.85rem';
    const sp = document.createElement('span');
    sp.className = 'spinner-border spinner-border-sm me-1';
    sp.setAttribute('role', 'status');
    sp.setAttribute('aria-hidden', 'true');
    outer.append(sp, document.createTextNode(' Lade Linien \u2026'));
    return outer;
  }

  // ── Station editor ────────────────────────────────────────────────────────────
  function openEditor(diva, label, isNew) {
    editingDiva  = diva;
    editingIsNew = isNew;
    editorName.textContent = label;
    stationEditor.style.display = '';
    loadEditorLines(diva);
  }

  function closeEditor() {
    editingDiva  = null;
    editingIsNew = false;
    stationEditor.style.display = 'none';
    filterLines.replaceChildren();
  }

  async function loadEditorLines(diva) {
    filterLines.replaceChildren(makeSpinner());
    try {
      const url = new URL('api.php', location.href);
      url.searchParams.set('action', 'monitor');
      url.searchParams.set('diva', diva);
      const res = await fetch(url);
      buildEditorCheckboxes(await res.json(), perStationFilter[diva] ?? []);
    } catch {
      filterLines.textContent = 'Fehler beim Laden der Linien.';
    }
  }

  function buildEditorCheckboxes(data, preChecked) {
    filterLines.replaceChildren();
    const seen  = new Set();
    const lines = [];
    for (const [key, station] of Object.entries(data)) {
      if (key === 'trains' || key === 'update_at' || key === 'api_ping') continue;
      if (!Array.isArray(station?.lines)) continue;
      for (const l of station.lines) {
        const k = l.name + '|' + l.platform;
        if (!seen.has(k)) { seen.add(k); lines.push(l); }
      }
    }
    if (!lines.length) { filterLines.textContent = 'Keine Linien gefunden.'; return; }

    for (const l of lines) {
      const isChecked = preChecked.some(
        f => f.line === l.name && String(f.platform) === String(l.platform)
      );
      const label = document.createElement('label');
      label.className = 'd-flex align-items-center gap-2 px-2 py-1 rounded';
      label.style.cssText = 'cursor:pointer;font-size:.85rem;border:1px solid var(--color-border)';

      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'form-check-input mt-0 flex-shrink-0';
      cb.checked = isChecked;
      cb.value = JSON.stringify({ line: l.name, platform: l.platform });

      const lineName = document.createElement('span');
      lineName.style.cssText = 'font-weight:700;min-width:2.5em;flex-shrink:0';
      lineName.textContent = l.name;

      const plat = document.createElement('span');
      plat.style.cssText = 'color:var(--color-muted);flex-shrink:0;min-width:1.5em';
      const dirStr = l.direction === 'H' ? '\u2192' : l.direction === 'R' ? '\u2190' : '';
      plat.textContent = l.platform + (dirStr ? '\u00a0' + dirStr : '');

      const dest = document.createElement('span');
      dest.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--color-muted)';
      dest.textContent = l.towards ?? '';

      label.append(cb, lineName, plat, dest);
      filterLines.appendChild(label);
    }
  }

  document.getElementById('stationEditorOk')?.addEventListener('click', () => {
    if (!editingDiva) return;
    const checked = [...filterLines.querySelectorAll('input[type="checkbox"]:checked')];
    const selection = checked.map(cb => JSON.parse(cb.value));
    if (selection.length) {
      perStationFilter[editingDiva] = selection;
    } else {
      delete perStationFilter[editingDiva];
    }
    if (editingIsNew) addPillDirect(editingDiva, editorName.textContent);
    else renderPills(); // refresh indicator on existing pill
    closeEditor();
  });

  document.getElementById('stationEditorClose')?.addEventListener('click', closeEditor);

  // ── DIVA pills ────────────────────────────────────────────────────────────────
  let pills = []; // [{diva, label}, …]

  function syncHidden() {
    divaHidden.value = pills.map(p => p.diva).join(',');
    if (saveBtn) saveBtn.disabled = pills.length === 0;
  }

  function renderPills() {
    pillBox.replaceChildren();
    for (const p of pills) {
      const wrapper = document.createElement('span');
      wrapper.className = 'd-inline-flex align-items-center rounded overflow-hidden me-1 mb-1';
      wrapper.style.cssText = 'border:1px solid var(--color-primary);font-size:.8rem;line-height:1';

      const nameBtn = document.createElement('button');
      nameBtn.type = 'button';
      nameBtn.style.cssText = 'background:var(--color-primary);color:#fff;border:none;cursor:pointer;padding:.25em .5em';
      const hasFilter = (perStationFilter[p.diva]?.length ?? 0) > 0;
      nameBtn.textContent = p.label + (hasFilter ? ' \u25cf' : '');
      nameBtn.title = hasFilter ? 'Filter bearbeiten' : 'Linien filtern';
      nameBtn.addEventListener('click', () => openEditor(p.diva, p.label, false));

      const xBtn = document.createElement('button');
      xBtn.type = 'button';
      xBtn.textContent = '\u00d7';
      xBtn.title = 'Entfernen';
      xBtn.style.cssText = 'background:var(--color-primary);color:#fff;border:none;'
        + 'border-left:1px solid rgba(255,255,255,.35);cursor:pointer;padding:.25em .4em;opacity:.75';
      xBtn.addEventListener('click', () => {
        if (pills.length <= 1) return;
        pills = pills.filter(x => x.diva !== p.diva);
        delete perStationFilter[p.diva];
        if (editingDiva === p.diva) closeEditor();
        renderPills();
        syncHidden();
      });

      wrapper.append(nameBtn, xBtn);
      pillBox.appendChild(wrapper);
    }
    syncHidden();
  }

  function addPillDirect(diva, label) {
    if (pills.some(p => p.diva === diva)) { renderPills(); return; }
    pills.push({ diva, label });
    renderPills();
  }

  // Initialise pills from PHP-resolved data
  for (const info of <?= $initialPillsJson ?>) {
    pills.push({ diva: info.diva, label: makeLabel(info) });
  }
  renderPills();

  // Write per-station filters into filterHidden on submit.
  // Use filterHidden.form to target the edit form directly — Chrome\Header
  // renders a logout form earlier in the DOM which would otherwise match.
  filterHidden.form?.addEventListener('submit', () => {
    const relevant = {};
    for (const p of pills) {
      if (perStationFilter[p.diva]?.length) relevant[p.diva] = perStationFilter[p.diva];
    }
    filterHidden.value = Object.keys(relevant).length ? JSON.stringify(relevant) : '';
  });

  // ── Station search ────────────────────────────────────────────────────────────
  let allStations = [];
  fetch('api.php?action=stations')
    .then(r => r.json())
    .then(data => { allStations = data; })
    .catch(() => {});

  searchInput.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    resultsList.replaceChildren();
    if (q.length < 2) { resultsList.style.display = 'none'; return; }

    const matches = allStations
      .filter(s => s.station.toLowerCase().includes(q) || (s.lines || '').toLowerCase().includes(q))
      .slice(0, 40);
    if (!matches.length) { resultsList.style.display = 'none'; return; }

    for (const s of matches) {
      const li = document.createElement('li');
      li.style.cssText = 'padding:.4rem .75rem;cursor:pointer;border-bottom:1px solid var(--color-border);font-size:.9rem';
      li.textContent = makeLabel(s);
      li.addEventListener('mousedown', e => {
        e.preventDefault();
        searchInput.value = '';
        resultsList.style.display = 'none';
        const isNew = !pills.some(p => p.diva === s.diva);
        openEditor(s.diva, makeLabel(s), isNew);
      });
      resultsList.appendChild(li);
    }
    resultsList.style.display = '';
  });

  searchInput.addEventListener('blur', () => {
    setTimeout(() => { resultsList.style.display = 'none'; }, 150);
  });
})();
</script>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
