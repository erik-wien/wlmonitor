<?php
/**
 * html_header.php — full page opener: DOCTYPE, <html data-theme>, <head>,
 * <body>, and the shared .app-header navigation bar.
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
$_username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$_isAdmin  = (($_SESSION['rights'] ?? '') === 'Admin');
$_uid      = (int)($_SESSION['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="de" data-theme="<?= htmlspecialchars($_theme, ENT_QUOTES) ?>">
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
  <link rel="icon" type="image/x-icon" href="img/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="img/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="img/apple-touch-icon.png">
  <link rel="manifest" href="img/manifest.json">
  <link rel="stylesheet" href="css/shared/theme.css">
  <link rel="stylesheet" href="css/shared/reset.css">
  <link rel="stylesheet" href="css/shared/layout.css">
  <link rel="stylesheet" href="css/shared/components.css">
  <link rel="stylesheet" href="css/app/wl-monitor.css">
</head>
<body>
<?php
// Inline SVG sprite (one HTTP request, browser-cached)
$_spritePath = __DIR__ . '/../web/css/icons.svg';
if (file_exists($_spritePath)) { readfile($_spritePath); }
unset($_spritePath);
?>
<header class="app-header">
    <a class="brand" href="index.php">
        <img src="img/wl-logo.svg" class="header-logo" width="28" height="28" alt="">
        <span class="header-appname">WL Monitor</span>
    </a>
    <?php if ($show_search): ?>
    <div class="header-search" id="stationSearchWrap">
        <div class="search-row">
            <input type="search" id="s" class="form-control"
                   placeholder="Station suchen …" autocomplete="off">
            <button class="btn-icon" id="stationListToggle" type="button"
                    tabindex="-1" title="Alle Stationen">
                <?= icon("chevron-down") ?>
            </button>
        </div>
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
    <?php endif; ?>
    <?php if ($_loggedIn): ?>
    <div class="user-menu">
        <button class="user-btn" type="button">
            <img src="avatar.php?id=<?= $_uid ?>" class="avatar" width="26" height="26" alt="">
            <span><?= $_username ?></span>
            <?= icon("chevron-down") ?>
        </button>
        <div class="user-dropdown">
            <span class="dropdown-username"><?= $_username ?></span>
            <div class="dropdown-divider"></div>
            <a href="preferences.php" class="dropdown-link-btn">Einstellungen</a>
            <?php if ($_isAdmin): ?>
            <a href="admin.php" class="dropdown-link-btn">Admin</a>
            <?php endif; ?>
            <div class="dropdown-divider"></div>
            <div class="theme-row">
                <button class="theme-btn<?= $_theme === 'light' ? ' active' : '' ?>"
                        data-theme="light" title="Hell">☀</button>
                <button class="theme-btn<?= $_theme === 'auto'  ? ' active' : '' ?>"
                        data-theme="auto"  title="Auto">⬤</button>
                <button class="theme-btn<?= $_theme === 'dark'  ? ' active' : '' ?>"
                        data-theme="dark"  title="Dunkel">🌙</button>
            </div>
            <div class="dropdown-divider"></div>
            <form method="post" action="logout.php" style="margin:0">
                <?= csrf_input() ?>
                <button type="submit" class="dropdown-link-btn">Abmelden</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <a href="login.php" class="user-btn" style="text-decoration:none;margin-left:auto">Anmelden</a>
    <?php endif; ?>
</header>
<?php if ($_loggedIn): ?>
<script<?= $_csp ? ' nonce="' . htmlspecialchars($_csp, ENT_QUOTES) . '"' : '' ?>>
(function () {
    const menu = document.querySelector('.user-menu');
    if (!menu) return;

    // Toggle dropdown open/close
    menu.querySelector('.user-btn').addEventListener('click', e => {
        e.stopPropagation();
        menu.classList.toggle('open');
    });
    document.addEventListener('click', () => menu.classList.remove('open'));

    // Theme switcher
    menu.querySelectorAll('.theme-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const theme = btn.dataset.theme;
            // Apply immediately
            if (theme === 'dark' || theme === 'light') {
                document.documentElement.dataset.theme = theme;
            } else if (theme === 'auto') {
                delete document.documentElement.dataset.theme;
            }
            // Update active state
            menu.querySelectorAll('.theme-btn').forEach(b =>
                b.classList.toggle('active', b.dataset.theme === theme)
            );
            // Persist in cookie (anonymous + logged-in)
            document.cookie = 'theme=' + theme + ';path=/;max-age=' + (365 * 86400) + ';samesite=Lax';
            // Persist server-side for logged-in users
            const fd = new FormData();
            fd.append('action', 'change_theme');
            fd.append('theme', theme);
            const csrfInput = document.querySelector('input[name="csrf_token"]');
            if (csrfInput) fd.append('csrf_token', csrfInput.value);
            fetch('preferences.php', { method: 'POST', body: fd }).catch(() => {});
        });
    });
})();
</script>
<?php endif; ?>
