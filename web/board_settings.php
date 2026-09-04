<?php
declare(strict_types=1);

/**
 * web/board_settings.php — Board-Einstellungen (TASK-27): Gaeste-WLAN,
 * Akku-Kalibrierung, MQTT-Sender-Credentials. Nur fuer Admins (Suite-Policy
 * §1.2, admin_require()) -- betrifft ein einzelnes geteiltes physisches
 * Geraet, keine Pro-User-Einstellung, deshalb "Administration ▾" statt
 * Usermenue (s. inc/layout.php adminItems).
 *
 * Drei getrennte POST-Actions statt einem Sammelformular (Muster aus
 * profil.php: change_theme/change_departures) -- jeder Bereich hat eigene
 * Validierung und soll unabhaengig speicherbar sein.
 *
 * Passwortfelder zeigen den Bestandswert NIE im Formular (auch nicht
 * verschleiert) -- leer lassen heisst "unveraendert", ein Wert eintragen
 * ersetzt ihn. Kein Passwortwert geht ins auth_log (appendLog()-Aufrufe
 * nennen nur, DASS sich etwas geaendert hat).
 */
require_once(__DIR__ . '/../inc/initialize.php');
require_once(__DIR__ . '/../inc/board_settings.php');
require_once(__DIR__ . '/../inc/board_calendar.php');
require_once(__DIR__ . '/../inc/layout.php');

// Konfiguriert ein Geraet, das nur an dieser einen Instanz haengt
// (s. BOARD_FEATURE_AVAILABLE in initialize.php). Auf jardyx.com waere die
// Seite gegenstandslos -- und wuerde Gaeste-WLAN- und Broker-Zugangsdaten in
// eine Datenbank schreiben, aus der sie dort niemand liest.
if (!BOARD_FEATURE_AVAILABLE) {
    http_response_code(404);
    exit;
}

auth_require();
admin_require();

$wifiError = null;
$batteryError = null;
$mqttError = null;
$calendarError = null;

// Das Board meldet sich als EIN Konto an (auth_accounts.id); dessen
// Kalender-Cache traegt die Liste der verfuegbaren Kalender.
const BOARD_DEVICE_USER_ID = 3921;
// Erfolg wird per PRG-Redirect (?saved=…) zurueckgemeldet und HIER gerendert.
// addAlert() waere falsch: diese Seite rendert $_SESSION['alerts'] nicht und
// leert sie auch nicht -- die Meldung poppte erst spaeter kontextlos auf einer
// anderen Seite auf (Audit 2026-09-03).
$savedLabels = [
    'wifi'    => 'Gäste-WLAN gespeichert.',
    'battery' => 'Akku-Kalibrierung gespeichert.',
    'mqtt'    => 'MQTT-Sender-Zugangsdaten gespeichert.',
    'kalender' => 'Kalenderauswahl gespeichert. Sie greift beim nächsten calsync-Lauf (alle 15 Minuten).',
];
$saved = $savedLabels[(string) ($_GET['saved'] ?? '')] ?? null;

