<?php
// tests/Unit/BoardCompressionTest.php
//
// Rumpf-Kompression des Bild-Protokolls (board_compress_body(), 2026-08-22).
// Hintergrund: am Geraet gemessen dauerte der Download der 328.536 B eines
// Vollbilds ~1470 ms und war damit der groesste Posten im Zyklus.
// Siehe docs/hardware/reterminal-e1003.md §20.8.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardCompressionTest extends TestCase
{
    /** Realistischer Rumpf: 1bpp-Bilddaten sind grosse weisse Flaechen. */
    private function frameLike(): string
    {
        return str_repeat("\xFF", 300000) . str_repeat("\x00\xFF\x0F", 9512);
    }

    public function test_compressed_body_round_trips_through_raw_inflate(): void
    {
        // Entscheidend: ROHES Deflate ohne zlib-/gzip-Rahmen -- nur das nimmt
        // tinfl_decompress_mem_to_mem(..., flags=0) im ESP32-S3-ROM entgegen.
        $body = $this->frameLike();

        $result = board_compress_body($body, true);

        $this->assertSame('deflate', $result['encoding']);
        $this->assertSame(strlen($body), $result['rawLength']);
        $this->assertSame($body, gzinflate($result['body']), 'muss sich mit rohem Inflate zurueckholen lassen');
    }

    public function test_compression_actually_pays_off_on_frame_like_data(): void
    {
        $body = $this->frameLike();

        $result = board_compress_body($body, true);

        $this->assertLessThan(
            strlen($body) * 0.25,
            strlen($result['body']),
            'Bilddaten muessen deutlich schrumpfen, sonst lohnt der ganze Weg nicht'
        );
    }

    public function test_body_stays_plain_when_the_device_did_not_announce_support(): void
    {
        // Aeltere Firmware oder Browser-Aufruf: der Rumpf muss unveraendert
        // rausgehen, sonst sieht das Geraet Datensalat statt Pixeln.
        $body = $this->frameLike();

        $result = board_compress_body($body, false);

        $this->assertNull($result['encoding']);
        $this->assertSame($body, $result['body']);
        $this->assertSame(strlen($body), $result['rawLength']);
    }

    public function test_small_bodies_are_left_alone(): void
    {
        $result = board_compress_body('winzig', true);

        $this->assertNull($result['encoding']);
        $this->assertSame('winzig', $result['body']);
    }

    public function test_incompressible_body_is_sent_plain(): void
    {
        // Zufallsdaten werden durch Deflate groesser -- dann muss der
        // unkomprimierte Weg gewinnen, sonst waere die "Optimierung" negativ.
        $body = random_bytes(4096);

        $result = board_compress_body($body, true);

        $this->assertNull($result['encoding']);
        $this->assertSame($body, $result['body']);
    }

    public function test_device_accept_header_parsing(): void
    {
        $this->assertTrue(board_device_accepts_deflate('deflate'));
        $this->assertFalse(board_device_accepts_deflate(null), 'fehlender Header = aeltere Firmware');
        $this->assertFalse(board_device_accepts_deflate(''));
        $this->assertFalse(board_device_accepts_deflate('gzip'));
    }
}
