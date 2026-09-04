<?php
declare(strict_types=1);

// Die Akku-Vorgaben (BOARD_BATTERY_*_MV_DEFAULT) stehen in inc/board.php, wo
// auch die Rechenfunktionen liegen. board_settings.php wird von vier Stellen
// eingebunden (web/board.php, web/board_settings.php, web/mqtt/index.php,
// tests/bootstrap.php) -- nicht alle laden board.php, also hier explizit.
require_once __DIR__ . '/board.php';

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
 *   battery_empty_mv: int, battery_full_mv: int, battery_charging_mv: int,
 *   battery_display_mode: 'percent'|'volt',
 *   device_idle_timeout_sec: int, device_refresh_interval_sec: int,
 *   device_wake_interval_sec: int,
 *   device_quiet_start_hour: int, device_quiet_end_hour: int,
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
        'battery_empty_mv' => BOARD_BATTERY_EMPTY_MV_DEFAULT,
        'battery_full_mv' => BOARD_BATTERY_FULL_MV_DEFAULT,
        'battery_charging_mv' => BOARD_BATTERY_CHARGING_MV_DEFAULT,
        'battery_display_mode' => 'percent',
        'device_idle_timeout_sec' => 600,
        'device_refresh_interval_sec' => 25,
        'device_wake_interval_sec' => 3600,
        'device_quiet_start_hour' => 0,
        'device_quiet_end_hour' => 6,
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
                    battery_empty_mv, battery_full_mv, battery_charging_mv,
                    battery_display_mode,
                    device_idle_timeout_sec, device_refresh_interval_sec,
                    device_wake_interval_sec,
                    device_quiet_start_hour, device_quiet_end_hour,
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
        'battery_empty_mv' => (int) ($row['battery_empty_mv'] ?? BOARD_BATTERY_EMPTY_MV_DEFAULT),
        'battery_full_mv' => (int) ($row['battery_full_mv'] ?? BOARD_BATTERY_FULL_MV_DEFAULT),
        'battery_charging_mv' => (int) ($row['battery_charging_mv'] ?? BOARD_BATTERY_CHARGING_MV_DEFAULT),
        'battery_display_mode' => ($row['battery_display_mode'] ?? 'percent') === 'volt' ? 'volt' : 'percent',
        'device_idle_timeout_sec' => (int) ($row['device_idle_timeout_sec'] ?? 600),
        'device_refresh_interval_sec' => (int) ($row['device_refresh_interval_sec'] ?? 25),
        'device_wake_interval_sec' => (int) ($row['device_wake_interval_sec'] ?? 3600),
        'device_quiet_start_hour' => (int) ($row['device_quiet_start_hour'] ?? 0),
        'device_quiet_end_hour' => (int) ($row['device_quiet_end_hour'] ?? 6),
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
 * Akku-Kalibrierung speichern -- in MILLIVOLT, nicht in Prozent
 * (Nutzerbefund 2026-09-04, Herleitung s. migrations/007_board_battery_volts.sql).
 *
 * Reihenfolge leer < voll <= laedt ist Pflicht, nicht Geschmack:
 * - leer >= voll waere eine Division durch null bzw. eine invertierte Skala
 *   in board_battery_percent_from_mv().
 * - laedt < voll hiesse, dass der Blitz schon erscheint, bevor 100 %
 *   ueberhaupt erreichbar sind -- der volle Ladestand waere nie zu sehen.
 *
 * Die Grenzen 2500..5000 mV umschliessen jede sinnvolle Einzelzelle mit
 * Reserve; ausserhalb liegt entweder ein Tippfehler oder ein anderer Akkutyp,
 * und beides gehoert bemerkt statt stillschweigend in ein Bild gerechnet.
 */