// Eingaben ueberleben einen Validierungsfehler (sonst tippt der Admin alles neu).
$form = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_verify()) {
        // Vorher fiel das still durch: die Seite rendert wie ein frischer GET,
        // der Admin sieht weder Fehler noch Bestaetigung und haelt es fuer
        // gespeichert. Fail-closed war es schon, nur eben unsichtbar.
        $msg = 'Sicherheits-Token abgelaufen -- Seite neu laden und nochmal versuchen.';
        $wifiError = $action === 'save_wifi' ? $msg : null;
        $batteryError = $action === 'save_battery' ? $msg : null;
        $mqttError = $action === 'save_mqtt_sender' ? $msg : null;
        $calendarError = $action === 'save_calendars' ? $msg : null;
    } elseif ($action === 'save_wifi') {
        $form = [
            'wifi_ssid'       => trim((string) ($_POST['wifi_ssid'] ?? '')),
            'wifi_encryption' => (string) ($_POST['wifi_encryption'] ?? 'WPA'),
            'wifi_hidden'     => ($_POST['wifi_hidden'] ?? '') === '1',
        ];
        $wifiError = board_settings_save_wifi(
            $con,
            $form['wifi_ssid'],
            (string) ($_POST['wifi_password'] ?? ''),
            $form['wifi_encryption'],
            $form['wifi_hidden']
        );
        if ($wifiError === null) {
            appendLog($con, 'admin', 'Board-Einstellungen: Gaeste-WLAN geaendert.');
            header('Location: board_settings.php?saved=wifi#wifi'); exit;
        }
    } elseif ($action === 'save_battery') {
        $form = [
            'battery_charging_threshold' => (int) ($_POST['battery_charging_threshold'] ?? 0),
            'battery_full_threshold'     => (int) ($_POST['battery_full_threshold'] ?? 0),
        ];
        $batteryError = board_settings_save_battery(
            $con,
            $form['battery_charging_threshold'],
            $form['battery_full_threshold']
        );
        if ($batteryError === null) {
            appendLog($con, 'admin', 'Board-Einstellungen: Akku-Kalibrierung geaendert.');
            header('Location: board_settings.php?saved=battery#battery'); exit;
        }
    } elseif ($action === 'save_calendars') {
        // Kein Eintrag angekreuzt -> leeres Array; board_settings_save_calendars()
        // entfernt dann die Auswahldatei, calsync faellt auf seine eingebaute
        // Liste zurueck (eine leere Auswahl darf das Board nicht stumm stellen).
        // Angekreuzte Kalender mit ihrem Kuerzel verbinden. Das Kuerzelfeld
        // kommt fuer JEDEN Kalender mit, auch fuer nicht angekreuzte -- so
        // bleibt ein einmal vergebenes "(E)" erhalten, wenn man den Kalender
        // kurz abwaehlt und wieder anhakt.
        $angekreuzt = array_values(array_filter(
            (array) ($_POST['calendars'] ?? []),
            static fn ($n): bool => is_string($n) && trim($n) !== ''
        ));
        $marken = (array) ($_POST['marker'] ?? []);
        $gewaehlt = [];
        foreach ($angekreuzt as $name) {
            $gewaehlt[trim($name)] = is_string($marken[$name] ?? null) ? trim($marken[$name]) : '';
        }
        $calendarError = board_settings_save_calendars($gewaehlt);
        if ($calendarError === null) {
            appendLog($con, 'admin', 'Board-Einstellungen: Kalenderauswahl geaendert (' . count($gewaehlt) . ' Kalender).');
            header('Location: board_settings.php?saved=kalender#kalender'); exit;
        }
    } elseif ($action === 'save_mqtt_sender') {
        $form = ['mqtt_sender_user' => trim((string) ($_POST['mqtt_sender_user'] ?? ''))];
        $mqttError = board_settings_save_mqtt_sender(
            $con,
            $form['mqtt_sender_user'],
            (string) ($_POST['mqtt_sender_password'] ?? '')
        );
        if ($mqttError === null) {
            appendLog($con, 'admin', 'Board-Einstellungen: MQTT-Sender-Zugangsdaten geaendert.');
            header('Location: board_settings.php?saved=mqtt#mqtt'); exit;
        }
    }
}

// Nach einem Fehler die abgelehnten Eingaben zeigen, sonst den DB-Stand.
$settings = array_merge(board_settings_load($con), $form);
$csrfToken = csrf_token();
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<?php render_header(); ?>

