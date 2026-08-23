<?php
// tests/Unit/BoardGuestWifiTest.php
//
// Gaeste-WLAN fuer den Schlafschirm (Nutzerwunsch 2026-08-23). Die
// Zugangsdaten liegen in data/guest_wifi.json -- ausserhalb von Git und
// ausserhalb des Deploys.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardGuestWifiTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wl_guest_wifi_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmp)) {
            unlink($this->tmp);
        }
    }

    private function write(array $data): string
    {
        file_put_contents($this->tmp, json_encode($data));
        return $this->tmp;
    }

    // --- Laden ---------------------------------------------------------------

    public function test_missing_file_yields_null_so_the_qr_block_is_skipped(): void
    {
        $this->assertNull(board_guest_wifi_load('/nicht/vorhanden.json'));
    }

    public function test_invalid_json_yields_null(): void
    {
        file_put_contents($this->tmp, '{kaputt');
        $this->assertNull(board_guest_wifi_load($this->tmp));
    }

    public function test_missing_ssid_yields_null(): void
    {
        $this->assertNull(board_guest_wifi_load($this->write(['password' => 'geheim'])));
    }

    public function test_defaults_are_filled_in(): void
    {
        $wifi = board_guest_wifi_load($this->write(['ssid' => 'Gaeste', 'password' => 'geheim']));

        $this->assertSame('Gaeste', $wifi['ssid']);
        $this->assertSame('WPA', $wifi['encryption'], 'ohne Angabe WPA annehmen');
        $this->assertFalse($wifi['hidden']);
    }

    public function test_unknown_encryption_falls_back_to_wpa(): void
    {
        $wifi = board_guest_wifi_load($this->write(['ssid' => 'Gaeste', 'encryption' => 'WPA4711']));
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
