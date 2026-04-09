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
$uname    = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$rights   = htmlspecialchars($_SESSION['rights']   ?? '', ENT_QUOTES, 'UTF-8');
// Logged-in users: DB preference (loaded into session at login) is authoritative
$theme = $loggedIn
    ? ($_SESSION['theme'] ?? 'auto')
    : ($_COOKIE['theme']  ?? 'auto');
$theme = htmlspecialchars($theme, ENT_QUOTES, 'UTF-8');
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>

<?php $avatarUrl = 'avatar.php?id=' . (int) ($_SESSION['id'] ?? 0); ?>
<nav class="navbar" id="mainNav">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php">
      <?= icon("subway", "me-1") ?> WL Monitor
    </a>

    <!-- Station search — always visible (outside collapse) -->
    <div id="stationSearchWrap" class="mx-2 flex-grow-1" style="position:relative;max-width:260px;">
      <div class="input-group input-group-sm">
        <input type="search" id="s" class="form-control" placeholder="Station suchen …" autocomplete="off">
        <button class="btn btn-nav" id="stationListToggle" type="button"
                tabindex="-1" title="Alle Stationen">
          <?= icon("chevron-down") ?>
        </button>
      </div>
      <div id="stationDropdown" class="station-dropdown" style="display:none;">
        <div class="station-dropdown-header">
          <div class="btn-group btn-group-sm w-100" role="group">
            <input type="radio" class="btn-check" name="stationSort"
                   id="sortAlpha" value="alpha" autocomplete="off" checked>
            <label class="btn btn-outline-secondary" for="sortAlpha">
              <?= icon("sort-alpha") ?> A–Z
            </label>
            <input type="radio" class="btn-check" name="stationSort"
                   id="sortDist" value="dist" autocomplete="off">
            <label class="btn btn-outline-secondary" for="sortDist">
              <?= icon("map-marker") ?> Nähe
            </label>
          </div>
        </div>
        <ul id="stationList" class="list-unstyled mb-0"></ul>
      </div>
    </div>

    <div class="ms-auto">
      <?php if ($loggedIn): ?>
        <div class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 px-2"
             href="#" role="button" data-dropdown-toggle aria-expanded="false">
            <img src="<?= $avatarUrl ?>" class="nav-avatar" alt="">
            <span class="d-none d-sm-inline">Profil</span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li>
              <span class="dropdown-item-text small text-muted">
                Angemeldet als <strong class="text-body"><?= $uname ?></strong>
              </span>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="preferences.php">
              <?= icon("user-cog", "me-2") ?>Einstellungen
            </a></li>
            <?php if ($rights === 'Admin'): ?>
              <li><a class="dropdown-item" href="admin.php">
                <?= icon("users-cog", "me-2") ?>Admin
              </a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li>
              <div class="dropdown-item-text py-1">
                <small class="text-muted d-block mb-1">Darstellung</small>
                <div class="btn-group btn-group-sm w-100" role="group" aria-label="Farbschema">
                  <button type="button" class="btn btn-outline-secondary<?= $theme === 'light' ? ' active' : '' ?>"
                          data-theme-btn="light" onclick="setTheme('light')" title="Hell">
                    <?= icon("sun") ?>
                  </button>
                  <button type="button" class="btn btn-outline-secondary<?= $theme === 'auto' ? ' active' : '' ?>"
                          data-theme-btn="auto" onclick="setTheme('auto')" title="Automatisch">
                    <?= icon("adjust") ?>
                  </button>
                  <button type="button" class="btn btn-outline-secondary<?= $theme === 'dark' ? ' active' : '' ?>"
                          data-theme-btn="dark" onclick="setTheme('dark')" title="Dunkel">
                    <?= icon("moon") ?>
                  </button>
                </div>
              </div>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <form method="post" action="logout.php">
                <?= csrf_input() ?>
                <button type="submit" class="dropdown-item">
                  <?= icon("sign-out", "me-2") ?>Logout
                </button>
              </form>
            </li>
          </ul>
        </div>
      <?php else: ?>
        <a class="nav-link px-2" href="login.php">
          <?= icon("key", "me-1") ?>
          <span class="d-none d-sm-inline">Login</span>
        </a>
      <?php endif; ?>
    </div>
  </div>
</nav>

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
