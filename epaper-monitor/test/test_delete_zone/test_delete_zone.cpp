#include <unity.h>
#include <cstdio>
#include <cstring>
#include "delete_zone.h"

// Loesch-X der Nachrichtenkarten (TASK-28). Der Header kommt vom Server
// (board_mqtt_delete_zones_header()), wird aber wie jede Netzwerkeingabe
// misstrauisch behandelt: nichts darf einen festen Puffer sprengen.

static DeleteZone zonen[MAX_DELETE_ZONES];

// --- Zerlegen --------------------------------------------------------------

void test_parses_a_single_zone(void) {
    const int n = parseDeleteZones("7ba3e9a6:532,148,72,72", zonen, MAX_DELETE_ZONES);

    TEST_ASSERT_EQUAL_INT(1, n);
    TEST_ASSERT_EQUAL_STRING("7ba3e9a6", zonen[0].id);
    TEST_ASSERT_EQUAL_INT(532, zonen[0].x);
    TEST_ASSERT_EQUAL_INT(148, zonen[0].y);
    TEST_ASSERT_EQUAL_INT(72,  zonen[0].w);
    TEST_ASSERT_EQUAL_INT(72,  zonen[0].h);
}

void test_parses_several_zones(void) {
    const int n = parseDeleteZones(
        "aaaaaaaa:16,148,72,72;bbbbbbbb:643,148,72,72;cccccccc:1270,500,72,72",
        zonen, MAX_DELETE_ZONES);

    TEST_ASSERT_EQUAL_INT(3, n);
    TEST_ASSERT_EQUAL_STRING("cccccccc", zonen[2].id);
    TEST_ASSERT_EQUAL_INT(1270, zonen[2].x);
    TEST_ASSERT_EQUAL_INT(500,  zonen[2].y);
}

void test_empty_and_null_yield_nothing(void) {
    TEST_ASSERT_EQUAL_INT(0, parseDeleteZones("", zonen, MAX_DELETE_ZONES));
    TEST_ASSERT_EQUAL_INT(0, parseDeleteZones(nullptr, zonen, MAX_DELETE_ZONES));
}

// --- Robustheit gegen kaputte Header ---------------------------------------

void test_a_broken_entry_does_not_discard_the_healthy_ones(void) {
    // Ein einzelner Tippfehler soll nicht saemtliche Loesch-X unbrauchbar
    // machen -- der kaputte Eintrag faellt weg, der Rest bleibt.
    const int n = parseDeleteZones(
        "aaaaaaaa:16,148,72,72;kaputt;bbbbbbbb:643,148,72,72",
        zonen, MAX_DELETE_ZONES);

    TEST_ASSERT_EQUAL_INT(2, n);
    TEST_ASSERT_EQUAL_STRING("aaaaaaaa", zonen[0].id);
    TEST_ASSERT_EQUAL_STRING("bbbbbbbb", zonen[1].id);
}

void test_incomplete_coordinates_are_rejected(void) {
    TEST_ASSERT_EQUAL_INT(0, parseDeleteZones("aaaaaaaa:16,148,72", zonen, MAX_DELETE_ZONES));
    TEST_ASSERT_EQUAL_INT(0, parseDeleteZones("aaaaaaaa:", zonen, MAX_DELETE_ZONES));
    TEST_ASSERT_EQUAL_INT(0, parseDeleteZones("aaaaaaaa", zonen, MAX_DELETE_ZONES));
    TEST_ASSERT_EQUAL_INT(0, parseDeleteZones("aaaaaaaa:x,y,w,h", zonen, MAX_DELETE_ZONES));
}

void test_zero_sized_zones_are_rejected(void) {
    // Koennte nie getroffen werden und wuerde nur einen Platz belegen.
    TEST_ASSERT_EQUAL_INT(0, parseDeleteZones("aaaaaaaa:16,148,0,72", zonen, MAX_DELETE_ZONES));
    TEST_ASSERT_EQUAL_INT(0, parseDeleteZones("aaaaaaaa:16,148,72,0", zonen, MAX_DELETE_ZONES));
}

void test_overlong_id_is_rejected_not_truncated(void) {
    // Abschneiden waere schlimmer als verwerfen: eine gekuerzte Kennung
    // loescht entweder nichts oder die FALSCHE Nachricht.
    char lang[64];
    memset(lang, 'a', sizeof(lang));
    lang[MAX_DELETE_ID_LEN + 1] = '\0';
    char header[128];
    snprintf(header, sizeof(header), "%s:16,148,72,72", lang);

    TEST_ASSERT_EQUAL_INT(0, parseDeleteZones(header, zonen, MAX_DELETE_ZONES));
}

