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

    // --- page_<N>: absolute Touch-Pille (TASK-25, Nutzerwunsch 2026-08-27:
    // "Vor/zurueck ist ein Anachronismus" -- nur die kapazitive Pille, NICHT
    // die physischen Tasten, s. page_prev/page_next-Tests oben) --------------

    public function test_page_n_jumps_directly_to_that_page(): void
    {
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 3],
            board_resolve_touch(['activeFavoriteIndex' => 0, 'activePage' => 1], 'page_3', 3)
        );
    }

    public function test_page_n_does_not_touch_the_active_favorite(): void
    {
        $this->assertSame(
            ['activeFavoriteIndex' => 2, 'activePage' => 5],
            board_resolve_touch(['activeFavoriteIndex' => 2, 'activePage' => 1], 'page_5', 3)
        );
    }

    public function test_page_0_clamps_to_one_instead_of_a_bogus_zero(): void
    {
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 1],
            board_resolve_touch(['activeFavoriteIndex' => 0, 'activePage' => 4], 'page_0', 3)
        );
    }

    public function test_page_n_has_no_upper_clamp_here_either(): void
    {
        // Wie page_next: der Aufrufer klemmt gegen die tatsaechliche
        // Seitenzahl -- eine veraltete Simulator-/Geraete-Zone darf hier
        // nicht ins Leere greifen, sondern landet sicher auf der letzten Seite.
        $this->assertSame(
            ['activeFavoriteIndex' => 0, 'activePage' => 99],
            board_resolve_touch(['activeFavoriteIndex' => 0, 'activePage' => 1], 'page_99', 3)
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

    // --- Geraete-Screenshot-Debug-Feature (2026-08-22, web/board_snapshot.php) ---

    public function test_snapshot_not_requested_by_default(): void
    {
        $hash = 'test-snap-' . uniqid();
        $this->assertFalse(board_state_snapshot_requested($hash));
    }

    public function test_request_snapshot_sets_the_flag(): void
    {
        $hash = 'test-snap-' . uniqid();
        $this->cleanupPaths[] = board_state_snapshot_request_path($hash);

        board_state_request_snapshot($hash);

        $this->assertTrue(board_state_snapshot_requested($hash));
    }

    public function test_clear_snapshot_request_unsets_the_flag(): void
    {
        $hash = 'test-snap-' . uniqid();
        $this->cleanupPaths[] = board_state_snapshot_request_path($hash);

        board_state_request_snapshot($hash);
        board_state_clear_snapshot_request($hash);

        $this->assertFalse(board_state_snapshot_requested($hash));
    }

    public function test_clear_snapshot_request_is_a_no_op_when_nothing_was_requested(): void
    {
        $hash = 'test-snap-' . uniqid();
        board_state_clear_snapshot_request($hash); // darf nicht werfen
        $this->assertFalse(board_state_snapshot_requested($hash));
    }

    public function test_load_snapshot_returns_null_when_nothing_was_uploaded(): void
    {
        $hash = 'test-snap-' . uniqid();
        $this->assertNull(board_state_load_snapshot($hash));
    }

    public function test_save_then_load_snapshot_roundtrips_binary_data(): void
    {
        $hash = 'test-snap-' . uniqid();
        $this->cleanupPaths[] = board_state_snapshot_path($hash);

        $packed = "\x00\xFF\x0F" . str_repeat("\xAA", 100);
        board_state_save_snapshot($hash, $packed);

        $this->assertSame($packed, board_state_load_snapshot($hash));
    }
}
