#include <unity.h>
#include "touch_zone.h"

// --- Favoriten-Zonen (y in [1320,1404)) ------------------------------------

void test_three_favorites_split_evenly(void) {
    // buttonWidth = (1872-32-32)/3 = 602
    // fav0: [16,618)  fav1: [634,1236)  fav2: [1252,1854)
    TEST_ASSERT_TRUE(mapTouchToZone(300, 1350, 3, 1).zone == TouchZone::Fav0);
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1350, 3, 1).zone == TouchZone::Fav1);
    TEST_ASSERT_TRUE(mapTouchToZone(1500, 1350, 3, 1).zone == TouchZone::Fav2);
}

void test_favorite_zone_boundaries_are_exact(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(16, 1320, 3, 1).zone == TouchZone::Fav0);   // top-left corner, inclusive
    TEST_ASSERT_TRUE(mapTouchToZone(617, 1403, 3, 1).zone == TouchZone::Fav0); // bottom-right, still inside (Bildschirmrand)
    TEST_ASSERT_TRUE(mapTouchToZone(618, 1350, 3, 1).zone == TouchZone::None); // gap between fav0/fav1
    TEST_ASSERT_TRUE(mapTouchToZone(634, 1350, 3, 1).zone == TouchZone::Fav1); // fav1 starts here
    TEST_ASSERT_TRUE(mapTouchToZone(300, 1404, 3, 1).zone == TouchZone::None); // one px below the row
    TEST_ASSERT_TRUE(mapTouchToZone(300, 1319, 3, 1).zone == TouchZone::None); // one px above the row
}

void test_single_favorite_spans_almost_the_whole_row(void) {
    // buttonWidth = (1872-32-0)/1 = 1840, fav0: [16,1856)
    TEST_ASSERT_TRUE(mapTouchToZone(16, 1350, 1, 1).zone == TouchZone::Fav0);
    TEST_ASSERT_TRUE(mapTouchToZone(1800, 1350, 1, 1).zone == TouchZone::Fav0);
    TEST_ASSERT_TRUE(mapTouchToZone(1860, 1350, 1, 1).zone == TouchZone::None);
}

void test_two_favorites_split_in_half(void) {
    // buttonWidth = (1872-32-16)/2 = 912, fav0: [16,928) fav1: [944,1856)
    TEST_ASSERT_TRUE(mapTouchToZone(500, 1350, 2, 1).zone == TouchZone::Fav0);
    TEST_ASSERT_TRUE(mapTouchToZone(1000, 1350, 2, 1).zone == TouchZone::Fav1);
    TEST_ASSERT_TRUE(mapTouchToZone(935, 1350, 2, 1).zone == TouchZone::None); // gap
}

void test_zero_favorites_means_no_favorite_zones_at_all(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(300, 1350, 0, 1).zone == TouchZone::None);
}

// --- Pagination-Zonen (y in [1252,1300)) -- rechtsbuendig an x=1083 --------
// Seit der Schlafschirm immer eine zusaetzliche letzte Seite ist (2026-08-23),
// ist totalPages praktisch nie mehr 1 -- die Pille waechst deshalb rechts-
// buendig ab x=1083 nach LINKS (pillWidth = totalPages*87 + 20), statt wie
// vor der Pfeil-Streichung linksbuendig ab einer festen Startposition.
//
// TASK-25 (Nutzerwunsch 2026-08-27: "Vor/zurueck ist ein Anachronismus",
// "vier Sprungziele, absolut nicht relativ"): jeder Slot springt direkt zu
// seiner eigenen 1-indizierten Seitenzahl (TouchZone::Page, TouchResult::page)
// statt links/rechts-Haelfte = vor/zurueck zu sein. Betrifft NUR die
// Touch-Pille -- die physischen Tasten (main.cpp) bleiben unveraendert
// relativ ("page_prev"/"page_next" als hartcodierte Strings, ausserhalb
// dieser Datei).

void test_pagination_zones_absent_when_only_one_page(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1280, 3, 1).zone == TouchZone::None);
}

