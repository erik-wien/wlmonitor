<?php
// tests/Integration/FavoritesTest.php

namespace WLMonitor\Tests\Integration;

class FavoritesTest extends IntegrationTestCase
{
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId      = $this->createUser();
        $this->otherUserId = $this->createUser();
        $_SESSION['id']    = $this->userId;
    }

    // --- favorites_add -------------------------------------------------------

    public function test_add_returns_positive_id(): void
    {
        $id = favorites_add($this->con, $this->userId, 'Karlsplatz', '60200103', 'btn-outline-color-green', 1);
        $this->assertGreaterThan(0, $id);
    }

    public function test_add_persists_record(): void
    {
        $id = favorites_add($this->con, $this->userId, 'Schwedenplatz', '60200105,60200106', 'btn-outline-color-red', 2);

        $rows = favorites_get($this->con, $this->userId);
        $found = array_filter($rows, fn($r) => $r['id'] === $id);
        $this->assertCount(1, $found);

        $fav = array_values($found)[0];
        $this->assertSame('Schwedenplatz', $fav['title']);
        $this->assertSame('60200105,60200106', $fav['diva']);
        $this->assertSame('btn-outline-color-red', $fav['bclass']);
        $this->assertSame(2, $fav['sort']);
    }

    public function test_add_strips_html_tags_from_title(): void
    {
        $id = favorites_add($this->con, $this->userId, '<b>Bold</b> Station', '123', 'btn-outline-color-green', 1);

        $rows  = favorites_get($this->con, $this->userId);
        $found = array_values(array_filter($rows, fn($r) => $r['id'] === $id));
        $this->assertSame('Bold Station', $found[0]['title']);
    }

    public function test_add_truncates_title_at_100_chars(): void
    {
        $long = str_repeat('A', 150);
        $id   = favorites_add($this->con, $this->userId, $long, '123', 'btn-outline-color-green', 1);

        $rows  = favorites_get($this->con, $this->userId);
        $found = array_values(array_filter($rows, fn($r) => $r['id'] === $id));
        $this->assertSame(100, mb_strlen($found[0]['title']));
    }

    public function test_add_sanitizes_diva(): void
    {
        $id = favorites_add($this->con, $this->userId, 'Test', 'abc123,456def', 'btn-outline-color-green', 1);

        $rows  = favorites_get($this->con, $this->userId);
        $found = array_values(array_filter($rows, fn($r) => $r['id'] === $id));
        $this->assertSame('123,456', $found[0]['diva']);
    }

    public function test_add_sanitizes_bclass(): void
    {
        $id = favorites_add($this->con, $this->userId, 'Test', '123', 'btn-outline-color-green"; DROP TABLE wl_favorites--', 1);

        $rows  = favorites_get($this->con, $this->userId);
        $found = array_values(array_filter($rows, fn($r) => $r['id'] === $id));
        // Only a-z, 0-9, and hyphen are allowed
        $this->assertMatchesRegularExpression('/^[a-z0-9\-]+$/', $found[0]['bclass']);
    }

    // --- favorites_get -------------------------------------------------------

    public function test_get_returns_only_own_favorites(): void
    {
        favorites_add($this->con, $this->userId,      'Mine',   '111', 'btn-outline-color-green', 1);
        favorites_add($this->con, $this->otherUserId, 'Others', '222', 'btn-outline-color-green', 1);

        $rows = favorites_get($this->con, $this->userId);
        foreach ($rows as $row) {
            $this->assertNotSame('Others', $row['title']);
        }
    }

    public function test_get_returns_sorted_by_sort_then_id(): void
    {
        favorites_add($this->con, $this->userId, 'C', '3', 'btn-outline-color-green', 3);
        favorites_add($this->con, $this->userId, 'A', '1', 'btn-outline-color-green', 1);
        favorites_add($this->con, $this->userId, 'B', '2', 'btn-outline-color-green', 2);

        $rows = favorites_get($this->con, $this->userId);
        $this->assertSame('A', $rows[0]['title']);
        $this->assertSame('B', $rows[1]['title']);
        $this->assertSame('C', $rows[2]['title']);
    }

    // --- favorites_check -----------------------------------------------------

    public function test_check_returns_true_when_diva_exists(): void
    {
        favorites_add($this->con, $this->userId, 'Test', '60200103', 'btn-outline-color-green', 1);
        $this->assertTrue(favorites_check($this->con, $this->userId, '60200103'));
    }

    public function test_check_returns_false_when_diva_absent(): void
    {
        $this->assertFalse(favorites_check($this->con, $this->userId, '99999999'));
    }

    public function test_check_is_user_scoped(): void
    {
        favorites_add($this->con, $this->otherUserId, 'Other', '60200103', 'btn-outline-color-green', 1);
        $this->assertFalse(favorites_check($this->con, $this->userId, '60200103'));
    }

    // --- favorites_edit ------------------------------------------------------

    public function test_edit_updates_fields(): void
    {
        $id = favorites_add($this->con, $this->userId, 'Old', '111', 'btn-outline-color-green', 1);

        $ok   = favorites_edit($this->con, $this->userId, $id, 'New', '222', 'btn-outline-color-red', 5);
        $rows = favorites_get($this->con, $this->userId);
        $fav  = array_values(array_filter($rows, fn($r) => $r['id'] === $id))[0];

        $this->assertTrue($ok);
        $this->assertSame('New', $fav['title']);
        $this->assertSame('222', $fav['diva']);
        $this->assertSame('btn-outline-color-red', $fav['bclass']);
        $this->assertSame(5, $fav['sort']);
    }

    public function test_edit_cannot_update_another_users_favorite(): void
    {
        $id = favorites_add($this->con, $this->otherUserId, 'Theirs', '111', 'btn-outline-color-green', 1);

        $ok = favorites_edit($this->con, $this->userId, $id, 'Stolen', '999', 'btn-outline-color-red', 1);

        $this->assertFalse($ok);
    }

    // --- favorites_delete ----------------------------------------------------

    public function test_delete_removes_record(): void
    {
        $id = favorites_add($this->con, $this->userId, 'Delete Me', '123', 'btn-outline-color-green', 1);

        $ok   = favorites_delete($this->con, $this->userId, $id);
        $rows = favorites_get($this->con, $this->userId);
        $ids  = array_column($rows, 'id');

        $this->assertTrue($ok);
        $this->assertNotContains($id, $ids);
    }

    public function test_delete_cannot_remove_another_users_favorite(): void
    {
        $id = favorites_add($this->con, $this->otherUserId, 'Theirs', '123', 'btn-outline-color-green', 1);

        $ok = favorites_delete($this->con, $this->userId, $id);

        $this->assertFalse($ok);
        // Verify it still exists for the real owner
        $rows = favorites_get($this->con, $this->otherUserId);
        $this->assertNotEmpty(array_filter($rows, fn($r) => $r['id'] === $id));
    }

    // --- favorites_save_sort -------------------------------------------------

    public function test_save_sort_updates_order(): void
    {
        $id1 = favorites_add($this->con, $this->userId, 'First',  '1', 'btn-outline-color-green', 1);
        $id2 = favorites_add($this->con, $this->userId, 'Second', '2', 'btn-outline-color-green', 2);

        favorites_save_sort($this->con, $this->userId, [
            ['id' => $id1, 'sort' => 10],
            ['id' => $id2, 'sort' => 5],
        ]);

        $rows   = favorites_get($this->con, $this->userId);
        $byId   = array_column($rows, null, 'id');
        $this->assertSame(10, $byId[$id1]['sort']);
        $this->assertSame(5,  $byId[$id2]['sort']);
    }

    public function test_save_sort_ignores_malformed_items(): void
    {
        $id = favorites_add($this->con, $this->userId, 'Test', '1', 'btn-outline-color-green', 3);

        // Should not throw
        favorites_save_sort($this->con, $this->userId, [
            ['id' => $id],           // missing 'sort'
            ['sort' => 1],           // missing 'id'
            [],                      // both missing
        ]);

        $rows = favorites_get($this->con, $this->userId);
        $fav  = array_values(array_filter($rows, fn($r) => $r['id'] === $id))[0];
        $this->assertSame(3, $fav['sort']); // unchanged
    }
}
