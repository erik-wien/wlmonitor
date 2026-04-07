<?php
$_ft = '';
if (!empty($_SESSION['loggedin'])) {
    $_ft = $_SESSION['theme'] ?? 'auto';
} else {
    $_ft = $_COOKIE['theme'] ?? 'auto';
}
$_ft = htmlspecialchars($_ft, ENT_QUOTES, 'UTF-8');
$_ftLoggedIn = !empty($_SESSION['loggedin']);
?>
<footer class="wl-footer fixed-bottom border-top">
  <div class="container-fluid d-flex justify-content-between align-items-center py-1">

    <!-- Theme toggle -->
    <div class="btn-group btn-group-sm" role="group" aria-label="Theme">
      <input type="radio" class="btn-check" name="themePreference"
             id="themeAuto" value="auto" autocomplete="off"
             <?= $_ft === 'auto'  ? 'checked' : '' ?>>
      <label class="btn btn-footer-toggle" for="themeAuto">Auto</label>

      <input type="radio" class="btn-check" name="themePreference"
             id="themeLight" value="light" autocomplete="off"
             <?= $_ft === 'light' ? 'checked' : '' ?>>
      <label class="btn btn-footer-toggle" for="themeLight">
        <i class="fas fa-sun"></i>
      </label>

      <input type="radio" class="btn-check" name="themePreference"
             id="themeDark" value="dark" autocomplete="off"
             <?= $_ft === 'dark'  ? 'checked' : '' ?>>
      <label class="btn btn-footer-toggle" for="themeDark">
        <i class="fas fa-moon"></i>
      </label>
    </div>

    <button class="btn btn-sm footer-btn" onclick="toggleFullScreen()" title="Vollbild">
      <i class="fas fa-expand-arrows-alt"></i>
      <span class="d-none d-sm-inline ms-1">Vollbild</span>
    </button>

    <small class="text-muted">&copy; 2026 Erik R. Huemer</small>

    <small class="text-muted">v<?= APP_VERSION ?>.<?= APP_BUILD ?></small>

  </div>
</footer>

<script nonce="<?= $_cspNonce ?>">
(function () {
  var loggedIn = <?= $_ftLoggedIn ? 'true' : 'false' ?>;

  function getCookie(name) {
    for (var part of decodeURIComponent(document.cookie).split(';')) {
      var t = part.trim(), eq = t.indexOf('=');
      if (eq !== -1 && t.slice(0, eq) === name) return t.slice(eq + 1);
    }
    return '';
  }
  function setCookie(name, val, days) {
    var d = new Date();
    d.setTime(d.getTime() + days * 86400000);
    document.cookie = name + '=' + val + ';expires=' + d.toUTCString() + ';path=/;SameSite=Strict';
  }

  document.querySelectorAll('input[name="themePreference"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      var t = radio.value;
      if (t === 'auto') {
        delete document.documentElement.dataset.theme;
      } else {
        document.documentElement.dataset.theme = t;
      }
      setCookie('theme', t, 365);
      if (loggedIn) {
        var fd = new FormData();
        fd.append('action', 'theme_save');
        fd.append('theme', t);
        fetch('api.php', { method: 'POST', body: fd }).catch(function () {});
      }
    });
  });

  function toggleFullScreen() {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen && document.documentElement.requestFullscreen();
    } else {
      document.exitFullscreen && document.exitFullscreen();
    }
  }
  window.toggleFullScreen = toggleFullScreen;
})();
</script>
