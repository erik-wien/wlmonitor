<?php
require_once(__DIR__ . '/../include/initialize.php');
header('Content-Type: text/html; charset=utf-8');

// Flush session alerts for JS to consume
$alerts = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
$alertsJson = json_encode($alerts, JSON_HEX_TAG | JSON_HEX_AMP);

$userID   = (int) ($_SESSION['id'] ?? 0);
$loggedIn = !empty($_SESSION['loggedin']);
$uname    = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$rights   = htmlspecialchars($_SESSION['rights']   ?? '', ENT_QUOTES, 'UTF-8');
$theme    = htmlspecialchars($_COOKIE['theme']     ?? 'auto', ENT_QUOTES, 'UTF-8');
?>
<?php include_once(__DIR__ . '/../include/html_header.php'); ?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">
      <i class="fas fa-subway me-1"></i> WL Monitor
    </a>
    <button class="navbar-toggler" type="button"
            data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav ms-auto align-items-center gap-1">
        <?php if ($loggedIn): ?>
          <li class="nav-item"><span class="nav-link"><?= $uname ?></span></li>
          <li class="nav-item"><a class="nav-link" href="changePassword.php" title="Passwort andern">
            <i class="fas fa-key"></i></a></li>
          <?php if ($rights === 'Admin'): ?>
            <li class="nav-item"><a class="nav-link" href="admin.php">
              <i class="fas fa-users-cog"></i> Admin</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="logout.php" title="Abmelden">
            <i class="fas fa-sign-out-alt"></i></a></li>
        <?php else: ?>
          <li class="nav-item">
            <form class="d-flex gap-1 align-items-center" method="post" action="authentication.php">
              <?= csrf_input() ?>
              <input type="email" name="login-email" class="form-control form-control-sm"
                     placeholder="E-Mail"
                     value="<?= htmlspecialchars($_COOKIE['wlmonitor_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
              <input type="password" name="login-password"
                     class="form-control form-control-sm" placeholder="Kennwort">
              <button class="btn btn-sm btn-outline-light" type="submit">
                <i class="fas fa-sign-in-alt"></i>
              </button>
            </form>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<div id="alerts" class="container-fluid mt-2"></div>

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
      <div id="buttons" class="mb-3"></div>

      <!-- Station sort controls -->
      <div class="mb-2">
        <div class="btn-group btn-group-sm w-100" role="group">
          <input type="radio" class="btn-check" name="stationSort"
                 id="sortDist" value="dist" autocomplete="off">
          <label class="btn btn-outline-secondary" for="sortDist">
            <i class="fas fa-map-marker-alt"></i> Nahe
          </label>

          <input type="radio" class="btn-check" name="stationSort"
                 id="sortAlpha" value="alpha" autocomplete="off">
          <label class="btn btn-outline-secondary" for="sortAlpha">
            <i class="fas fa-sort-alpha-down"></i> A-Z
          </label>

          <input type="radio" class="btn-check" name="stationSort"
                 id="sortSearch" value="search" autocomplete="off">
          <label class="btn btn-outline-secondary" for="sortSearch">
            <i class="fas fa-search"></i> Suche
          </label>
        </div>

        <input type="text" id="s" class="form-control form-control-sm mt-1 d-none"
               placeholder="Station suchen ...">

        <div id="stationSortDist" class="d-none mt-1 text-muted small">
          <span class="spinner-border spinner-border-sm"></span> Standort wird ermittelt ...
        </div>
        <div id="stationSortAlpha" class="d-none mt-1 text-muted small">
          <span class="spinner-border spinner-border-sm"></span> Stationen werden geladen ...
        </div>
      </div>

      <ul id="stationList" class="list-unstyled"></ul>

      <!-- Theme toggle -->
      <div class="mt-3">
        <div class="btn-group btn-group-sm" role="group">
          <input type="radio" class="btn-check" name="themePreference"
                 id="themeAuto" value="auto" autocomplete="off"
                 <?= $theme === 'auto'  ? 'checked' : '' ?>>
          <label class="btn btn-outline-secondary" for="themeAuto">Auto</label>

          <input type="radio" class="btn-check" name="themePreference"
                 id="themeLight" value="light" autocomplete="off"
                 <?= $theme === 'light' ? 'checked' : '' ?>>
          <label class="btn btn-outline-secondary" for="themeLight">
            <i class="fas fa-sun"></i>
          </label>

          <input type="radio" class="btn-check" name="themePreference"
                 id="themeDark" value="dark" autocomplete="off"
                 <?= $theme === 'dark'  ? 'checked' : '' ?>>
          <label class="btn btn-outline-secondary" for="themeDark">
            <i class="fas fa-moon"></i>
          </label>
        </div>
      </div>
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
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmB6rVQO0ViiINFjyRUNLnCIE3T"
        crossorigin="anonymous"></script>

<!-- Pass PHP state to JS module -->
<script>
window.wlConfig = {
  userID:   <?= $userID ?>,
  loggedIn: <?= $loggedIn ? 'true' : 'false' ?>,
  alerts:   <?= $alertsJson ?>
};
</script>

<!-- App module -->
<script type="module" src="js/wl-monitor.js"></script>

<?php include_once(__DIR__ . '/../include/html_footer.php'); ?>
