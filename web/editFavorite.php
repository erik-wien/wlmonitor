<?php
require_once(__DIR__ . '/../include/initialize.php');

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
        appendLog($con, 'edf', 'Unauthorized edit attempt on fav #' . $favID, 'web');
        $_SESSION['alerts'][] = ['danger', 'Favorit nicht gefunden.'];
        header('Location: index.php'); exit;
    }

    $diva   = sanitizeDivaInput($diva);
    $title  = mb_substr($title, 0, 100);
    $bclass = preg_replace('/[^a-z0-9\-]/', '', $bclass);

    $stmt = $con->prepare('UPDATE wl_favorites SET title = ?, diva = ?, bclass = ?, sort = ? WHERE id = ? AND idUser = ?');
    $stmt->bind_param('sssiii', $title, $diva, $bclass, $sort, $favID, $userID);
    $stmt->execute();
    $stmt->close();

    appendLog($con, 'edf', 'Favourite #' . $favID . ' updated.', 'web');
    $_SESSION['alerts'][] = ['success', 'Der Favorit wurde gespeichert.'];
    header('Location: index.php'); exit;
}

// --- GET: show edit form ---
$favID = (int) ($_GET['favID'] ?? 0);
if ($favID === 0) {
    $_SESSION['alerts'][] = ['danger', 'Programmfehler: keine favID angegeben.'];
    header('Location: index.php'); exit;
}

$stmt = $con->prepare('SELECT id, title, diva, bclass, sort FROM wl_favorites WHERE id = ? AND idUser = ?');
$stmt->bind_param('ii', $favID, $userID);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    appendLog($con, 'edf', 'Favourite #' . $favID . ' not found for user #' . $userID, 'web');
    $_SESSION['alerts'][] = ['danger', 'Favorit #' . $favID . ' nicht gefunden.'];
    header('Location: index.php'); exit;
}

$theme = htmlspecialchars($_SESSION['theme'] ?? ($_COOKIE['theme'] ?? 'auto'), ENT_QUOTES, 'UTF-8');
$uname = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');

$bclassOptions = [
    'btn-outline-default'   => 'Standard',
    'btn-outline-primary'   => 'Blau',
    'btn-outline-success'   => 'Grün',
    'btn-outline-info'      => 'Cyan',
    'btn-outline-warning'   => 'Orange',
    'btn-outline-danger'    => 'Rot',
    'btn-outline-secondary' => 'Grau',
    'btn-outline-dark'      => 'Dunkel',
];
?>
<?php include_once(__DIR__ . '/../include/html_header.php'); ?>
<script nonce="<?= $_cspNonce ?>">
(function() {
  var t = <?= json_encode($theme) ?>;
  if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
})();
</script>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php"><i class="fas fa-subway me-1"></i> WL Monitor</a>
    <div class="navbar-nav ms-auto align-items-center gap-1">
      <span class="nav-link text-light"><?= $uname ?></span>
      <a class="nav-link text-light" href="index.php" title="Zurück"><i class="fas fa-arrow-left"></i></a>
    </div>
  </div>
</nav>

<div class="container mt-4" style="max-width:520px">
  <h4 class="mb-3">Favorit bearbeiten</h4>

  <form method="post" action="editFavorite.php?favID=<?= $row['id'] ?>">
    <?= csrf_input() ?>
    <input type="hidden" name="favID" value="<?= $row['id'] ?>">

    <div class="mb-3">
      <label class="form-label" for="title">Bezeichnung</label>
      <input type="text" id="title" name="title" class="form-control"
             value="<?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>"
             maxlength="100" required autocomplete="off">
    </div>

    <div class="mb-3">
      <label class="form-label" for="diva">DIVA-Nummern</label>
      <input type="text" id="diva" name="diva" class="form-control"
             value="<?= htmlspecialchars($row['diva'], ENT_QUOTES, 'UTF-8') ?>"
             placeholder="z.B. 60200103,60200104" required autocomplete="off">
      <div class="form-text">Kommagetrennte DIVA/RBL-Nummern der Haltestellen.</div>
    </div>

    <div class="mb-3">
      <label class="form-label" for="sort">Rang</label>
      <input type="number" id="sort" name="sort" class="form-control"
             value="<?= (int) $row['sort'] ?>" min="0">
    </div>

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

    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Speichern</button>
    <a href="index.php" class="btn btn-secondary ms-2">Abbrechen</a>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous"></script>

<?php include_once(__DIR__ . '/../include/html_footer.php'); ?>
