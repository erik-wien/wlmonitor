<?php
require_once(__DIR__ . '/../inc/initialize.php');
require_once(__DIR__ . '/../inc/state.php');
require_once(__DIR__ . '/../inc/colors.php');
require_once(__DIR__ . '/../inc/layout.php');
header('Content-Type: text/html; charset=utf-8');

// Flush session alerts for JS to consume
$alerts = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
$alertsJson = json_encode($alerts, JSON_HEX_TAG | JSON_HEX_AMP);

$userID     = (int) ($_SESSION['id'] ?? 0);
$loadFavId  = (int) ($_SESSION['loadFavId'] ?? 0);
unset($_SESSION['loadFavId']);
$loggedIn = !empty($_SESSION['loggedin']);
$initialDiva = null;
if ($loggedIn && !$loadFavId) {
    $state = state_load($con, $userID);
    if ($state['last_fav_id'] !== null) {
        $loadFavId = $state['last_fav_id']; // FK guarantees favourite still exists
    } elseif ($state['last_diva'] !== null) {
        $initialDiva = $state['last_diva'];
    }
}
$theme = $loggedIn
    ? ($_SESSION['theme'] ?? 'auto')
    : ($_COOKIE['theme']  ?? 'auto');
$theme = htmlspecialchars($theme, ENT_QUOTES, 'UTF-8');
?>
<?php render_header(true); ?>
<main id="main-content" tabindex="-1">
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
<div class="app-modal-backdrop" id="addFavModal" role="dialog" aria-modal="true"
     aria-labelledby="addFavModalTitle" aria-hidden="true" hidden>
  <div class="app-modal-dialog app-modal-sm">
    <div class="app-modal-header">
      <div class="app-modal-header-row">
        <h5 class="app-modal-title" id="addFavModalTitle"><?= icon("star", "me-1") ?> Als Favorit speichern</h5>
        <button type="button" class="app-modal-close" data-modal-close aria-label="Schließen">&times;</button>
      </div>
    </div>
    <div class="app-modal-body">
      <div class="mb-3">
        <label class="form-label" for="addFavTitle">Bezeichnung</label>
        <input type="text" id="addFavTitle" class="form-control" maxlength="100" required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="addFavColor">Farbe</label>
        <select id="addFavColor" class="form-select">
          <?php foreach (wl_palette_list() as $entry): ?>
            <option value="<?= htmlspecialchars($entry['class'], ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($entry['label'], ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="addFavLinesSection" style="display:none">
        <label class="form-label">Linien</label>
        <div id="addFavLines" class="d-flex flex-column gap-1 mb-1"
             style="max-height:200px;overflow-y:auto"></div>
        <div class="form-text">Wähle die Linien aus, die dieser Favorit anzeigen soll.</div>
      </div>
    </div>
    <div class="app-modal-footer">
      <button type="button" class="btn" data-modal-close>Abbrechen</button>
      <button type="button" class="btn btn-outline-danger" id="addFavSubmit"><?= icon("save", "me-1") ?> Speichern</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Pass PHP state to JS module -->
<script nonce="<?= $_cspNonce ?>">
window.wlConfig = {
  userID:      <?= $userID ?>,
  loggedIn:    <?= $loggedIn ? 'true' : 'false' ?>,
  theme:       <?= json_encode($theme) ?>,
  alerts:      <?= $alertsJson ?>,
  loadFavId:   <?= $loadFavId ?>,
  initialDiva: <?= $initialDiva !== null ? json_encode($initialDiva, JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>
};
</script>

<!-- App module -->
<?php $jsV = static function (string $rel): string {
    $m = @filemtime(__DIR__ . '/' . $rel);   // web/-relativer Pfad, analog zu $cssV in layout.php
    return $m ? '?v=' . $m : '';
}; ?>
<script src="js/vendor/Sortable.min.js<?= $jsV('js/vendor/Sortable.min.js') ?>" nonce="<?= $_cspNonce ?>"></script>
<script type="module" src="js/wl-monitor.js<?= $jsV('js/wl-monitor.js') ?>"></script>

</main>
<?php render_footer(); ?>
