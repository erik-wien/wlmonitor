<?php
declare(strict_types=1);

/**
 * inc/board_settings.php — Board-Einstellungen (TASK-27): Gaeste-WLAN,
 * Akku-Kalibrierung, MQTT-Sender-Credentials. Single-Row-Tabelle
 * wl_board_settings (id=1 fix, s. migrations/006_wl_board_settings.sql).
 *
 * Nur Admins duerfen speichern -- web/board_settings.php prueft das
 * server-seitig (admin_require()), nicht nur ueber die Header-Sichtbarkeit.
 * Betrifft ein einzelnes geteiltes physisches Geraet, keine Pro-User-
 * Einstellung.
 *
 * Passwortwerte gehen NIE ins auth_log (Projektkonvention, s. Token-Werte
 * in web/board.php) -- appendLog()-Aufrufe hier nennen nur, DASS sich etwas
 * geaendert hat, nie den neuen Wert.
 */

const BOARD_SETTINGS_MQTT_PUB_BIN = '/opt/homebrew/bin/mosquitto_pub';
const BOARD_SETTINGS_MQTT_PASSWD_BIN = '/opt/homebrew/bin/mosquitto_passwd';
const BOARD_SETTINGS_MQTT_PASSWD_FILE = '/opt/homebrew/etc/mosquitto/passwd';

/**
 * @return array{
 *   wifi_ssid: string, wifi_password: string, wifi_encryption: string, wifi_hidden: bool,
 *   battery_charging_threshold: int, battery_full_threshold: int,
 *   mqtt_sender_user: string, mqtt_sender_password: string
 * }
 */
function board_settings_load(mysqli $con): array
{
    $defaults = [
        'wifi_ssid' => '',
        'wifi_password' => '',
        'wifi_encryption' => 'WPA',
        'wifi_hidden' => false,
        'battery_charging_threshold' => 95,
        'battery_full_threshold' => 92,
        'mqtt_sender_user' => 'sender',
        'mqtt_sender_password' => '',
    ];

    // ABSICHTLICH abgefangen: initialize.php stellt mysqli auf
    // MYSQLI_REPORT_STRICT, eine fehlende Tabelle oder ein fehlendes GRANT
    // wuerfe also eine Exception. web/board.php liefert BILDER aus und faengt
    // RuntimeException als "Upstream-Fehler" -> das Geraet stuende auf HTTP 503
    // mit einem Log, das auf die Wiener Linien zeigt. Ein Deploy, dessen
    // Migration noch nicht gelaufen ist (deploy.py fuehrt sie fuer akadbrain
    // NICHT automatisch aus), darf das Board nicht abschalten: dann lieber die
    // Vorgabewerte und ein sprechender Log-Eintrag (Audit 2026-09-03).
    try {
        $result = $con->query(
            'SELECT wifi_ssid, wifi_password, wifi_encryption, wifi_hidden,
                    battery_charging_threshold, battery_full_threshold,
                    mqtt_sender_user, mqtt_sender_password
             FROM wl_board_settings WHERE id = 1'
        );
    } catch (mysqli_sql_exception $e) {
        error_log('board_settings_load: wl_board_settings nicht lesbar ('
            . $e->getMessage() . ') -- Migration/GRANT pruefen, nutze Vorgabewerte.');
        return $defaults;
    }

    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($row === null) {
        return $defaults;
    }

    return [
        'wifi_ssid' => (string) ($row['wifi_ssid'] ?? ''),
        'wifi_password' => (string) ($row['wifi_password'] ?? ''),
        'wifi_encryption' => (string) ($row['wifi_encryption'] ?? 'WPA'),
        'wifi_hidden' => (bool) ($row['wifi_hidden'] ?? false),
        'battery_charging_threshold' => (int) ($row['battery_charging_threshold'] ?? 95),
        'battery_full_threshold' => (int) ($row['battery_full_threshold'] ?? 92),
        'mqtt_sender_user' => (string) ($row['mqtt_sender_user'] ?? 'sender'),
        'mqtt_sender_password' => (string) ($row['mqtt_sender_password'] ?? ''),
    ];
}

/**
 * Stellt sicher, dass die Einzelzeile existiert. Alle Save-Funktionen sind
 * reine "UPDATE ... WHERE id = 1" -- fehlt die Zeile (Tabelle neu angelegt,
 * INSERT IGNORE der Migration nie gelaufen), traefe das Update 0 Zeilen und
 * die Seite meldete trotzdem Erfolg, waehrend board_settings_load() weiter
 * Vorgabewerte liefert (Audit 2026-09-03).
 */
