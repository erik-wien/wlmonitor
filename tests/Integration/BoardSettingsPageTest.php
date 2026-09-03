<?php
// tests/Integration/BoardSettingsPageTest.php
//
// web/board_settings.php (TASK-27): Admin-only ueber auth_require() +
// admin_require(). Laeuft wie PageProbeTest.php out-of-process (die Seite
// ruft exit() ueber diese Guards auf) -- GET-only hier, deshalb kein
// DB-Cleanup noetig (board_settings_load() liest nur).

namespace WLMonitor\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class BoardSettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
    }

    /** @return array{status: ?int, out: string} */
    private function runPageProbe(string $page, array $scenario): array
    {
        $scenarioFile = tempnam(sys_get_temp_dir(), 'wlm_page_');
        file_put_contents($scenarioFile, json_encode($scenario));
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/page_probe.php')
             . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($scenarioFile);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($scenarioFile);

        $status = null;
        if (preg_match('/STATUS:(\d+)/', $stderr, $m)) {
            $status = (int) $m[1];
        }
        return ['status' => $status, 'out' => $stdout];
    }

    public function test_without_session_redirects_no_fatal(): void
    {
        $r = $this->runPageProbe('board_settings.php', ['loggedin' => false]);
        $this->assertSame(302, $r['status'], 'expected a redirect, not a fatal');
        $this->assertSame('', $r['out'], 'no HTML leaked before the redirect');
    }

    /**
     * Suite-Policy-Grundsatz: UI-Ausblendung ist keine Zugriffskontrolle --
     * dieser Test prueft den server-seitigen Guard direkt, unabhaengig
     * davon, ob der "Board-Einstellungen"-Link im Header fuer Nicht-Admins
     * sichtbar ist.
     */
    public function test_non_admin_is_redirected_not_shown_the_page(): void
    {
        $r = $this->runPageProbe('board_settings.php', [
            'loggedin' => true, 'id' => 999999, 'username' => 'probe-user', 'rights' => 'User',
        ]);

        $this->assertSame(302, $r['status'], 'admin_require() must redirect non-admins');
        $this->assertSame('', $r['out'], 'no settings markup leaked before the redirect');
    }

    public function test_admin_renders_all_three_sections(): void
    {
        $r = $this->runPageProbe('board_settings.php', [
            'loggedin' => true, 'id' => 999999, 'username' => 'probe-admin', 'rights' => 'Admin',
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Gäste-WLAN', $r['out']);
        $this->assertStringContainsString('Akku-Kalibrierung', $r['out']);
        $this->assertStringContainsString('MQTT-Sender-Zugangsdaten', $r['out']);

        // Passwortfelder duerfen NIE den Bestandswert ausspucken. Bewusst
        // STRUKTURELL geprueft (gar kein value=-Attribut) statt auf einen
        // nicht-leeren Wert: in der Testdatenbank sind die Passwortspalten im
        // Normalfall leer, ein Regex auf value="[^"]+" koennte also gar nicht
        // matchen und bliebe auch dann gruen, wenn die Seite den Wert ausgibt
        // -- Scheinsicherheit (Audit 2026-09-03).
        foreach (['wifi_password', 'mqtt_sender_password'] as $feld) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*name="' . $feld . '"[^>]*>/',
                $r['out'],
                "Feld $feld fehlt"
            );
            preg_match('/<input[^>]*name="' . $feld . '"[^>]*>/', $r['out'], $m);
            $this->assertStringNotContainsString(
                'value=',
                $m[0],
                "$feld darf kein value=-Attribut tragen (auch kein leeres) -- sonst wandert der Bestand ins HTML"
            );
        }
    }
}
