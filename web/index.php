<?php
require_once(__DIR__ . '/../inc/initialize.php');
header('Content-Type: text/html; charset=utf-8');

// Flush session alerts for JS to consume
$alerts = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
$alertsJson = json_encode($alerts, JSON_HEX_TAG | JSON_HEX_AMP);

$userID   = (int) ($_SESSION['id'] ?? 0);
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
      <i class="fas fa-subway me-1"></i> WL Monitor
    </a>

    <!-- Station search — always visible (outside collapse) -->
    <div id="stationSearchWrap" class="mx-2 flex-grow-1" style="position:relative;max-width:260px;">
      <div class="input-group input-group-sm">
        <input type="search" id="s" class="form-control" placeholder="Station suchen …" autocomplete="off">
        <button class="btn btn-nav" id="stationListToggle" type="button"
                tabindex="-1" title="Alle Stationen">
          <i class="fas fa-chevron-down"></i>
        </button>
      </div>
      <div id="stationDropdown" class="station-dropdown" style="display:none;">
        <div class="station-dropdown-header">
          <div class="btn-group btn-group-sm w-100" role="group">
            <input type="radio" class="btn-check" name="stationSort"
                   id="sortAlpha" value="alpha" autocomplete="off" checked>
            <label class="btn btn-outline-secondary" for="sortAlpha">
              <i class="fas fa-sort-alpha-down"></i> A–Z
            </label>
            <input type="radio" class="btn-check" name="stationSort"
                   id="sortDist" value="dist" autocomplete="off">
            <label class="btn btn-outline-secondary" for="sortDist">
              <i class="fas fa-map-marker-alt"></i> Nähe
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
             href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <img src="<?= $avatarUrl ?>" class="nav-avatar" alt=""
                 onerror="this.outerHTML='<i class=\'fas fa-user-circle\'></i>'">
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
              <i class="fas fa-user-cog me-2"></i>Einstellungen
            </a></li>
            <?php if ($rights === 'Admin'): ?>
              <li><a class="dropdown-item" href="admin.php">
                <i class="fas fa-users-cog me-2"></i>Admin
              </a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li>
              <div class="dropdown-item-text py-1">
                <small class="text-muted d-block mb-1">Darstellung</small>
                <div class="btn-group btn-group-sm w-100" role="group" aria-label="Farbschema">
                  <button type="button" class="btn btn-outline-secondary<?= $theme === 'light' ? ' active' : '' ?>"
                          data-theme-btn="light" onclick="setTheme('light')" title="Hell">
                    <i class="fas fa-sun"></i>
                  </button>
                  <button type="button" class="btn btn-outline-secondary<?= $theme === 'auto' ? ' active' : '' ?>"
                          data-theme-btn="auto" onclick="setTheme('auto')" title="Automatisch">
                    <i class="fas fa-adjust"></i>
                  </button>
                  <button type="button" class="btn btn-outline-secondary<?= $theme === 'dark' ? ' active' : '' ?>"
                          data-theme-btn="dark" onclick="setTheme('dark')" title="Dunkel">
                    <i class="fas fa-moon"></i>
                  </button>
                </div>
              </div>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="logout.php">
              <i class="fas fa-sign-out-alt me-2"></i>Logout
            </a></li>
          </ul>
        </div>
      <?php else: ?>
        <a class="nav-link px-2" href="login.php">
          <i class="fas fa-key me-1"></i>
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
      <div id="monitor" class="mb-3">
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
        style="display:none;position:fixed;bottom:20px;right:20px;"
        title="Nach oben">
  <i class="fas fa-arrow-up"></i>
</button>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous"></script>

<!-- Pass PHP state to JS module -->
<script nonce="<?= $_cspNonce ?>">
window.wlConfig = {
  userID:   <?= $userID ?>,
  loggedIn: <?= $loggedIn ? 'true' : 'false' ?>,
  theme:    <?= json_encode($theme) ?>,
  alerts:   <?= $alertsJson ?>
};
</script>

<!-- App module -->
<script type="module" src="js/wl-monitor.js"></script>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
