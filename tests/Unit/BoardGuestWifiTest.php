<?php
// tests/Unit/BoardGuestWifiTest.php
//
// Gaeste-WLAN fuer den Schlafschirm (Nutzerwunsch 2026-08-23). Zugangsdaten
// kommen seit TASK-27 aus wl_board_settings (board_settings_load()), nicht
// mehr aus data/guest_wifi.json -- board_guest_wifi_load() ist deshalb eine
// reine Array-Transformation ohne Datei-I/O.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardGuestWifiTest extends TestCase
{
    /** @return array{wifi_ssid: string, wifi_password: string, wifi_encryption: string, wifi_hidden: bool} */
    private function settings(array $overrides = []): array
    {
        return array_merge([
            'wifi_ssid' => '',
            'wifi_password' => '',
            'wifi_encryption' => 'WPA',
            'wifi_hidden' => false,
        ], $overrides);
    }

    // --- Laden ---------------------------------------------------------------

    public function test_empty_ssid_yields_null_so_the_qr_block_is_skipped(): void
    {
        $this->assertNull(board_guest_wifi_load($this->settings()));
    }

    public function test_defaults_are_filled_in(): void
    {
        $wifi = board_guest_wifi_load($this->settings(['wifi_ssid' => 'Gaeste', 'wifi_password' => 'geheim']));

        $this->assertSame('Gaeste', $wifi['ssid']);
        $this->assertSame('WPA', $wifi['encryption'], 'ohne Angabe WPA annehmen');
        $this->assertFalse($wifi['hidden']);
    }

    public function test_unknown_encryption_falls_back_to_wpa(): void
    {
        // Verteidigung gegen unerwartete DB-Werte -- board_settings_save_wifi()
        // weist ungueltige Verschluesselungsarten schon beim Speichern zurueck,
        // aber der Loader soll trotzdem nicht mit einem kaputten QR-Code enden.
        $wifi = board_guest_wifi_load($this->settings(['wifi_ssid' => 'Gaeste', 'wifi_encryption' => 'WPA4711']));
        $this->assertSame('WPA', $wifi['encryption']);
    }

    // --- Nutzlast ------------------------------------------------------------

    public function test_payload_uses_the_wifi_scheme(): void
    {
        $payload = board_guest_wifi_payload([
            'ssid' => 'Gaeste', 'password' => 'geheim', 'encryption' => 'WPA', 'hidden' => false,
        ]);

        $this->assertSame('WIFI:T:WPA;S:Gaeste;P:geheim;;', $payload);
    }

    public function test_open_network_omits_the_password(): void
    {
        $payload = board_guest_wifi_payload([
            'ssid' => 'Offen', 'password' => '', 'encryption' => 'NOPASS', 'hidden' => false,
        ]);

        $this->assertSame('WIFI:T:nopass;S:Offen;;', $payload);
        $this->assertStringNotContainsString('P:', $payload);
    }

    public function test_hidden_network_is_flagged(): void
    {
        $payload = board_guest_wifi_payload([
            'ssid' => 'Versteckt', 'password' => 'x', 'encryption' => 'WPA', 'hidden' => true,
        ]);

        $this->assertStringContainsString('H:true;', $payload);
    }

    public function test_special_characters_are_escaped(): void
    {
        // Ohne Maskierung wuerde das Semikolon das Passwortfeld beenden -- der
        // Scanner laese "Sommer" als Passwort und den Rest als Muell.
        $payload = board_guest_wifi_payload([
            'ssid' => 'Cafe:Bar', 'password' => 'Sommer;2026\\Gast', 'encryption' => 'WPA', 'hidden' => false,
        ]);

        $this->assertStringContainsString('S:Cafe\\:Bar;', $payload);
        $this->assertStringContainsString('P:Sommer\;2026\\\\Gast;', $payload);
    }
}
