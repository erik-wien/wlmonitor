<?php
/**
 * inc/layout.php — wrapper functions for \Erikr\Chrome header/footer.
 *
 * render_header(bool $showSearch = false): void
 *   Emits DOCTYPE, <head>, <body>, and the shared Chrome header.
 *   Pass true for index.php which shows the station-search widget.
 *
 * render_footer(): void
 *   Emits the shared Chrome footer plus </body></html>.
 */

function render_header(bool $showSearch = false): void
{
    global $_cspNonce;

    $csp      = $_cspNonce ?? '';
    $loggedIn = !empty($_SESSION['loggedin']);
    $theme    = $loggedIn ? ($_SESSION['theme'] ?? 'auto') : ($_COOKIE['theme'] ?? 'auto');
    $theme    = in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'auto';
    $username = $_SESSION['username'] ?? '';
    $isAdmin  = (($_SESSION['rights'] ?? '') === 'Admin');
    $uid      = (int) ($_SESSION['id'] ?? 0);

    $leftExtra = '';
    if ($showSearch) {
        ob_start(); ?>
<div class="header-search" id="stationSearchWrap">
    <button class="btn-icon search-toggle" id="searchToggle" type="button"
            aria-label="Station suchen">
        <?= icon("search") ?>
    </button>
    <div class="search-row">
        <input type="search" id="s"
               placeholder="Station suchen …" autocomplete="off">
        <button class="btn-icon" id="stationListToggle" type="button"
                tabindex="-1" title="Alle Stationen">
            <?= icon("chevron-down") ?>
        </button>
        <div id="stationDropdown" class="station-dropdown" style="display:none;">
            <div class="station-dropdown-header">
                <div class="sort-btn-group">
                    <input type="radio" name="stationSort" id="sortAlpha"
                           value="alpha" autocomplete="off" checked>
                    <label for="sortAlpha"><?= icon("sort-alpha") ?> A–Z</label>
                    <input type="radio" name="stationSort" id="sortDist"
                           value="dist" autocomplete="off">
                    <label for="sortDist"><?= icon("map-marker") ?> Nähe</label>
                </div>
            </div>
            <ul id="stationList" style="list-style:none;padding:0;margin:0;"></ul>
        </div>
    </div>
</div>
<?php
        $leftExtra = ob_get_clean();
    }

    // Cross-App-Navigation aus der zentralen Registry (Erikr\Chrome\AppsMenu) —
    // ersetzt die frühere handgepflegte Liste (TASK-19).
    $appsMenu = \Erikr\Chrome\AppsMenu::build('wlmonitor', APP_ENV);

    // App-eigene Navigation (Suite-Policy §1.1). wlmonitor hat keine: die
    // Abfahrten sind die Startseite. Die frueheren Eintraege "eInk Display"
    // und "Board-Einstellungen" sind mit dem Umzug nach ~/Git/Display
    // (2026-09-05) entfallen -- sie zeigten auf Seiten, die es hier nicht
    // mehr gibt.
    $appMenu = [];
    $adminItems = [];

    $themeAttr = $theme !== 'auto'
        ? ' data-theme="' . htmlspecialchars($theme, ENT_QUOTES) . '"'
        : '';
    ?>
<!DOCTYPE html>
<html lang="de"<?= $themeAttr ?>>
<head>
  <title>Wiener Linien Abfahrtsmonitor</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <meta name="application-name" content="WL Monitor">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="WL Monitor">
  <meta name="theme-color" content="<?= htmlspecialchars(APP_COLOR, ENT_QUOTES) ?>">
  <link rel="icon" type="image/svg+xml" href="css/shared/logos/jardyx_rot.svg">
  <link rel="icon" type="image/x-icon" href="img/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="img/favicon-16x16.png">
  <link rel="apple-touch-icon" href="img/apple-touch-icon.png">
  <link rel="manifest" href="img/manifest.json">
  <?php $cssV = static function (string $rel): string {
      $m = @filemtime(dirname(__DIR__) . '/web/' . $rel);   // folgt shared/-Symlink
      return $m ? '?v=' . $m : '';
  }; ?>
  <link rel="stylesheet" href="css/shared/theme.css<?= $cssV('css/shared/theme.css') ?>">
  <link rel="stylesheet" href="css/shared/reset.css<?= $cssV('css/shared/reset.css') ?>">
  <link rel="stylesheet" href="css/shared/layout.css<?= $cssV('css/shared/layout.css') ?>">
  <link rel="stylesheet" href="css/shared/components.css<?= $cssV('css/shared/components.css') ?>">
  <link rel="stylesheet" href="css/app/wl-theme.css<?= $cssV('css/app/wl-theme.css') ?>">
  <link rel="stylesheet" href="css/app/wl-monitor.css<?= $cssV('css/app/wl-monitor.css') ?>">
</head>
<body>
<?php
    \Erikr\Chrome\Header::render([
        'appName'       => 'WL Monitor',
        'base'          => '',
        'cspNonce'      => $csp,
        'csrfToken'     => function_exists('csrf_token') ? csrf_token() : '',
        'appMenu'       => $appMenu,
        'appsMenu'      => $appsMenu,
        'leftExtra'     => $leftExtra,
        'spritePath'    => __DIR__ . '/../web/css/icons.svg',
        'loggedIn'      => $loggedIn,
        'username'      => $username,
        'isAdmin'       => $isAdmin,
        'theme'         => $theme,
        'brandHref'     => 'index.php',
        'avatarSrc'     => 'avatar.php?id=' . $uid,
        'adminHref'     => 'admin.php',
        // statusHref/profilHref/activityHref explicit (TASK-21, Suite-Policy
        // §Baustein 2 resp. Usermenü-Redesign): the Header defaults are
        // $base . '/status.php' etc.; wlmonitor passes 'base' => '' but
        // (unlike suche, where $base is a real computed path prefix) every
        // other href here is already a bare relative path (admin.php,
        // profil.php, …), so the defaults would render domain-root-absolute
        // ('/status.php') — inconsistent with the rest of the menu and wrong
        // if the app is ever not served at its (sub)domain root. Same fix
        // zeiterfassung applied (inc/_header.php).
        'adminItems'    => $adminItems,
        'statusHref'    => 'status.php',
        'profilHref'    => 'profil.php',
        'activityHref'  => 'aktivitaet.php',
        'helpHref'      => 'help.php',
        'logoutHref'    => 'logout.php',
        // themeEndpoint: the header's theme pill fire-and-forget POSTs here
        // (action=change_theme). preferences.php was removed (its remaining
        // content — Profilbild/E-Mail — moved to profil.php, Abfahrten went
        // inline there too); profil.php now also handles change_theme.
        'themeEndpoint' => 'profil.php',
        'anonLoginHref' => 'login.php',
    ]);
}

function render_footer(): void
{
    \Erikr\Chrome\Footer::render([
        'base'          => '',
        'impressumHref' => 'impressum.php',
    ]);
    echo '</body></html>';
}