void test_pagination_pill_two_pages_each_slot_is_its_own_absolute_page(void) {
    // totalPages=2: pillWidth=2*87+20=194, pillStartX=1083-194=889.
    // Slot 1 (Seite 1): [889,976)  Slot 2 (Seite 2): [976,1083) -- reicht bis
    // zum Pillenrand, schluckt den rechten Padding-Streifen (Klemmung).
    TouchResult before = mapTouchToZone(888, 1280, 3, 2);
    TEST_ASSERT_TRUE(before.zone == TouchZone::None);                          // vor der Pille

    TouchResult slot1Start = mapTouchToZone(889, 1280, 3, 2);
    TEST_ASSERT_TRUE(slot1Start.zone == TouchZone::Page);
    TEST_ASSERT_EQUAL_INT(1, slot1Start.page);                                 // Pillenanfang -> Seite 1

    TouchResult slot1End = mapTouchToZone(975, 1280, 3, 2);
    TEST_ASSERT_TRUE(slot1End.zone == TouchZone::Page);
    TEST_ASSERT_EQUAL_INT(1, slot1End.page);

    TouchResult slot2Start = mapTouchToZone(976, 1280, 3, 2);
    TEST_ASSERT_TRUE(slot2Start.zone == TouchZone::Page);
    TEST_ASSERT_EQUAL_INT(2, slot2Start.page);

    TouchResult slot2End = mapTouchToZone(1082, 1280, 3, 2);
    TEST_ASSERT_TRUE(slot2End.zone == TouchZone::Page);
    TEST_ASSERT_EQUAL_INT(2, slot2End.page);                                   // rechter Padding-Streifen -> auf Seite 2 geklemmt

    TouchResult after = mapTouchToZone(1083, 1280, 3, 2);
    TEST_ASSERT_TRUE(after.zone == TouchZone::None);                           // Pillenende, exklusiv
}

void test_pagination_pill_grows_leftward_and_slots_stay_87px(void) {
    // totalPages=5: pillWidth=5*87+20=455, pillStartX=1083-455=628.
    // Slots je 87px ab 628: [628,715)=1 [715,802)=2 [802,889)=3 [889,976)=4
    // [976,1083)=5 (letzter Slot bis zum Pillenrand).
    TouchResult before = mapTouchToZone(627, 1280, 3, 5);
    TEST_ASSERT_TRUE(before.zone == TouchZone::None);

    TEST_ASSERT_EQUAL_INT(1, mapTouchToZone(700, 1280, 3, 5).page);
    TEST_ASSERT_EQUAL_INT(3, mapTouchToZone(850, 1280, 3, 5).page);
    TEST_ASSERT_EQUAL_INT(5, mapTouchToZone(1082, 1280, 3, 5).page);

    TouchResult after = mapTouchToZone(1083, 1280, 3, 5);
    TEST_ASSERT_TRUE(after.zone == TouchZone::None);
}

void test_pagination_row_boundaries_are_exact(void) {
    // totalPages=2: Slot 1 [889,976). x=900 liegt darin.
    TouchResult top = mapTouchToZone(900, 1252, 3, 2);
    TEST_ASSERT_TRUE(top.zone == TouchZone::Page);
    TEST_ASSERT_EQUAL_INT(1, top.page); // top of row

    TouchResult bottom = mapTouchToZone(900, 1299, 3, 2);
    TEST_ASSERT_TRUE(bottom.zone == TouchZone::Page);
    TEST_ASSERT_EQUAL_INT(1, bottom.page); // bottom of row

    TEST_ASSERT_TRUE(mapTouchToZone(900, 1300, 3, 2).zone == TouchZone::None); // one px below
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1251, 3, 2).zone == TouchZone::None); // one px above
}

// --- Zellen ausserhalb beider Reihen ----------------------------------------

void test_touch_in_the_departures_area_maps_to_none(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(500, 700, 3, 2).zone == TouchZone::None);
}

// --- Header-Wert-Umwandlung -------------------------------------------------
// TouchZone::Page hat KEINEN festen Wert -- main.cpp formatiert "page_<N>"
// selbst aus TouchResult::page, s. Kommentar in touch_zone.h/.cpp.

void test_zone_to_header_value(void) {
    TEST_ASSERT_EQUAL_STRING("fav0", touchZoneToHeaderValue(TouchZone::Fav0));
    TEST_ASSERT_EQUAL_STRING("fav1", touchZoneToHeaderValue(TouchZone::Fav1));
    TEST_ASSERT_EQUAL_STRING("fav2", touchZoneToHeaderValue(TouchZone::Fav2));
    TEST_ASSERT_NULL(touchZoneToHeaderValue(TouchZone::None));
    TEST_ASSERT_NULL(touchZoneToHeaderValue(TouchZone::Page));
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_three_favorites_split_evenly);
    RUN_TEST(test_favorite_zone_boundaries_are_exact);
    RUN_TEST(test_single_favorite_spans_almost_the_whole_row);
    RUN_TEST(test_two_favorites_split_in_half);
    RUN_TEST(test_zero_favorites_means_no_favorite_zones_at_all);
    RUN_TEST(test_pagination_zones_absent_when_only_one_page);
    RUN_TEST(test_pagination_pill_two_pages_each_slot_is_its_own_absolute_page);
    RUN_TEST(test_pagination_pill_grows_leftward_and_slots_stay_87px);
    RUN_TEST(test_pagination_row_boundaries_are_exact);
    RUN_TEST(test_touch_in_the_departures_area_maps_to_none);
    RUN_TEST(test_zone_to_header_value);
    return UNITY_END();
}
