<?php
// tests/Integration/BoardMqttDeleteTest.php
//
// Loeschen einer MQTT-Karte (TASK-26, Nutzerwunsch 2026-08-29: "auf den
// einzelnen Karten ein lösch-x"). Anders als der Rest von board_mqtt.php
// fasst board_mqtt_delete() eine DATEI an -- deshalb Integration statt Unit.

namespace WLMonitor\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class BoardMqttDeleteTest extends TestCase
{
    private string $pfad;
    private ?string $sicherung = null;

    protected function setUp(): void
    {
        $this->pfad = board_mqtt_path();
        // Einen echten lokalen Cache nicht zerstoeren.
        if (is_file($this->pfad)) {
            $this->sicherung = (string) file_get_contents($this->pfad);
        }
        if (!is_dir(dirname($this->pfad))) {
            mkdir(dirname($this->pfad), 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if ($this->sicherung !== null) {
            file_put_contents($this->pfad, $this->sicherung);
        } else {
            @unlink($this->pfad);
        }
        @unlink($this->pfad . '.tmp');
    }

    /** @param list<array{payload: string, received_at: string}> $messages */
    private function schreibeCache(array $messages): void
    {
        file_put_contents($this->pfad, json_encode(['schema' => 1, 'messages' => $messages]));
    }

    private function leseCache(): array
    {
        return json_decode((string) file_get_contents($this->pfad), true)['messages'];
    }

    private function nachricht(string $titel, string $zeit): array
    {
        return [
            'topic' => 'wlmonitor/board/message',
            'payload' => json_encode(['title' => $titel, 'body' => 'x']),
            'received_at' => $zeit,
        ];
    }

    public function test_delete_removes_exactly_the_addressed_message(): void
    {
        $a = $this->nachricht('A', '2026-08-29T08:00:00+02:00');
        $b = $this->nachricht('B', '2026-08-29T08:01:00+02:00');
        $c = $this->nachricht('C', '2026-08-29T08:02:00+02:00');
        $this->schreibeCache([$a, $b, $c]);

        $this->assertTrue(board_mqtt_delete(board_mqtt_message_id($b)));

        $uebrig = $this->leseCache();
        $this->assertCount(2, $uebrig);
        $titel = array_map(static fn (array $m): string => json_decode($m['payload'], true)['title'], $uebrig);
        $this->assertSame(['A', 'C'], $titel, 'nur B darf verschwinden, Reihenfolge bleibt');
    }

    public function test_delete_with_unknown_id_changes_nothing(): void
    {
        $a = $this->nachricht('A', '2026-08-29T08:00:00+02:00');
        $this->schreibeCache([$a]);

        $this->assertFalse(board_mqtt_delete('deadbeef'));
        $this->assertCount(1, $this->leseCache());
    }

    public function test_delete_on_missing_cache_file_is_a_no_op_not_an_error(): void
    {
        @unlink($this->pfad);
        $this->assertFalse(board_mqtt_delete('irgendwas'));
    }

    public function test_delete_on_garbage_cache_is_a_no_op_not_an_error(): void
    {
        file_put_contents($this->pfad, 'kein JSON');
        $this->assertFalse(board_mqtt_delete('irgendwas'));
    }

    public function test_deleted_message_is_gone_from_the_rendered_page(): void
    {
        // Ende-zu-Ende: loeschen, neu einlesen, rendern -- der Text darf nicht
        // mehr im SVG stehen.
        $a = $this->nachricht('Bleibt', '2026-08-29T08:00:00+02:00');
        $b = $this->nachricht('Verschwindet', '2026-08-29T08:01:00+02:00');
        $this->schreibeCache([$a, $b]);

        board_mqtt_delete(board_mqtt_message_id($b));

        $anzeige = board_mqtt_select_display(board_mqtt_load(), new \DateTimeImmutable());
        $svg = board_mqtt_render_svg(board_mqtt_layout($anzeige));

        $this->assertStringContainsString('Bleibt', $svg);
        $this->assertStringNotContainsString('Verschwindet', $svg);
    }

    public function test_no_tmp_file_is_left_behind(): void
    {
        $a = $this->nachricht('A', '2026-08-29T08:00:00+02:00');
        $this->schreibeCache([$a]);
        board_mqtt_delete(board_mqtt_message_id($a));

        $this->assertFileDoesNotExist($this->pfad . '.tmp', 'atomares Schreiben darf keine Reste hinterlassen');
    }
}
