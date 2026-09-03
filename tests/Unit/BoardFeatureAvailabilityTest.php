<?php
// tests/Unit/BoardFeatureAvailabilityTest.php
//
// Nutzervorgabe 2026-09-03: "eInk Display gibts nur auf eriks.cloud, nicht
// jardyx!!!" -- das E-Paper-Geraet haengt an genau einer Instanz (akadbrain).
// Nur dort laeuft der mosquitto-Broker, den web/mqtt/ zum Senden braucht.
// Auf world4you fuehrte der Menuepunkt auf eine Seite, die grundsaetzlich
// "Senden fehlgeschlagen" meldet.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardFeatureAvailabilityTest extends TestCase
{
    public function test_feature_is_on_for_the_instance_that_owns_the_device(): void
    {
        $this->assertTrue(wl_board_feature_available('akadbrain'), 'eriks.cloud betreibt Display + Broker');
        $this->assertTrue(wl_board_feature_available('local'), 'Entwicklung auf Hamish');
    }

    public function test_feature_is_off_on_jardyx(): void
    {
        $this->assertFalse(wl_board_feature_available('world4you'), 'jardyx.com hat weder Broker noch Display');
    }

    public function test_unknown_environments_do_not_get_the_feature_by_accident(): void
    {
        // Positivliste, keine Ausschlussliste: eine neue Umgebung bekommt das
        // Feature nur, wenn sie ausdruecklich eingetragen wird.
        $this->assertFalse(wl_board_feature_available('staging'));
        $this->assertFalse(wl_board_feature_available('dev'));
        $this->assertFalse(wl_board_feature_available(''));
    }
}
