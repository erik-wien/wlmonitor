<?php
// tests/Unit/MonitorJsonShapeTest.php
//
// monitor_json.php wird abgesichert, ohne seine Antwortform zu aendern —
// Home Assistant parst sie. Dieser Test nagelt die Form fest.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class MonitorJsonShapeTest extends TestCase
{
    public function test_source_still_emits_the_unchanged_structure(): void
    {
        $src = file_get_contents(__DIR__ . '/../../web/monitor_json.php');

        // Die Ausgabe ist unveraendert monitor_get() — keine Umformung.
        $this->assertStringContainsString('monitor_get(', $src);
        $this->assertMatchesRegularExpression('/echo\s+json_encode\(\s*\$data/', $src);

        // Und die Haertung ist da:
        $this->assertStringContainsString('auth_api_request_user', $src,
            'Token-Pflicht fehlt');
        $this->assertStringNotContainsString('$_SESSION', $src,
            'Der Endpunkt darf keine Sitzung mehr anfassen');
        // Die Regel ist nicht "das Wort getMessage() kommt nicht vor", sondern
        // "die Ursache geht ins Log, nicht zum Client". Ein Textverbot wuerde
        // sonst den Variablennamen der Exception diktieren.
        foreach (explode("\n", $src) as $nr => $zeile) {
            if (!str_contains($zeile, 'getMessage()')) continue;
            $this->assertStringContainsString('appendLog(', $zeile,
                'getMessage() darf nur in einem appendLog()-Aufruf stehen, Zeile ' . ($nr + 1));
        }
        $this->assertStringContainsString('appendLog(', $src,
            'Jeder Fehlerpfad muss loggen (§21)');
    }

    /**
     * B4 (Review 2026-08-02): die departures-Abfrage stand VOR dem try —
     * mysqli laeuft im Exception-Modus (MYSQLI_REPORT_STRICT), ein fehlendes
     * GRANT oder eine fehlende Tabelle waere dort ein unbehandelter Fatal
     * (kein appendLog(), kaputtes JSON). web/board.php zieht den analogen
     * favorites_get()-Aufruf bewusst in den try-Block — hier muss dieselbe
     * DB-Abfrage ebenfalls DRIN liegen, nicht davor.
     */
    public function test_preferences_query_is_inside_the_protected_try_block(): void
    {
        $src = file_get_contents(__DIR__ . '/../../web/monitor_json.php');

        $tryPos   = strpos($src, 'try {');
        $queryPos = strpos($src, 'FROM wl_preferences');

        $this->assertNotFalse($tryPos, 'no try block found');
        $this->assertNotFalse($queryPos, 'no wl_preferences query found');
        $this->assertGreaterThan($tryPos, $queryPos,
            'the wl_preferences query must run INSIDE the try block, not before it');
    }
}
