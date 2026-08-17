<?php
// tests/Unit/BoardStateTest.php
//
// Per-device state persistence (Spec §4 Schritt 3/6) -- zwei Dateien pro
// Geraet (Meta-JSON + roher letzter Frame), Schluessel = SHA-256(Token).
// Pattern A (flock read-modify-write), analog zu RATE_LIMIT_FILE in
// vendor/erikr/auth/src/auth.php.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardStateTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $p) {
            @unlink($p);
        }
        $this->cleanupPaths = [];
    }

    public function test_hash_is_deterministic_sha256(): void
    {
        $this->assertSame(hash('sha256', 'abc'), board_state_hash('abc'));
    }

    public function test_meta_path_and_frame_path_share_the_same_hash_stem(): void
    {
        $hash = board_state_hash('sometoken');
        $this->assertSame(board_state_dir() . "/$hash.json", board_state_meta_path($hash));
        $this->assertSame(board_state_dir() . "/$hash.frame", board_state_frame_path($hash));
    }

    public function test_load_meta_returns_defaults_when_file_is_missing(): void
    {
        $path = board_state_dir() . '/does-not-exist-' . uniqid() . '.json';
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 1, 'etag' => null, 'fullRefreshAt' => null],
            board_state_load_meta($path)
        );
    }

    public function test_save_then_load_meta_roundtrips(): void
    {
        $path = board_state_dir() . '/test-' . uniqid() . '.json';
        $this->cleanupPaths[] = $path;

        $meta = ['activeFavoriteIndex' => 2, 'activePage' => 3, 'etag' => '"abc"', 'fullRefreshAt' => 1700000000];
        board_state_save_meta($path, $meta);

        $this->assertSame($meta, board_state_load_meta($path));
    }

    public function test_load_frame_returns_null_when_file_is_missing(): void
    {
        $path = board_state_dir() . '/does-not-exist-' . uniqid() . '.frame';
        $this->assertNull(board_state_load_frame($path));
    }

    public function test_save_then_load_frame_roundtrips_binary_data(): void
    {
        $path = board_state_dir() . '/test-' . uniqid() . '.frame';
        $this->cleanupPaths[] = $path;

        $packed = "\x00\xFF\x0F" . str_repeat("\xAA", 100);
        board_state_save_frame($path, $packed);

        $this->assertSame($packed, board_state_load_frame($path));
    }

    // --- Touch-Aufloesung (Spec §4 Schritt 3) ---------------------------------

    public function test_no_touch_keeps_existing_favorite_and_page(): void
    {
        $meta = ['activeFavoriteIndex' => 1, 'activePage' => 2];
        $this->assertSame(
            ['activeFavoriteIndex' => 1, 'activePage' => 2],
            board_resolve_touch($meta, null, 3)
        );
    }

    public function test_fav_touch_switches_favorite_and_resets_page_to_one(): void
    {
        $meta = ['activeFavoriteIndex' => 0, 'activePage' => 3];
        $this->assertSame(
            ['activeFavoriteIndex' => 2, 'activePage' => 1],
            board_resolve_touch($meta, 'fav2', 3)
        );
    }

    public function test_fav_touch_beyond_available_favorite_count_is_ignored(): void
    {
        $meta = ['activeFavoriteIndex' => 0, 'activePage' => 1];
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 1],
            board_resolve_touch($meta, 'fav2', 2) // nur 2 Favoriten (Index 0,1) -- fav2 gibt es nicht
        );
    }

    public function test_page_prev_decrements_but_not_below_one(): void
    {
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 1],
            board_resolve_touch(['activeFavoriteIndex' => 0, 'activePage' => 1], 'page_prev', 3)
        );
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 1],
            board_resolve_touch(['activeFavoriteIndex' => 0, 'activePage' => 2], 'page_prev', 3)
        );
    }

    public function test_page_next_increments_unclamped(): void
    {
        // Kein oberes Clamping hier -- der Aufrufer (web/board.php) klemmt
        // gegen die tatsaechliche, datenabhaengige Seitenzahl.
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 4],
            board_resolve_touch(['activeFavoriteIndex' => 0, 'activePage' => 3], 'page_next', 3)
        );
    }

    public function test_zero_favorites_forces_index_zero_regardless_of_stored_state(): void
    {
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 1],
            board_resolve_touch(['activeFavoriteIndex' => 2, 'activePage' => 1], null, 0)
        );
    }

    public function test_unknown_touch_value_is_a_no_op(): void
    {
        $meta = ['activeFavoriteIndex' => 1, 'activePage' => 2];
        $this->assertSame(
            ['activeFavoriteIndex' => 1, 'activePage' => 2],
            board_resolve_touch($meta, 'garbage', 3)
        );
    }
}
