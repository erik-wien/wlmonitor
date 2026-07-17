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

    $themeAttr = $theme !== 'auto'
        ? ' data-theme="' . htmlspecialchars($theme, ENT_QUOTES) . '"'
        : '';
    ?>
<!DOCTYPE html>
<html lang="de"<?= $themeAttr ?>>
<head>
  <title>Wiener Linien Abfahrtsmonitor</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        'appsMenu'      => $appsMenu,
        'leftExtra'     => $leftExtra,
        'spritePath'    => __DIR__ . '/../web/css/icons.svg',
        'loggedIn'      => $loggedIn,
        'username'      => $username,
        'isAdmin'       => $isAdmin,
        'theme'         => $theme,
        'brandHref'     => 'index.php',
        'avatarSrc'     => 'avatar.php?id=' . $uid,
        'profileHref'   => 'preferences.php#profilbild',
        'emailHref'     => 'preferences.php#email',
        'appPrefsHref'  => 'preferences.php#abfahrten',
        'appPrefsLabel' => 'Abfahrten',
        'securityHref'  => 'security.php',
        'adminHref'     => 'admin.php',
        'helpHref'      => 'help.php',
        'logoutHref'    => 'logout.php',
        'themeEndpoint' => 'preferences.php',
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