<main id="main-content" tabindex="-1">
<div class="container admin-page">

  <?php if ($saved !== null): ?>
    <div class="app-alert app-alert-success" role="status"><?= $e($saved) ?></div>
  <?php endif; ?>

  <div class="app-card" id="wifi">
    <div class="app-card-header">Gäste-WLAN</div>
    <div class="app-card-body">
      <p class="form-text">Erscheint als QR-Code auf dem Schlafschirm des Boards. Leere SSID blendet den QR-Block aus.</p>
      <?php if ($wifiError !== null): ?>
        <div class="app-alert app-alert-danger py-2" role="alert"><?= $e($wifiError) ?></div>
      <?php endif; ?>
      <form method="post" action="board_settings.php#wifi">
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="action" value="save_wifi">
        <div class="form-group">
          <label class="form-label" for="wifi_ssid">SSID</label>
          <input type="text" class="form-control" id="wifi_ssid" name="wifi_ssid"
                 value="<?= $e($settings['wifi_ssid']) ?>" maxlength="64">
        </div>
        <div class="form-group">
          <label class="form-label" for="wifi_password">Passwort</label>
          <input type="text" class="form-control" id="wifi_password" name="wifi_password"
                 autocomplete="off" placeholder="unverändert lassen = leer senden" maxlength="128">
        </div>
        <div class="form-group">
          <label class="form-label" for="wifi_encryption">Verschlüsselung</label>
          <select class="form-control" id="wifi_encryption" name="wifi_encryption">
            <?php foreach (['WPA', 'WEP', 'NOPASS'] as $enc): ?>
              <option value="<?= $e($enc) ?>" <?= $settings['wifi_encryption'] === $enc ? 'selected' : '' ?>><?= $e($enc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group form-check">
          <input type="checkbox" class="form-check-input" id="wifi_hidden" name="wifi_hidden" value="1"
                 <?= $settings['wifi_hidden'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="wifi_hidden">Verstecktes Netz</label>
        </div>
        <button type="submit" class="btn btn-outline-danger">Speichern</button>
      </form>
    </div>
  </div>

  <div class="app-card" id="battery">
    <div class="app-card-header">Akku-Kalibrierung</div>
    <div class="app-card-body">
      <p class="form-text">Ab dem Lade-Schwellwert zeigt das Board ein Blitz-Symbol statt einer Prozentzahl. Zwischen "voll" und "lädt" wird 100&nbsp;% angezeigt.</p>
      <?php if ($batteryError !== null): ?>
        <div class="app-alert app-alert-danger py-2" role="alert"><?= $e($batteryError) ?></div>
      <?php endif; ?>
      <form method="post" action="board_settings.php#battery">
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="action" value="save_battery">
        <div class="form-group">
          <label class="form-label" for="battery_full_threshold">"Voll"-Schwellwert (%)</label>
          <input type="number" class="form-control" id="battery_full_threshold" name="battery_full_threshold"
                 min="1" max="100" value="<?= (int) $settings['battery_full_threshold'] ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="battery_charging_threshold">Lade-Schwellwert (%)</label>
          <input type="number" class="form-control" id="battery_charging_threshold" name="battery_charging_threshold"
                 min="1" max="100" value="<?= (int) $settings['battery_charging_threshold'] ?>">
        </div>
        <button type="submit" class="btn btn-outline-danger">Speichern</button>
      </form>
    </div>
  </div>

  <div class="app-card" id="kalender">
    <div class="app-card-header">Kalender</div>
    <div class="app-card-body">
      <p class="form-text">
        Welche Kalender auf der Kalenderseite des Boards erscheinen. Die Liste stammt aus dem letzten
        <code>calsync</code>-Lauf auf diesem Server &mdash; nur der kennt die Kalender des Systems.
      </p>
      <?php if ($calendarError !== null): ?>
        <div class="app-alert app-alert-danger py-2" role="alert"><?= $e($calendarError) ?></div>
      <?php endif; ?>
      <?php $verfuegbar = board_settings_available_calendars(BOARD_DEVICE_USER_ID); ?>
      <?php if ($verfuegbar === []): ?>
        <div class="app-alert app-alert-warning py-2" role="alert">
          Keine Kalenderliste vorhanden. Sie entsteht beim nächsten <code>calsync</code>-Lauf;
          ist sie danach immer noch leer, läuft dort noch eine ältere Fassung ohne diese Auskunft.
        </div>
      <?php else: ?>
        <form method="post" action="board_settings.php#kalender">
          <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
          <input type="hidden" name="action" value="save_calendars">
          <?php $gewaehlt = board_calendar_selection(); ?>
          <p class="form-text">
            Das <strong>Kürzel</strong> steht auf dem Board vor jedem Termin dieses Kalenders &mdash;
            so ist zu sehen, wem er gehört (z.&nbsp;B. <code>(E)</code>, <code>(A)</code>).
            Leer lassen heißt: keine Markierung. Emoji eignen sich dafür nicht, das Panel kennt
            nur Schwarz und Weiß.
          </p>
          <?php foreach ($verfuegbar as $i => $name): ?>
            <div class="form-group">
              <div class="form-check">
                <input type="checkbox" class="form-check-input" id="cal<?= (int) $i ?>"
                       name="calendars[]" value="<?= $e($name) ?>"
                       <?= array_key_exists($name, $gewaehlt) ? 'checked' : '' ?>>
                <label class="form-check-label" for="cal<?= (int) $i ?>"><?= $e($name) ?></label>
              </div>
              <input type="text" class="form-control" style="max-width:8rem"
                     name="marker[<?= $e($name) ?>]" maxlength="6" placeholder="Kürzel"
                     aria-label="Kürzel für <?= $e($name) ?>"
                     value="<?= $e($gewaehlt[$name] ?? '') ?>">
            </div>
          <?php endforeach; ?>
          <p class="form-text">
            Nichts angekreuzt bedeutet <strong>nicht</strong> „keine Kalender“: dann gilt weiter die
            im Server hinterlegte Standardliste. So bleibt das Board nach einem Fehlgriff bedient.
          </p>
          <button type="submit" class="btn btn-outline-danger">Speichern</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="app-card" id="mqtt">
    <div class="app-card-header">MQTT-Sender-Zugangsdaten</div>
    <div class="app-card-body">
      <p class="form-text">
        Zugangsdaten für <code>/mqtt/</code> (Nachricht ans Board senden). Ein neues Passwort wird sofort auch am
        Broker gesetzt -- schlägt das fehl, wird nichts gespeichert, damit DB und Broker nicht auseinanderlaufen.
      </p>
      <?php if ($mqttError !== null): ?>
        <div class="app-alert app-alert-danger py-2" role="alert"><?= $e($mqttError) ?></div>
      <?php endif; ?>
      <form method="post" action="board_settings.php#mqtt">
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="action" value="save_mqtt_sender">
        <div class="form-group">
          <label class="form-label" for="mqtt_sender_user">Benutzername</label>
          <input type="text" class="form-control" id="mqtt_sender_user" name="mqtt_sender_user"
                 value="<?= $e($settings['mqtt_sender_user']) ?>" maxlength="64">
        </div>
        <div class="form-group">
          <label class="form-label" for="mqtt_sender_password">Passwort</label>
          <input type="text" class="form-control" id="mqtt_sender_password" name="mqtt_sender_password"
                 autocomplete="off" placeholder="unverändert lassen = leer senden" maxlength="128">
        </div>
        <button type="submit" class="btn btn-outline-danger">Speichern</button>
      </form>
    </div>
  </div>

</div>
</main>

<?php render_footer(); ?>
