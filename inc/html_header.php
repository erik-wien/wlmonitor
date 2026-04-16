<?php
/**
 * html_header.php — full page opener: DOCTYPE, <head>, <body>, and the
 * shared app header via \Erikr\Chrome\Header::render().
 *
 * Variables the including page may set before this include:
 *   $show_search (bool, default false) — show station search in header (index.php only)
 */

if (!isset($show_search)) { $show_search = false; }

$_csp      = $_cspNonce ?? '';
$_loggedIn = !empty($_SESSION['loggedin']);
$_theme    = $_loggedIn
    ? ($_SESSION['theme'] ?? 'auto')
    : ($_COOKIE['theme']  ?? 'auto');
$_theme    = in_array($_theme, ['light', 'dark', 'auto'], true) ? $_theme : 'auto';
$_username = $_SESSION['username'] ?? '';
$_isAdmin  = (($_SESSION['rights'] ?? '') === 'Admin');
$_uid      = (int)($_SESSION['id'] ?? 0);

// Optional station-search widget injected into .header-left via Chrome's leftExtra slot.
$_leftExtra = '';
if ($show_search) {
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
    $_leftExtra = ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="de"<?= $_theme !== 'auto' ? ' data-theme="' . htmlspecialchars($_theme, ENT_QUOTES) . '"' : '' ?>>
<head>
  <title>Wiener Linien Abfahrtsmonitor</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <meta name="application-name" content="WL Monitor">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="WL Monitor">
  <meta name="theme-color" content="#000000">
  <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png">
  <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
  <link rel="manifest" href="img/manifest.json">
  <link rel="stylesheet" href="css/shared/theme.css">
  <link rel="stylesheet" href="css/shared/reset.css">
  <link rel="stylesheet" href="css/shared/layout.css">
  <link rel="stylesheet" href="css/shared/components.css">
  <link rel="stylesheet" href="css/app/wl-monitor.css">
</head>
<body>
<?php
\Erikr\Chrome\Header::render([
    'appName'       => 'WL Monitor',
    'base'          => '',
    'cspNonce'      => $_csp,
    'csrfToken'     => function_exists('csrf_token') ? csrf_token() : '',
    'leftExtra'     => $_leftExtra,
    'spritePath'    => __DIR__ . '/../web/css/icons.svg',
    'loggedIn'      => $_loggedIn,
    'username'      => $_username,
    'isAdmin'       => $_isAdmin,
    'theme'         => $_theme,
    'brandHref'     => 'index.php',
    'brandLogoSrc'  => 'assets/jardyx.svg',
    'avatarSrc'     => 'avatar.php?id=' . $_uid,
    'prefsHref'     => 'preferences.php',
    'securityHref'  => 'security.php',
    'adminHref'     => 'admin.php',
    'helpHref'      => 'help.php',
    'logoutHref'    => 'logout.php',
    'themeEndpoint' => 'preferences.php',
    'anonLoginHref' => 'login.php',
]);
