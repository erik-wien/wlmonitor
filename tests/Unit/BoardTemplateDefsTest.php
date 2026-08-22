<?php
// tests/Unit/BoardTemplateDefsTest.php
//
// SVG-Grundformen (Badges, Wetter-Icons) aus Spec §9. Reiner String-Vergleich
// gegen die in der Spec festgeschriebenen IDs -- kein Rendering hier (das
// prueft Task 7 ueber die echte Pipeline).

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BoardTemplateDefsTest extends TestCase
{
    private const EXPECTED_IDS = [
        'icon_klar', 'icon_leicht_bewoelkt', 'icon_bewoelkt', 'icon_bedeckt',
        'icon_regen_leicht', 'icon_regen_stark', 'icon_schnee', 'icon_gewitter',
        'icon_nebel', 'icon_unbekannt',
        'iconTemp', 'iconDroplet', 'iconWind', 'iconDroplets',
        'badgeMetro', 'badgeTram', 'badgeBus', 'badgeTrain',
        'starNow',
    ];

    public function test_defs_contain_every_expected_id_exactly_once(): void
    {
        $defs = board_svg_defs();

        foreach (self::EXPECTED_IDS as $id) {
            $count = substr_count($defs, 'id="' . $id . '"');
            $this->assertSame(1, $count, "id=\"$id\" muss genau einmal vorkommen (gefunden: $count)");
        }
    }

    public function test_defs_is_well_formed_xml_fragment(): void
    {
        $wrapped = '<svg xmlns="http://www.w3.org/2000/svg"><defs>' . board_svg_defs() . '</defs></svg>';
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($wrapped);
        $errors = libxml_get_errors();
        libxml_use_internal_errors($prev);

        $this->assertNotFalse($doc, 'board_svg_defs() muss valides XML liefern');
        $this->assertSame([], $errors);
    }

    public function test_badge_shape_mapping_covers_all_board_types(): void
    {
        $this->assertSame('badgeMetro', BOARD_BADGE_SHAPE_BY_TYPE['metro']);
        $this->assertSame('badgeTram', BOARD_BADGE_SHAPE_BY_TYPE['tram']);
        $this->assertSame('badgeBus', BOARD_BADGE_SHAPE_BY_TYPE['bus']);
        $this->assertSame('badgeTrain', BOARD_BADGE_SHAPE_BY_TYPE['train']);
        $this->assertSame('badgeTrain', BOARD_BADGE_SHAPE_BY_TYPE['other'], 'other faellt auf die train-Form zurueck (Spec Global Constraints)');
    }

    public function test_icon_id_mapping_covers_all_nine_categories_plus_fallback(): void
    {
        $expected = [
            'klar' => 'icon_klar', 'leicht_bewoelkt' => 'icon_leicht_bewoelkt',
            'bewoelkt' => 'icon_bewoelkt', 'bedeckt' => 'icon_bedeckt',
            'regen_leicht' => 'icon_regen_leicht', 'regen_stark' => 'icon_regen_stark',
            'schnee' => 'icon_schnee', 'gewitter' => 'icon_gewitter',
            'nebel' => 'icon_nebel', 'unbekannt' => 'icon_unbekannt',
        ];
        foreach ($expected as $category => $iconId) {
            $this->assertSame($iconId, BOARD_ICON_ID_BY_CATEGORY[$category]);
        }
    }

    public function test_badge_label_font_size_shrinks_for_three_char_labels(): void
    {
        $this->assertSame(26, board_badge_label_font_size('U6'));
        $this->assertSame(26, board_badge_label_font_size('18'));
        $this->assertSame(24, board_badge_label_font_size('WLB'));
    }

    public function test_badge_label_font_size_grows_30_percent_for_metro_and_tram(): void
    {
        $this->assertSame(34, board_badge_label_font_size('U6', 'metro'));
        $this->assertSame(34, board_badge_label_font_size('18', 'tram'));
        $this->assertSame(31, board_badge_label_font_size('WLB', 'metro'));
        $this->assertSame(26, board_badge_label_font_size('U6', 'bus'));
    }

    // --- board_read_weather_icon() (2026-08-22, Tabler-Icons statt handgezeichnet) --

    public function test_read_weather_icon_replaces_currentcolor_with_explicit_stroke(): void
    {
        $icon = board_read_weather_icon('sun.svg');

        $this->assertStringNotContainsString('currentColor', $icon);
        $this->assertStringContainsString('stroke="black"', $icon);
        $this->assertStringContainsString('translate(-12,-12)', $icon, 'auf lokalen Mittelpunkt zentriert');
    }

    public function test_read_weather_icon_leaves_self_colored_icon_unwrapped(): void
    {
        // cloud-sun.svg faerbt sich selbst (kein currentColor) -- keine
        // zusaetzliche fill/stroke-Umwicklung noetig.
        $icon = board_read_weather_icon('cloud-sun.svg');

        $this->assertStringNotContainsString('fill="none" stroke="black"', $icon);
    }

    public function test_read_weather_icon_throws_on_missing_file(): void
    {
        $this->expectException(\RuntimeException::class);
        board_read_weather_icon('does-not-exist.svg');
    }

    public function test_defs_embeds_all_weather_icon_files_without_error(): void
    {
        // Rauchtest: jede in BOARD_WEATHER_ICON_FILES referenzierte Datei
        // muss tatsaechlich existieren und lesbar sein.
        foreach (BOARD_WEATHER_ICON_FILES as $file) {
            $this->assertNotFalse(
                realpath(__DIR__ . '/../../assets/img/wetter/' . $file),
                "assets/img/wetter/$file nicht gefunden"
            );
        }
    }
}
