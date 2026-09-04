<?php
// tests/Integration/BoardSettingsTest.php
//
// Board-Einstellungen (TASK-27): wl_board_settings ist eine Single-Row-
// Tabelle (id=1 fix, migrations/006_wl_board_settings.sql) -- Tests laufen
// in einer Transaktion (IntegrationTestCase), Aenderungen an der einen Zeile
// werden nach jedem Test zurueckgerollt.

namespace WLMonitor\Tests\Integration;

class BoardSettingsTest extends IntegrationTestCase
{
    /**
     * Schutz vor echtem Schaden: board_settings_save_mqtt_sender() ruft bei
     * gesetztem Passwort mosquitto_passwd auf, sobald der Broker erreichbar
     * ist. Auf akadbrain (Broker + schreibbare passwd-Datei) wuerde dieser
     * Test damit PRODUKTIVE Zugangsdaten ueberschreiben -- und anders als die
     * DB rollt die Passwortdatei nicht zurueck. Ergebnis waere ein stiller
     * Ausfall von /mqtt/ ("Broker nicht erreichbar"), dessen Ursache nirgends
     * steht (Audit 2026-09-03). Deshalb hart ueberspringen statt nur im
     * Kommentar anzunehmen, dass hier kein Broker laeuft.
     */
    private function skipIfBrokerPresent(): void
    {
        if (\board_settings_mqtt_broker_reachable()) {
            $this->markTestSkipped(
                'Lokaler MQTT-Broker erreichbar -- Test wuerde echte Broker-Zugangsdaten ueberschreiben.'
            );
        }
    }

    // --- Laden -----------------------------------------------------------

    public function test_load_returns_seeded_defaults(): void
    {
        $settings = \board_settings_load($this->con);

        $this->assertSame('', $settings['wifi_ssid']);
        $this->assertSame('WPA', $settings['wifi_encryption']);
        $this->assertFalse($settings['wifi_hidden']);
        $this->assertSame(95, $settings['battery_charging_threshold']);
        $this->assertSame(92, $settings['battery_full_threshold']);
        $this->assertSame('sender', $settings['mqtt_sender_user']);
        $this->assertSame('', $settings['mqtt_sender_password']);
    }

    // --- WiFi --------------------------------------------------------------

    public function test_save_wifi_persists_and_reloads(): void
    {
        $err = \board_settings_save_wifi($this->con, 'Gaeste', 'geheim123', 'wpa', true);

        $this->assertNull($err);
        $settings = \board_settings_load($this->con);
        $this->assertSame('Gaeste', $settings['wifi_ssid']);
        $this->assertSame('geheim123', $settings['wifi_password']);
        $this->assertSame('WPA', $settings['wifi_encryption'], 'wird normalisiert (strtoupper)');
        $this->assertTrue($settings['wifi_hidden']);
    }

    public function test_save_wifi_rejects_unknown_encryption(): void
    {
        $err = \board_settings_save_wifi($this->con, 'Gaeste', '', 'WPA4711', false);

        $this->assertNotNull($err);
        $this->assertSame('', \board_settings_load($this->con)['wifi_ssid'], 'nichts gespeichert bei Fehler');
    }

    public function test_save_wifi_with_empty_password_keeps_the_existing_one(): void
    {
        // Der Platzhalter im Formular verspricht "unveraendert lassen = leer
        // senden". Vorher wurde das Passwort bei JEDER Aenderung geloescht --
        // wer nur die SSID korrigierte, zerstoerte den QR-Code, ohne dass es
        // irgendwo sichtbar war (Audit 2026-09-03).
        \board_settings_save_wifi($this->con, 'Gaeste', 'geheim123', 'WPA', false);

        $err = \board_settings_save_wifi($this->con, 'Gaeste-neu', '', 'WPA', true);

        $this->assertNull($err);
        $settings = \board_settings_load($this->con);
        $this->assertSame('Gaeste-neu', $settings['wifi_ssid']);
        $this->assertTrue($settings['wifi_hidden']);
        $this->assertSame('geheim123', $settings['wifi_password'], 'Passwort darf nicht stillschweigend verschwinden');
    }

    public function test_save_wifi_nopass_clears_the_stored_password(): void
    {
        // Offenes Netz: hier MUSS das Passwort weichen, sonst bliebe es
        // unsichtbar in der DB stehen.
        \board_settings_save_wifi($this->con, 'Gaeste', 'geheim123', 'WPA', false);

        \board_settings_save_wifi($this->con, 'Gaeste', '', 'NOPASS', false);

        $this->assertSame('', \board_settings_load($this->con)['wifi_password']);
    }

    public function test_save_wifi_rejects_overlong_values(): void
    {
        // Ohne serverseitige Pruefung liefe das unter STRICT_TRANS_TABLES in
        // "Data too long" -> ungefangene mysqli_sql_exception -> Fatal.
        $this->assertNotNull(\board_settings_save_wifi($this->con, str_repeat('a', 65), '', 'WPA', false));
        $this->assertNotNull(\board_settings_save_wifi($this->con, 'ok', str_repeat('b', 129), 'WPA', false));
    }

    public function test_save_wifi_allows_empty_ssid_to_disable_qr_block(): void
    {
        \board_settings_save_wifi($this->con, 'Gaeste', 'x', 'WPA', false);
        $err = \board_settings_save_wifi($this->con, '', '', 'WPA', false);

        $this->assertNull($err);
        $this->assertSame('', \board_settings_load($this->con)['wifi_ssid']);
    }

    // --- Akku-Kalibrierung -------------------------------------------------

