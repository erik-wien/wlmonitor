<?php
namespace WLMonitor\Tests\Integration;

class StatePersistenceTest extends IntegrationTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../inc/state.php';
        require_once __DIR__ . '/../../inc/favorites.php';
        $this->userId   = $this->createUser();
        $_SESSION['id'] = $this->userId;
    }

    public function test_load_returns_nulls_for_fresh_user(): void
    {
        $state = state_load($this->con, $this->userId);
        $this->assertNull($state['last_fav_id']);
        $this->assertNull($state['last_diva']);
    }

    public function test_upsert_saves_diva(): void
    {
        state_upsert($this->con, $this->userId, null, '60200103');
        $state = state_load($this->con, $this->userId);
        $this->assertSame('60200103', $state['last_diva']);
        $this->assertNull($state['last_fav_id']);
    }

    public function test_upsert_saves_fav_id(): void
    {
        $favId = favorites_add($this->con, $this->userId, 'Karlsplatz', '60200103', 'btn-outline-success', 1);
        state_upsert($this->con, $this->userId, $favId, '60200103');
        $state = state_load($this->con, $this->userId);
        $this->assertSame($favId, $state['last_fav_id']);
        $this->assertSame('60200103', $state['last_diva']);
    }

    public function test_fk_nulls_last_fav_id_on_favorite_delete(): void
    {
        $favId = favorites_add($this->con, $this->userId, 'Karlsplatz', '60200103', 'btn-outline-success', 1);
        state_upsert($this->con, $this->userId, $favId, '60200103');
        favorites_delete($this->con, $this->userId, $favId);
        $state = state_load($this->con, $this->userId);
        $this->assertNull($state['last_fav_id']);
        $this->assertSame('60200103', $state['last_diva']); // diva survives
    }

    public function test_upsert_overwrites_existing(): void
    {
        state_upsert($this->con, $this->userId, null, '60200103');
        state_upsert($this->con, $this->userId, null, '60200456');
        $state = state_load($this->con, $this->userId);
        $this->assertSame('60200456', $state['last_diva']);
    }
}