function board_settings_ensure_row(mysqli $con): void
{
    $con->query('INSERT IGNORE INTO wl_board_settings (id) VALUES (1)');
}

/**
 * Gaeste-WLAN speichern. Leere SSID ist zulaessig (deaktiviert den QR-Block
 * auf dem Schlafschirm, wie zuvor eine fehlende data/guest_wifi.json).
 *
 * Leeres $password heisst "unveraendert lassen" -- wie beim MQTT-Sender und
 * wie es der Platzhalter im Formular ankuendigt. Ein bedingungsloses Schreiben
 * loeschte sonst bei JEDER Aenderung (SSID-Tippfehler, Haken "verstecktes
 * Netz") das Passwort, weil das Feld den Bestand nie anzeigt -- der QR-Code
 * auf dem Schlafschirm zeigte danach stumm ein leeres Passwort (Audit 2026-09-03).
 * Ein Passwort bewusst LEEREN geht ueber die Verschluesselungsart NOPASS.
 *
 * @return string|null Fehlermeldung, oder null bei Erfolg.
 */
function board_settings_save_wifi(
    mysqli $con,
    string $ssid,
    string $password,
    string $encryption,
    bool $hidden
): ?string {
    board_settings_ensure_row($con);
    $encryption = strtoupper($encryption);
    if (!in_array($encryption, ['WPA', 'WEP', 'NOPASS'], true)) {
        return 'Ungueltige Verschluesselungsart.';
    }
    if (mb_strlen($ssid) > 64) {
        return 'SSID darf hoechstens 64 Zeichen lang sein.';
    }
    if (mb_strlen($password) > 128) {
        return 'Passwort darf hoechstens 128 Zeichen lang sein.';
    }

    $hiddenInt = $hidden ? 1 : 0;
    // NOPASS = offenes Netz: hier MUSS ein bestehendes Passwort weichen,
    // sonst bliebe es unsichtbar in der DB stehen.
    if ($password !== '' || $encryption === 'NOPASS') {
        $stmt = $con->prepare(
            'UPDATE wl_board_settings
             SET wifi_ssid = ?, wifi_password = ?, wifi_encryption = ?, wifi_hidden = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = 1'
        );
        $stmt->bind_param('sssi', $ssid, $password, $encryption, $hiddenInt);
    } else {
        $stmt = $con->prepare(
            'UPDATE wl_board_settings
             SET wifi_ssid = ?, wifi_encryption = ?, wifi_hidden = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = 1'
        );
        $stmt->bind_param('ssi', $ssid, $encryption, $hiddenInt);
    }
    $stmt->execute();
    $stmt->close();

    return null;
}

/**
 * Akku-Kalibrierung speichern (s. board_battery_is_charging()/
 * board_battery_display_percent() in inc/board.php). full MUSS unter
 * charging liegen, sonst waere der "voll"-Bereich leer oder invertiert.
 *
 * @return string|null Fehlermeldung, oder null bei Erfolg.
 */
function board_settings_save_battery(mysqli $con, int $chargingThreshold, int $fullThreshold): ?string
{
    board_settings_ensure_row($con);
    if ($chargingThreshold < 1 || $chargingThreshold > 100 || $fullThreshold < 1 || $fullThreshold > 100) {
        return 'Schwellwerte muessen zwischen 1 und 100 liegen.';
    }
    if ($fullThreshold >= $chargingThreshold) {
        return 'Der "voll"-Schwellwert muss unter dem Lade-Schwellwert liegen.';
    }

    $stmt = $con->prepare(
        'UPDATE wl_board_settings
         SET battery_charging_threshold = ?, battery_full_threshold = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = 1'
    );
    $stmt->bind_param('ii', $chargingThreshold, $fullThreshold);
    $stmt->execute();
    $stmt->close();

    return null;
}

/**
 * Ob dieser Host den lokalen MQTT-Broker bedient (akadbrain) -- lokal/
 * world4you haben keinen, dort wird der Broker-Sync sauber uebersprungen
 * statt mit einem exec()-Fehler zu scheitern.
 */
function board_settings_mqtt_broker_reachable(): bool
{
    return is_executable(BOARD_SETTINGS_MQTT_PASSWD_BIN) && is_writable(BOARD_SETTINGS_MQTT_PASSWD_FILE);
}

