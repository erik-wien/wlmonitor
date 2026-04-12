<?php
require_once(__DIR__ . '/../inc/initialize.php');
header('Content-Type: text/html; charset=utf-8');

// Flush session alerts for JS to consume
$alerts = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
$alertsJson = json_encode($alerts, JSON_HEX_TAG | JSON_HEX_AMP);

$userID     = (int) ($_SESSION['id'] ?? 0);
$loadFavId  = (int) ($_SESSION['loadFavId'] ?? 0);
unset($_SESSION['loadFavId']);
$loggedIn = !empty($_SESSION['loggedin']);
// html_header.php reads theme from session/cookie — pass it to wlConfig for JS use
$theme = $loggedIn
    ? ($_SESSION['theme'] ?? 'auto')
    : ($_COOKIE['theme']  ?? 'auto');
$theme = htmlspecialchars($theme, ENT_QUOTES, 'UTF-8');
$show_search = true;  // show station search in the shared .app-header
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>

<div id="alerts" class="container-fluid mt-2"></div>
<?= csrf_input() ?>

<div class="container-fluid mt-2">
  <div class="row">

    <!-- Monitor panel -->
    <div class="col-md-8">
      <div id="monitor" class="mb-1">
        <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
        <span class="ms-2 text-muted">Lade Abfahrten ...</span>
      </div>
    </div>

    <!-- Sidebar -->
    <div class="col-md-4">
      <div id="buttons"></div>
    </div>

  </div>
</div>

<button id="topBtn" class="btn btn-secondary btn-sm"
        title="Nach oben">
  <?= icon("arrow-up") ?>
</button>

<?php if ($loggedIn): ?>
<!-- Add-favourite modal -->
<div class="modal" id="addFavModal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?= icon("star", "me-1") ?> Als Favorit speichern</h5>
        <button type="button" class="btn-close" data-modal-close></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="addFavTitle">Bezeichnung</label>
          <input type="text" id="addFavTitle" class="form-control" maxlength="100" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="addFavColor">Farbe</label>
          <select id="addFavColor" class="form-select">
            <option value="btn-outline-default">Standard</option>
            <option value="btn-outline-primary">Blau</option>
            <option value="btn-outline-success">Grün</option>
            <option value="btn-outline-info">Cyan</option>
            <option value="btn-outline-warning">Orange</option>
            <option value="btn-outline-danger">Rot</option>
            <option value="btn-outline-secondary">Grau</option>
            <option value="btn-outline-dark">Dunkel</option>
          </select>
        </div>
        <div id="addFavLinesSection" style="display:none">
          <label class="form-label">Linien</label>
          <div id="addFavLines" class="d-flex flex-column gap-1 mb-1"
               style="max-height:200px;overflow-y:auto"></div>
          <div class="form-text">Wähle die Linien aus, die dieser Favorit anzeigen soll.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-modal-close>Abbrechen</button>
        <button type="button" class="btn btn-primary" id="addFavSubmit"><?= icon("save", "me-1") ?> Speichern</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Pass PHP state to JS module -->
<script nonce="<?= $_cspNonce ?>">
window.wlConfig = {
  userID:    <?= $userID ?>,
  loggedIn:  <?= $loggedIn ? 'true' : 'false' ?>,
  theme:     <?= json_encode($theme) ?>,
  alerts:    <?= $alertsJson ?>,
  loadFavId: <?= $loadFavId ?>
};
</script>

<!-- App module -->
<script type="module" src="js/wl-monitor.js"></script>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
