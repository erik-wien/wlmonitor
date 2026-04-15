<?php
/**
 * web/help.php — user-facing help page (FAQ + Datenschutz).
 *
 * Public. No auth required: the shared header renders a login link
 * instead of the user menu when the visitor isn't signed in.
 */
require_once(__DIR__ . '/../inc/initialize.php');
?>
<?php include_once(__DIR__ . '/../inc/html_header.php'); ?>

<main class="container-md mt-4 mb-4" id="helpMain">

  <h1 class="mb-3">Hilfe</h1>

  <p class="text-muted">
    Der Wiener Abfahrtsmonitor zeigt Echtzeit-Abfahrtszeiten der Wiener Linien
    für selbst gewählte Stationen und Favoriten an.
  </p>

  <nav class="mb-4" aria-label="Inhalt">
    <ul class="list-unstyled d-flex flex-wrap gap-3">
      <li><a href="#faq">Häufige Fragen</a></li>
      <li><a href="#favoriten">Favoriten &amp; Suche</a></li>
      <li><a href="#datenschutz">Cookies &amp; Datenschutz</a></li>
    </ul>
  </nav>

  <!-- ── FAQ ─────────────────────────────────────────────────────────────── -->
  <section id="faq" class="card mb-3">
    <div class="card-header">Häufige Fragen</div>
    <div class="card-body">

      <h3 class="h6">Was sind Favoriten?</h3>
      <p>
        Favoriten sind Stationen, die Sie sich vorgemerkt haben — typischerweise
        Orte, an denen Sie oft losfahren. Favoriten erscheinen als farbige
        Schnellauswahl-Schaltflächen neben dem Monitor.
      </p>

      <h3 class="h6">Wie füge ich einen Favoriten hinzu?</h3>
      <p>
        Sie müssen registriert und angemeldet sein. Rufen Sie eine Station auf
        und klicken Sie neben dem Stationsnamen auf einen der farbigen Sterne.
        In dieser Farbe erscheint dann Ihr Favorit in der Seitenleiste.
      </p>

      <h3 class="h6">Kann ich einen Favoriten auf eine bestimmte Linie oder Richtung einschränken?</h3>
      <p>
        Ja, bedingt. Wenn Sie auf einen Richtungsnamen klicken, zeigt der Monitor
        nur noch die Verbindungen in diese Richtung. Dieser gefilterte Zustand
        lässt sich ebenfalls als Favorit speichern.
      </p>

      <h3 class="h6">Wie lösche oder ändere ich einen Favoriten?</h3>
      <p>
        Rufen Sie die Station über den entsprechenden Button auf und klicken Sie
        beim Stationsnamen auf den Mistkübel, um sie zu löschen. Zum Bearbeiten
        klicken Sie den Favoriten-Button doppelt an — im Editor lassen sich
        Titel, Farbe und Reihenfolge ändern.
      </p>

      <h3 class="h6">Wie kann ich andere Stationen aufrufen?</h3>
      <p>
        Klicken Sie oben in das Suchfeld und geben Sie den Namen der Station
        ein, oder wählen Sie aus der Stationsliste.
      </p>

      <h3 class="h6">Warum fragt die App nach meiner Position?</h3>
      <p>
        Nur zur Sortierung der Stationsliste nach Entfernung, damit die
        nächstgelegene Haltestelle zuoberst steht. Die Position wird ausschließlich
        im Browser verwendet und nicht an den Server übertragen.
      </p>

      <h3 class="h6">Wie lege ich die App auf den Startbildschirm?</h3>
      <p>
        WL Monitor ist eine Web App: Mobile Browser (Safari, Chrome) bieten im
        Teilen-Menü „Zum Home-Bildschirm hinzufügen“ an. Nach dem Hinzufügen
        startet der Monitor wie eine eigenständige App.
      </p>

      <h3 class="h6" id="favoriten">Einstellungen und Passwort</h3>
      <p>
        Anzeige-Einstellungen (Profilbild, Design, Anzahl Abfahrten, E-Mail)
        finden Sie unter <a href="preferences.php">Einstellungen</a>. Passwort
        und Zwei-Faktor-Anmeldung werden getrennt davon unter
        <a href="security.php">Passwort &amp; 2FA</a> verwaltet.
      </p>

    </div>
  </section>

  <!-- ── Datenschutz ────────────────────────────────────────────────────── -->
  <section id="datenschutz" class="card mb-4">
    <div class="card-header">Cookies &amp; Datenschutz</div>
    <div class="card-body">

      <p>
        Diese Webapp verwendet Cookies ausschließlich zur Speicherung Ihrer
        zuletzt gewählten Station und der Design-Einstellung (Hell/Dunkel/Automatisch).
        Cookies werden lokal in Ihrem Browser gespeichert.
      </p>

      <p>
        Angemeldete Benutzer:innen können Favoriten anlegen. Dafür speichern wir
        den von Ihnen angegebenen Benutzernamen, die E-Mail-Adresse und einen
        <em>Passwort-Hash</em>. Das Passwort selbst kennen wir nicht — wir
        prüfen nur, ob ein eingegebenes Passwort zum gespeicherten Hash passt.
      </p>

      <p>
        Registrierung, An- und Abmeldung sowie Aktionen an der Favoritenverwaltung
        werden in einem Aktivitätsprotokoll gespeichert, das Sie auf Wunsch
        einsehen können. Das Protokoll dient der Nachvollziehbarkeit bei
        Sicherheitsfragen.
      </p>

      <p>
        Ihre Daten werden zu keinem anderen Zweck als zur Bereitstellung dieses
        Services verarbeitet und nicht an Dritte weitergegeben.
      </p>

      <p class="text-muted small">
        Verantwortlich: siehe <a href="impressum.html">Impressum</a>.
      </p>

    </div>
  </section>

</main>

<?php include_once(__DIR__ . '/../inc/html_footer.php'); ?>