/**
 * Aendert das Passwort des uebergebenen Users direkt in der Mosquitto-
 * Passwortdatei (dieselbe exec()-Technik wie web/mqtt/index.php fuer
 * mosquitto_pub -- kein neuer Rechte-Sprung). mosquitto liest password_file
 * automatisch neu ein (kein Broker-Neustart noetig, anders als acl_file).
 */
function board_settings_sync_mqtt_broker_password(string $user, string $password): bool
{
    $cmd = sprintf(
        '%s -b %s %s %s 2>&1',
        escapeshellarg(BOARD_SETTINGS_MQTT_PASSWD_BIN),
        escapeshellarg(BOARD_SETTINGS_MQTT_PASSWD_FILE),
        escapeshellarg($user),
        escapeshellarg($password)
    );
    exec($cmd, $output, $exitCode);

    return $exitCode === 0;
}

/**
 * Entfernt ein Broker-Konto (-D) -- noetig beim Umbenennen, sonst blieben die
 * alten Zugangsdaten gueltig. Fehlschlaege sind nicht fatal (das neue Konto
 * steht bereits), landen aber im Log, damit eine Karteileiche auffaellt.
 */
function board_settings_delete_mqtt_broker_user(string $user): bool
{
    $cmd = sprintf(
        '%s -D %s %s 2>&1',
        escapeshellarg(BOARD_SETTINGS_MQTT_PASSWD_BIN),
        escapeshellarg(BOARD_SETTINGS_MQTT_PASSWD_FILE),
        escapeshellarg($user)
    );
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        error_log('board_settings: altes MQTT-Konto "' . $user . '" konnte nicht entfernt werden.');
    }
    return $exitCode === 0;
}

/**
 * MQTT-Sender-Credentials speichern (web/mqtt/index.php). Leeres Passwort im
 * Formular heisst "unveraendert lassen" -- nur der Benutzername wird dann
 * aktualisiert, das Passwort in DB UND Broker bleibt wie es war.
 *
 * @return string|null Fehlermeldung, oder null bei Erfolg.
 */
function board_settings_save_mqtt_sender(mysqli $con, string $user, string $password): ?string
{
    board_settings_ensure_row($con);
    $user = trim($user);
    if ($user === '') {
        return 'Benutzername darf nicht leer sein.';
    }
    // Nicht nur Kosmetik: escapeshellarg() schuetzt die Shell, nicht den
    // Argument-Parser von mosquitto_passwd -- ein Name wie "-c" waere dort
    // eine Option ("Datei neu anlegen"), kein Benutzername.
    if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $user) || $user[0] === '-') {
        return 'Benutzername: nur Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich (max. 64, nicht mit "-" beginnend).';
    }
    if (mb_strlen($password) > 128) {
        return 'Passwort darf hoechstens 128 Zeichen lang sein.';
    }

    $aktuell = board_settings_load($con);
    // Ein neuer Benutzername OHNE Passwort liesse den Broker den Namen gar
    // nicht kennen: die DB zeigte dann auf ein Konto, das nicht existiert, und
    // /mqtt/ meldete nur noch "Broker nicht erreichbar", obwohl der Broker
    // laeuft (Audit 2026-09-03). Genau die Divergenz, die dieser Sync
    // verhindern soll -- also vorher abfangen.
    if ($password === '' && $user !== $aktuell['mqtt_sender_user']) {
        return 'Neuer Benutzername braucht auch ein Passwort -- sonst kennt der Broker das Konto nicht.';
    }

    if ($password !== '' && board_settings_mqtt_broker_reachable()) {
        if (!board_settings_sync_mqtt_broker_password($user, $password)) {
            return 'Broker-Synchronisierung fehlgeschlagen -- nichts gespeichert.';
        }
        // Umbenannt? Dann das alte Broker-Konto entwerten -- sonst bleiben die
        // alten Zugangsdaten unbegrenzt gueltig, ein "Passwortwechsel" waere
        // also gar keiner.
        if ($aktuell['mqtt_sender_user'] !== '' && $aktuell['mqtt_sender_user'] !== $user) {
            board_settings_delete_mqtt_broker_user($aktuell['mqtt_sender_user']);
        }
    }

    if ($password !== '') {
        $stmt = $con->prepare(
            'UPDATE wl_board_settings SET mqtt_sender_user = ?, mqtt_sender_password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1'
        );
        $stmt->bind_param('ss', $user, $password);
    } else {
        $stmt = $con->prepare(
            'UPDATE wl_board_settings SET mqtt_sender_user = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1'
        );
        $stmt->bind_param('s', $user);
    }
    $stmt->execute();
    $stmt->close();

    return null;
}