    public function test_save_battery_persists(): void
    {
        $err = \board_settings_save_battery($this->con, 90, 85);

        $this->assertNull($err);
        $settings = \board_settings_load($this->con);
        $this->assertSame(90, $settings['battery_charging_threshold']);
        $this->assertSame(85, $settings['battery_full_threshold']);
    }

    public function test_save_battery_rejects_full_at_or_above_charging(): void
    {
        $err = \board_settings_save_battery($this->con, 95, 95);
        $this->assertNotNull($err);

        $err = \board_settings_save_battery($this->con, 90, 95);
        $this->assertNotNull($err);
    }

    public function test_save_battery_rejects_out_of_range_values(): void
    {
        $this->assertNotNull(\board_settings_save_battery($this->con, 101, 50));
        $this->assertNotNull(\board_settings_save_battery($this->con, 50, 0));
    }

    // --- MQTT-Sender ---------------------------------------------------------

    public function test_save_mqtt_sender_rejects_empty_user(): void
    {
        $err = \board_settings_save_mqtt_sender($this->con, '', 'irgendwas');
        $this->assertNotNull($err);
    }

    public function test_save_mqtt_sender_rejects_names_that_look_like_options(): void
    {
        // escapeshellarg() schuetzt die Shell, nicht den Argument-Parser von
        // mosquitto_passwd -- "-c" waere dort "Datei neu anlegen".
        $this->assertNotNull(\board_settings_save_mqtt_sender($this->con, '-c', 'x'));
        $this->assertNotNull(\board_settings_save_mqtt_sender($this->con, 'user; rm -rf /', 'x'));
    }

    public function test_save_mqtt_sender_updates_user_and_password(): void
    {
        $this->skipIfBrokerPresent();
        $err = \board_settings_save_mqtt_sender($this->con, 'testsender', 'neuespasswort');

        $this->assertNull($err);
        $settings = \board_settings_load($this->con);
        $this->assertSame('testsender', $settings['mqtt_sender_user']);
        $this->assertSame('neuespasswort', $settings['mqtt_sender_password']);
    }

    public function test_save_mqtt_sender_with_empty_password_keeps_existing_password(): void
    {
        $this->skipIfBrokerPresent();
        \board_settings_save_mqtt_sender($this->con, 'sender', 'altespasswort');

        // Gleicher Name, leeres Passwort = unveraendert lassen.
        $err = \board_settings_save_mqtt_sender($this->con, 'sender', '');

        $this->assertNull($err);
        $settings = \board_settings_load($this->con);
        $this->assertSame('altespasswort', $settings['mqtt_sender_password'], 'leeres Passwort im Formular = unveraendert lassen');
    }

    public function test_renaming_the_mqtt_user_without_a_password_is_rejected(): void
    {
        $this->skipIfBrokerPresent();
        \board_settings_save_mqtt_sender($this->con, 'sender', 'altespasswort');

        // Ohne Passwort kann der Broker den neuen Namen nicht kennen -- die DB
        // zeigte sonst auf ein Konto, das dort nicht existiert, und /mqtt/
        // meldete nur noch "Broker nicht erreichbar" (Audit 2026-09-03).
        $err = \board_settings_save_mqtt_sender($this->con, 'neuerNutzername', '');

        $this->assertNotNull($err);
        $this->assertSame('sender', \board_settings_load($this->con)['mqtt_sender_user'], 'nichts gespeichert');
    }

    // --- Kalenderauswahl (Nutzerwunsch 2026-09-04) -----------------------------

    public function test_calendar_selection_round_trips_through_the_file(): void
    {
        // Emoji im Titel sind der Normalfall ("🔜 Eriks Termine") -- und der
        // Grund, warum die Auswahl NICHT in der DB liegt: die Verbindung steht
        // auf Zeichensatz "utf8" (3 Byte) und weist 4-Byte-Zeichen ab.
        $err = \board_settings_save_calendars(['🔜 Eriks Termine', 'Birthdays']);

        $this->assertNull($err);
        $this->assertSame(['🔜 Eriks Termine', 'Birthdays'], \board_settings_read_calendar_selection());

        $pfad = \board_settings_calendar_selection_path();
        $this->assertFileExists($pfad);
        $this->assertSame(['🔜 Eriks Termine', 'Birthdays'], json_decode((string) file_get_contents($pfad), true));
    }

    public function test_empty_selection_removes_the_file_so_calsync_keeps_its_defaults(): void
    {
        // Nichts angekreuzt darf NICHT "gar keine Kalender" heissen -- sonst
        // stuende das Board nach einem Fehlgriff stumm da.
        \board_settings_save_calendars(['Birthdays']);
        $this->assertFileExists(\board_settings_calendar_selection_path());

        $err = \board_settings_save_calendars([]);

        $this->assertNull($err);
        $this->assertFileDoesNotExist(\board_settings_calendar_selection_path());
        $this->assertSame([], \board_settings_read_calendar_selection());
    }

    public function test_calendar_names_with_any_character_survive(): void
    {
        // JSON statt Trennzeichen: ein "|" im Titel war frueher ein Problem,
        // jetzt nicht mehr.
        \board_settings_save_calendars(['Kaputt|Name', 'Komma, Punkt']);
        $this->assertSame(['Kaputt|Name', 'Komma, Punkt'], \board_settings_read_calendar_selection());
    }

    public function test_available_calendars_come_from_the_calsync_cache(): void
    {
        // Nur calsync (EventKit) kann Kalender aufzaehlen; PHP liest die Liste
        // aus dem Cache. Fehlt sie (aeltere calsync-Fassung), kommt eine leere
        // Liste zurueck statt einer Falschauskunft.
        $this->assertIsArray(\board_settings_available_calendars(999999));
        $this->assertSame([], \board_settings_available_calendars(999999));
    }
}
