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
$timingError = null;
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
    'timing' => 'Zeitverhalten gespeichert. Wirkt beim naechsten Abruf des Geraets.',
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
        $timingError  = $action === 'save_timing' ? $msg : null;
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
    } elseif ($action === 'save_timing') {
        $timingError = board_settings_save_device_timing(
            $con,
            (int) ($_POST['device_idle_timeout_sec'] ?? 0),
            (int) ($_POST['device_refresh_interval_sec'] ?? 0),
            (int) ($_POST['device_wake_interval_sec'] ?? 0),
            (int) ($_POST['device_quiet_start_hour'] ?? 0),
            (int) ($_POST['device_quiet_end_hour'] ?? 0)
        );
        if ($timingError === null) {
            appendLog($con, 'admin', 'Board-Einstellungen: Zeitverhalten geaendert.');
            header('Location: board_settings.php?saved=timing#timing'); exit;
        }
    } elseif ($action === 'save_battery') {
        // Eingabe in Volt (so steht es auf jedem Messgeraet), gespeichert in
        // mV -- s. board_settings_volt_input_to_mv().
        $mv = static fn (string $feld): ?int
            => board_settings_volt_input_to_mv((string) ($_POST[$feld] ?? ''));
        $form = [
            'battery_empty_mv'     => $mv('battery_empty_v'),
            'battery_full_mv'      => $mv('battery_full_v'),
            'battery_charging_mv'  => $mv('battery_charging_v'),
            'battery_display_mode' => ($_POST['battery_display_mode'] ?? '') === 'volt' ? 'volt' : 'percent',
        ];
        if (in_array(null, [$form['battery_empty_mv'], $form['battery_full_mv'], $form['battery_charging_mv']], true)) {
            $batteryError = 'Alle drei Spannungen muessen Zahlen sein (z.B. 4,13).';
        } else {
            $batteryError = board_settings_save_battery(
                $con,
                $form['battery_empty_mv'],
                $form['battery_full_mv'],
                $form['battery_charging_mv'],
                $form['battery_display_mode']
            );
        }
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

  <div class="app-card" id="timing">
    <div class="app-card-header">Zeitverhalten des Displays</div>
    <div class="app-card-body">
      <p class="form-text">
        Wird dem Gerät bei jedem Abruf mitgeschickt und wirkt ab dem nächsten Aufwachen &mdash;
        <strong>ohne Neu-Flashen</strong>. Bis zum nächsten Abruf gilt am Gerät noch der alte Wert.
      </p>
      <?php if ($timingError !== null): ?>
        <div class="app-alert app-alert-danger py-2" role="alert"><?= $e($timingError) ?></div>
      <?php endif; ?>
      <form method="post" action="board_settings.php#timing">
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="action" value="save_timing">
        <div class="form-group">
          <label class="form-label" for="device_idle_timeout_sec">Einschlaf-Frist (Sekunden)</label>
          <input type="number" class="form-control" id="device_idle_timeout_sec" name="device_idle_timeout_sec"
                 min="30" max="3600" value="<?= (int) $settings['device_idle_timeout_sec'] ?>">
          <small class="form-text">Nach so langer Untätigkeit geht das Gerät schlafen. Gilt nur nach manuellem Wecken.</small>
        </div>
        <div class="form-group">
          <label class="form-label" for="device_refresh_interval_sec">Nachladen im Wachbetrieb (Sekunden)</label>
          <input type="number" class="form-control" id="device_refresh_interval_sec" name="device_refresh_interval_sec"
                 min="10" max="600" value="<?= (int) $settings['device_refresh_interval_sec'] ?>">
          <small class="form-text">
            Nicht unter 10&nbsp;s: ein vollständiger Abruf dauert am Gerät 3&ndash;4&nbsp;s
            (TLS, Wiener-Linien-API, Bildaufbau, Zeichnen). Zu kurz heißt, es lädt dauernd
            und reagiert nicht mehr auf Berührungen.
          </small>
        </div>
        <div class="form-group">
          <label class="form-label" for="device_wake_interval_sec">Automatisches Aufwachen alle (Sekunden)</label>
          <input type="number" class="form-control" id="device_wake_interval_sec" name="device_wake_interval_sec"
                 min="300" max="86400" value="<?= (int) $settings['device_wake_interval_sec'] ?>">
          <small class="form-text">3600 = stündlich. Dabei ein Abruf ohne Piep und ohne Vollbild, dann sofort weiterschlafen.</small>
        </div>
        <div class="form-group">
          <label class="form-label">Ruhezeit &ndash; gar kein automatisches Aufwachen</label>
          <div class="d-flex align-items-center" style="gap:.5rem">
            <span>von</span>
            <input type="number" class="form-control" style="max-width:6rem" name="device_quiet_start_hour"
                   min="0" max="23" value="<?= (int) $settings['device_quiet_start_hour'] ?>" aria-label="Ruhezeit Beginn (Stunde)">
            <span>bis</span>
            <input type="number" class="form-control" style="max-width:6rem" name="device_quiet_end_hour"
                   min="0" max="23" value="<?= (int) $settings['device_quiet_end_hour'] ?>" aria-label="Ruhezeit Ende (Stunde)">
            <span>Uhr</span>
          </div>
          <small class="form-text">
            Ein geplantes Aufwachen, das in die Ruhezeit fiele, entfällt ersatzlos. Bei stündlichem
            Intervall und Ruhezeit 0&ndash;6&nbsp;Uhr ist deshalb faktisch schon ab 23&nbsp;Uhr Ruhe:
            der Weckpunkt um Mitternacht liegt bereits darin. Gleicher Wert für Beginn und Ende
            heißt <em>keine</em> Ruhezeit.
          </small>
        </div>
        <button type="submit" class="btn btn-outline-danger">Speichern</button>
      </form>
    </div>
  </div>

  <div class="app-card" id="battery">
    <div class="app-card-header">Akku-Kalibrierung</div>
    <div class="app-card-body">
      <p class="form-text">
        Kalibriert wird in <strong>Volt</strong>, nicht in Prozent: das Gerät misst Spannung, die
        Prozentzahl entsteht erst hier daraus. Die Prozent-Schwellwerte von früher stellten an einer
        Zahl, deren Herleitung selbst nie kalibriert war.
      </p>
      <?php if ($batteryError !== null): ?>
        <div class="app-alert app-alert-danger py-2" role="alert"><?= $e($batteryError) ?></div>
      <?php endif; ?>
      <?php $volt = static fn (int $mv): string => number_format($mv / 1000, 2, ',', ''); ?>
      <form method="post" action="board_settings.php#battery">
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="action" value="save_battery">
        <div class="form-group">
          <label class="form-label" for="battery_empty_v">Leer &ndash; hier wird 0&nbsp;% angezeigt (V)</label>
          <input type="text" inputmode="decimal" class="form-control" id="battery_empty_v" name="battery_empty_v"
                 value="<?= $e($volt((int) $settings['battery_empty_mv'])) ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="battery_full_v">Voll &ndash; hier wird 100&nbsp;% angezeigt (V)</label>
          <input type="text" inputmode="decimal" class="form-control" id="battery_full_v" name="battery_full_v"
                 value="<?= $e($volt((int) $settings['battery_full_mv'])) ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="battery_charging_v">Lädt &ndash; ab hier Blitz statt Wert (V)</label>
          <input type="text" inputmode="decimal" class="form-control" id="battery_charging_v" name="battery_charging_v"
                 value="<?= $e($volt((int) $settings['battery_charging_mv'])) ?>">
          <small class="form-text">
            Am Ladekabel treibt die Ladeschaltung die Spannung über das, was ein ruhender Akku je
            erreicht &mdash; darüber ist der Messwert kein Ladestand mehr.
          </small>
        </div>
        <div class="form-group">
          <span class="form-label d-block">Anzeige am Display</span>
          <div class="btn-group" role="group">
            <?php foreach (['percent' => '%', 'volt' => 'Volt'] as $wert => $beschriftung): ?>
              <?php $aktiv = $settings['battery_display_mode'] === $wert; ?>
              <input type="radio" class="btn-check" name="battery_display_mode" id="mode_<?= $e($wert) ?>"
                     value="<?= $e($wert) ?>"<?= $aktiv ? ' checked' : '' ?>>
              <label class="btn <?= $aktiv ? 'btn-primary' : 'btn-outline-primary' ?>" for="mode_<?= $e($wert) ?>">
                <?= $e($beschriftung) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <small class="form-text">
            Der Balken füllt sich in beiden Fällen stufenlos nach Prozent &mdash; nur die
            Beschriftung wechselt.
          </small>
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