void test_more_zones_than_capacity_are_dropped_not_overflowed(void) {
    // 30 Eintraege bei 20 Plaetzen -- der Puffer darf nicht ueberlaufen.
    char header[2048] = {0};
    for (int i = 0; i < 30; ++i) {
        char eintrag[64];
        snprintf(eintrag, sizeof(eintrag), "%s%08d:16,148,72,72", i == 0 ? "" : ";", i);
        strncat(header, eintrag, sizeof(header) - strlen(header) - 1);
    }

    const int n = parseDeleteZones(header, zonen, MAX_DELETE_ZONES);

    TEST_ASSERT_EQUAL_INT(MAX_DELETE_ZONES, n);
    TEST_ASSERT_EQUAL_STRING("00000000", zonen[0].id);
    TEST_ASSERT_EQUAL_STRING("00000019", zonen[MAX_DELETE_ZONES - 1].id);
}

void test_capacity_argument_is_respected(void) {
    const int n = parseDeleteZones("aaaaaaaa:16,148,72,72;bbbbbbbb:643,148,72,72", zonen, 1);
    TEST_ASSERT_EQUAL_INT(1, n);
}

// --- Treffer ---------------------------------------------------------------

void test_hit_inside_the_zone(void) {
    const int n = parseDeleteZones("aaaaaaaa:100,200,72,72", zonen, MAX_DELETE_ZONES);

    TEST_ASSERT_EQUAL_INT(0, findDeleteZone(zonen, n, 100, 200));  // linke obere Ecke, inklusive
    TEST_ASSERT_EQUAL_INT(0, findDeleteZone(zonen, n, 171, 271));  // rechte untere Ecke, noch drin
    TEST_ASSERT_EQUAL_INT(0, findDeleteZone(zonen, n, 136, 236));  // Mitte
}

void test_miss_next_to_the_zone(void) {
    const int n = parseDeleteZones("aaaaaaaa:100,200,72,72", zonen, MAX_DELETE_ZONES);

    TEST_ASSERT_EQUAL_INT(-1, findDeleteZone(zonen, n, 99,  236)); // eins links
    TEST_ASSERT_EQUAL_INT(-1, findDeleteZone(zonen, n, 172, 236)); // eins rechts (exklusiv)
    TEST_ASSERT_EQUAL_INT(-1, findDeleteZone(zonen, n, 136, 199)); // eins darueber
    TEST_ASSERT_EQUAL_INT(-1, findDeleteZone(zonen, n, 136, 272)); // eins darunter
}

void test_finds_the_right_zone_among_several(void) {
    const int n = parseDeleteZones(
        "aaaaaaaa:16,148,72,72;bbbbbbbb:643,148,72,72;cccccccc:1270,148,72,72",
        zonen, MAX_DELETE_ZONES);

    TEST_ASSERT_EQUAL_INT(1, findDeleteZone(zonen, n, 660, 160));
    TEST_ASSERT_EQUAL_STRING("bbbbbbbb", zonen[findDeleteZone(zonen, n, 660, 160)].id);
}

void test_no_zones_means_no_hit(void) {
    TEST_ASSERT_EQUAL_INT(-1, findDeleteZone(zonen, 0, 100, 200));
    TEST_ASSERT_EQUAL_INT(-1, findDeleteZone(nullptr, 3, 100, 200));
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_parses_a_single_zone);
    RUN_TEST(test_parses_several_zones);
    RUN_TEST(test_empty_and_null_yield_nothing);
    RUN_TEST(test_a_broken_entry_does_not_discard_the_healthy_ones);
    RUN_TEST(test_incomplete_coordinates_are_rejected);
    RUN_TEST(test_zero_sized_zones_are_rejected);
    RUN_TEST(test_overlong_id_is_rejected_not_truncated);
    RUN_TEST(test_more_zones_than_capacity_are_dropped_not_overflowed);
    RUN_TEST(test_capacity_argument_is_respected);
    RUN_TEST(test_hit_inside_the_zone);
    RUN_TEST(test_miss_next_to_the_zone);
    RUN_TEST(test_finds_the_right_zone_among_several);
    RUN_TEST(test_no_zones_means_no_hit);
    return UNITY_END();
}