function board_settings_save_battery(
    mysqli $con,
    int $emptyMv,
    int $fullMv,
    int $chargingMv,
    string $displayMode
): ?string {
    board_settings_ensure_row($con);

    foreach (['leer' => $emptyMv, 'voll' => $fullMv, 'laedt' => $chargingMv] as $name => $wert) {
        if ($wert < 2500 || $wert > 5000) {
            return sprintf('Spannung "%s" muss zwischen 2,50 V und 5,00 V liegen.', $name);
        }
    }
    if ($emptyMv >= $fullMv) {
        return 'Die "leer"-Spannung muss unter der "voll"-Spannung liegen.';
    }
    if ($chargingMv < $fullMv) {
        return 'Die "laedt"-Spannung darf nicht unter der "voll"-Spannung liegen -- sonst waere der volle Ladestand nie zu sehen.';
    }
    if ($displayMode !== 'percent' && $displayMode !== 'volt') {
        return 'Unbekannte Anzeigeart.';
    }

    // Gefangen, weil diese Spalten aus Migration 007 stammen und deploy.py auf
    // akadbrain GAR KEINE Migration ausfuehrt (s. den Hinweis am Ende von
    // 006_wl_board_settings.sql). Zwischen Deploy und von Hand eingespielter
    // Migration gibt es also ein Fenster, in dem die Spalten fehlen --
    // mysqli_report(MYSQLI_REPORT_STRICT) macht daraus sonst einen Fatal auf
    // der Adminseite, statt zu sagen, was zu tun ist. board_settings_load()
    // faengt denselben Fall bereits ab (dort mit Rueckfall auf die Vorgaben,
    // damit das E-Paper-Board nicht auf 503 faellt).
    try {
        $stmt = $con->prepare(
            'UPDATE wl_board_settings
             SET battery_empty_mv = ?, battery_full_mv = ?, battery_charging_mv = ?,
                 battery_display_mode = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = 1'
        );
        $stmt->bind_param('iiis', $emptyMv, $fullMv, $chargingMv, $displayMode);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        return 'Speichern nicht moeglich - vermutlich fehlt die Migration '
             . '007_board_battery_volts.sql auf diesem Server. Bis dahin gelten die Vorgabewerte.';
    }

    return null;
}

/**
 * Zeitverhalten des Geraets speichern (Migration 008). Alle Werte gehen als
 * Antwort-Header an die Firmware, s. web/board.php.
 *
 * Die Untergrenzen sind keine Willkuer, sondern die gemessenen Kosten eines
 * Abrufs: ein kompletter Zyklus (TLS-Handshake, WL-API, Bildaufbau, Zeichnen)
 * dauert am Geraet 3-4 Sekunden. Ein Nachladeintervall darunter hiesse, dass
 * der naechste Abruf beginnt, bevor der vorige fertig ist -- die Aktiv-Session
 * waere dauerbeschaeftigt und reagierte nicht mehr auf Beruehrungen.
 *
 * Obergrenzen: 3600 s Untaetigkeit ist eine Stunde wach ohne Eingabe (der
 * Akku haelt keinen Dauerbetrieb aus), 86400 s Weckintervall ist ein Tag.
 */
function board_settings_save_device_timing(
    mysqli $con,
    int $idleTimeoutSec,
    int $refreshIntervalSec,
    int $wakeIntervalSec,
    int $quietStartHour,
    int $quietEndHour
): ?string {
    board_settings_ensure_row($con);

    if ($idleTimeoutSec < 30 || $idleTimeoutSec > 3600) {
        return 'Einschlaf-Frist muss zwischen 30 s und 3600 s liegen.';
    }
    if ($refreshIntervalSec < 10 || $refreshIntervalSec > 600) {
        return 'Nachladeintervall muss zwischen 10 s und 600 s liegen -- ein Abruf dauert am Geraet 3-4 s.';
    }
    if ($wakeIntervalSec < 300 || $wakeIntervalSec > 86400) {
        return 'Weckintervall muss zwischen 300 s und 86400 s liegen.';
    }
    foreach ([$quietStartHour, $quietEndHour] as $stunde) {
        if ($stunde < 0 || $stunde > 23) {
            return 'Ruhezeit-Stunden muessen zwischen 0 und 23 liegen.';
        }
    }
    // Nicht geprueft: start == end. Das heisst bewusst "keine Ruhezeit"
    // (rund um die Uhr wecken) und ist eine gueltige Einstellung, keine
    // Fehleingabe -- s. secondsUntilNextAutomaticWake() in der Firmware.

    try {
        $stmt = $con->prepare(
            'UPDATE wl_board_settings
             SET device_idle_timeout_sec = ?, device_refresh_interval_sec = ?,
                 device_wake_interval_sec = ?, device_quiet_start_hour = ?,
                 device_quiet_end_hour = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = 1'
        );
        $stmt->bind_param('iiiii', $idleTimeoutSec, $refreshIntervalSec, $wakeIntervalSec, $quietStartHour, $quietEndHour);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        return 'Speichern nicht moeglich - vermutlich fehlt die Migration '
             . '008_board_device_timing.sql auf diesem Server. Bis dahin gelten die Vorgabewerte.';
    }

    return null;
}

