<?php
/**
 * web/aktivitaet.php — "Log" (Erikr\Chrome\Activity, Header's
 * activityHref target). Zeigt die eigenen auth_log-Einträge des angemeldeten
 * Users, ohne Filterzeile.
 *
 * Sicherheit: die User-ID kommt ausschließlich aus der Session
 * ($_SESSION['id']), nie aus $_GET/$_POST — siehe Chrome\Activity-Docblock.
 */

require_once(__DIR__ . '/../inc/initialize.php');
require_once(__DIR__ . '/../inc/layout.php');

auth_require();
?>
<?php render_header(); ?>

<main class="container-md mt-4" id="main-content" tabindex="-1">
  <h4 class="mb-3"><?= icon("history", "me-2") ?>Log</h4>

  <?php
  \Erikr\Chrome\Activity::render([
      'con'    => $con,
      'userId' => (int) $_SESSION['id'],
  ]);
  ?>

</main>

<?php render_footer(); ?>
