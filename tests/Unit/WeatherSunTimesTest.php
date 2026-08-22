<?php
// tests/Unit/WeatherSunTimesTest.php
//
// Sonnenauf-/-untergang fuer die Wetterkarte (Nutzerwunsch 2026-08-22).
// Bewusst gerechnet statt gescrapt -- date_sun_info() braucht kein Netz,
// keinen Cache und keinen Cron-Lauf, also auch keinen weiteren Ausfallpfad
// im Bildrender.

namespace WLMonitor\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class WeatherSunTimesTest extends TestCase
{
    private function vienna(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('Europe/Vienna'));
    }

    public function test_summer_day_in_vienna(): void
    {
        // Referenz: 22.08.2026 in Wien, Sonnenaufgang ~05:58, Untergang ~19:56.
        $sun = weather_sun_times($this->vienna('2026-08-22 12:00'));

        $this->assertTrue($sun['available']);
        $this->assertSame('05:58', $sun['sunrise']->format('H:i'));
        $this->assertSame('19:56', $sun['sunset']->format('H:i'));
    }

    public function test_winter_day_is_much_shorter(): void
    {
        $summer = weather_sun_times($this->vienna('2026-06-21 12:00'));
        $winter = weather_sun_times($this->vienna('2026-12-21 12:00'));

        $summerLength = $summer['sunset']->getTimestamp() - $summer['sunrise']->getTimestamp();
        $winterLength = $winter['sunset']->getTimestamp() - $winter['sunrise']->getTimestamp();

        $this->assertGreaterThan($winterLength + 7 * 3600, $summerLength, 'Sommer- und Wintertag muessen sich deutlich unterscheiden');
    }

    public function test_times_come_back_in_the_timezone_of_the_given_day(): void
    {
        // Sonst stuende auf dem Board UTC statt Ortszeit -- im Sommer zwei
        // Stunden daneben, ohne dass irgendetwas offensichtlich kaputt waere.
        $sun = weather_sun_times($this->vienna('2026-08-22 12:00'));

        $this->assertSame('Europe/Vienna', $sun['sunrise']->getTimezone()->getName());
        $this->assertSame('Europe/Vienna', $sun['sunset']->getTimezone()->getName());
    }

    public function test_sunrise_is_before_sunset(): void
    {
        $sun = weather_sun_times($this->vienna('2026-03-29 12:00')); // Tag der Zeitumstellung

        $this->assertTrue($sun['available']);
        $this->assertLessThan($sun['sunset']->getTimestamp(), $sun['sunrise']->getTimestamp());
    }
}