/**
 * "4,13" / "4.13" / "4130" -> 4130 mV. Die Adminseite laesst Volt eintippen
 * (so steht es auf jedem Messgeraet), gespeichert wird ganzzahlig in mV --
 * Gleitkomma-Rundung hat in einer Kalibrierung nichts verloren.
 *
 * Werte ueber 100 gelten als bereits in mV eingegeben: 4,13 V und 4130 mV
 * sind beide eindeutig, dazwischen gibt es keinen plausiblen Akkuwert.
 */
function board_settings_volt_input_to_mv(string $eingabe): ?int
{
    $eingabe = trim(str_replace(',', '.', $eingabe));
    if ($eingabe === '' || !is_numeric($eingabe)) {
        return null;
    }
    $zahl = (float) $eingabe;
    return (int) round($zahl > 100 ? $zahl : $zahl * 1000);
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

/** SIGHUP an mosquitto -- s. board_settings_reload_mqtt_broker(). */
const BOARD_SETTINGS_PKILL_BIN = '/usr/bin/pkill';

/**
 * Faehrt mosquitto dazu, password_file neu einzulesen.
 *
 * NICHT optional: mosquitto liest die Datei NUR beim Start und bei SIGHUP,
 * nicht bei jeder Anmeldung. Am 2026-09-04 an der Produktion nachgemessen --
 * nach dem Schreiben der Datei wies der laufende Broker das neue Passwort mit
 * "Connection Refused: not authorised" ab, bis SIGHUP kam. Ohne diesen Aufruf
 * haette die Admin-Seite bei jedem Passwortwechsel das Senden lahmgelegt:
 * Datei und DB neu, Broker im Speicher alt -- und die Fehlermeldung in
 * /mqtt/ lautet nur "Broker nicht erreichbar", nennt also nicht die Ursache.
 *
 * Zulaessig, weil php-fpm und mosquitto auf akadbrain beide als derselbe
 * Benutzer laufen (verifiziert); sonst schlueg das Signal mit EPERM fehl.
 */
function board_settings_reload_mqtt_broker(): bool
{
    $cmd = sprintf('%s -HUP -x mosquitto 2>&1', escapeshellarg(BOARD_SETTINGS_PKILL_BIN));
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        error_log('board_settings: mosquitto konnte nicht zum Neuladen bewegt werden (SIGHUP fehlgeschlagen) -- '
            . 'der Broker nutzt weiter das ALTE Passwort, Senden schlaegt bis zu einem Neustart fehl.');
    }
    return $exitCode === 0;
}

/**
 * Aendert das Passwort des uebergebenen Users direkt in der Mosquitto-
 * Passwortdatei (dieselbe exec()-Technik wie web/mqtt/index.php fuer
 * mosquitto_pub -- kein neuer Rechte-Sprung) und laesst den Broker die Datei
 * anschliessend neu einlesen.
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

    if ($exitCode !== 0) {
        return false;
    }
    // Erst nach erfolgreichem Schreiben neu laden -- ein SIGHUP auf eine
    // unveraenderte Datei waere sinnlos, aber harmlos; umgekehrt waere ein
    // Schreiben ohne Reload genau der Ausfall, den diese Zeile verhindert.
    return board_settings_reload_mqtt_broker();
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
        return false;
    }
    // Auch das Loeschen braucht den Reload: ohne ihn bliebe das entfernte
    // Konto im Speicher des laufenden Brokers gueltig -- der "Widerruf" waere
    // bis zum naechsten Neustart wirkungslos.
    return board_settings_reload_mqtt_broker();
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

/**
 * Datei, aus der calsync.swift die Auswahl liest -- und zugleich die EINZIGE
 * Quelle dafuer.
 *
 * Bewusst NICHT zusaetzlich in wl_board_settings: calsync.swift kann kein
 * MySQL, es MUSS also ohnehin diese Datei lesen. Eine DB-Spalte daneben waere
 * doppelter Zustand, der auseinanderlaeuft, sobald einer der beiden Wege
 * einmal fehlschlaegt -- ohne dass irgendetwas davon auffiele.
 *
 * (Ursprünglich sprach ein zweiter Grund dagegen: die Verbindung stand auf
 * Zeichensatz "utf8" (3 Byte) und wies die Emoji in den Kalendertiteln mit
 * "Incorrect string value" ab. Das ist seit der Umstellung auf utf8mb4
 * erledigt -- der Grund oben traegt aber weiterhin.)
 *
 * Ablageort neben dem Cache in data/calendar/: dort schreibt der Webserver
 * ohnehin, und data/ ist vom Deploy ausgenommen, ueberlebt also ein Update.
 */
function board_settings_calendar_selection_path(): string
{
    return __DIR__ . '/../data/calendar/selection.json';
}

/**
 * Aktuell hinterlegte Auswahl: Kalendername -> Kuerzel ("(E)", "(A)", ...).
 * Leeres Kuerzel = Kalender ausgewaehlt, aber ohne Markierung am Termin.
 * Leeres Array = keine eigene Auswahl; calsync bleibt dann bei
 * CALSYNC_CALENDARS bzw. seiner eingebauten Liste.
 *
 * Nimmt auch das fruehere reine Array entgegen (dann ohne Kuerzel), damit
 * eine aeltere Datei nicht zu einer leeren Auswahl fuehrt.
 *
 * @return array<string, string>
 */
function board_calendar_selection(): array
{
    $pfad = board_settings_calendar_selection_path();
    if (!is_file($pfad)) {
        return [];
    }
    $roh = json_decode((string) @file_get_contents($pfad), true);
    if (!is_array($roh)) {
        return [];
    }

    $auswahl = [];
    foreach ($roh as $schluessel => $wert) {
        // Altes Format: [0 => "Name"]. Neues: ["Name" => "(E)"].
        $name = is_int($schluessel) ? (is_string($wert) ? $wert : '') : (string) $schluessel;
        $kuerzel = is_int($schluessel) ? '' : (is_string($wert) ? $wert : '');
        $name = trim($name);
        if ($name !== '') {
            $auswahl[$name] = trim($kuerzel);
        }
    }

    return $auswahl;
}

/**
 * Schreibt die Auswahl fuer calsync -- atomar (tmp + rename) wie der
 * Wetter-Cron und calsync selbst: eine halb geschriebene Datei waere fuer
 * calsync nicht von Muell zu unterscheiden.
 *
 * Leere Auswahl -> Datei ENTFERNEN statt leer schreiben. calsync faellt dann
 * auf seine eingebaute Liste zurueck; eine leere Liste wuerde sonst "gar keine
 * Kalender" bedeuten und das Board stumm stellen.
 *
 * @param array<string, string> $auswahl Kalendername -> Kuerzel
 * @return string|null Fehlermeldung, oder null bei Erfolg.
 */
function board_settings_save_calendars(array $auswahl): ?string
{
    $sauber = [];
    foreach ($auswahl as $name => $kuerzel) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $kuerzel = trim((string) $kuerzel);
        // Kurz halten: das Kuerzel steht VOR dem Titel und frisst sonst die
        // Zeilenbreite, die fuer den Termin gedacht ist.
        if (mb_strlen($kuerzel) > 6) {
            return 'Kuerzel darf hoechstens 6 Zeichen lang sein (z. B. "(E)").';
        }
        $sauber[$name] = $kuerzel;
    }
    if (count($sauber) > 50) {
        return 'Zu viele Kalender ausgewaehlt.';
    }

    $pfad = board_settings_calendar_selection_path();

    if ($sauber === []) {
        if (is_file($pfad) && !@unlink($pfad)) {
            error_log('board_settings: Kalenderauswahl nicht entfernbar: ' . $pfad);
            return 'Auswahl konnte nicht zurueckgesetzt werden.';
        }
        return null;
    }

    $verzeichnis = dirname($pfad);
    if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0755, true) && !is_dir($verzeichnis)) {
        return 'Ablageverzeichnis nicht anlegbar.';
    }

    $tmp = $pfad . '.tmp';
    $json = json_encode($sauber, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $pfad)) {
        @unlink($tmp);
        error_log('board_settings: Kalenderauswahl nicht schreibbar: ' . $pfad);
        return 'Auswahl konnte nicht gespeichert werden.';
    }

    return null;
}

/**
 * Welche Kalender kennt der Server? Steht im Kalender-Cache, den calsync
 * schreibt (available_calendars) -- nur EventKit kann sie aufzaehlen, PHP
 * nicht. Fehlt der Cache oder das Feld (calsync noch nicht neu gebaut),
 * kommt eine leere Liste zurueck; die Seite sagt dann, woran es liegt,
 * statt eine leere Auswahl anzubieten.
 *
 * @return list<string>
 */
function board_settings_available_calendars(int $userId): array
{
    $cache = board_calendar_load($userId);
    $namen = is_array($cache) ? ($cache['available_calendars'] ?? null) : null;
    if (!is_array($namen)) {
        return [];
    }
    $namen = array_values(array_filter($namen, 'is_string'));
    sort($namen, SORT_NATURAL | SORT_FLAG_CASE);

    return $namen;
}
