#include <unity.h>
#include "touch_zone.h"

// --- Favoriten-Zonen (y in [1320,1404)) ------------------------------------

void test_three_favorites_split_evenly(void) {
    // buttonWidth = (1872-32-32)/3 = 602
    // fav0: [16,618)  fav1: [634,1236)  fav2: [1252,1854)
    TEST_ASSERT_TRUE(mapTouchToZone(300, 1350, 3, 1) == TouchZone::Fav0);
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1350, 3, 1) == TouchZone::Fav1);
    TEST_ASSERT_TRUE(mapTouchToZone(1500, 1350, 3, 1) == TouchZone::Fav2);
}

void test_favorite_zone_boundaries_are_exact(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(16, 1320, 3, 1) == TouchZone::Fav0);   // top-left corner, inclusive
    TEST_ASSERT_TRUE(mapTouchToZone(617, 1403, 3, 1) == TouchZone::Fav0); // bottom-right, still inside (Bildschirmrand)
    TEST_ASSERT_TRUE(mapTouchToZone(618, 1350, 3, 1) == TouchZone::None); // gap between fav0/fav1
    TEST_ASSERT_TRUE(mapTouchToZone(634, 1350, 3, 1) == TouchZone::Fav1); // fav1 starts here
    TEST_ASSERT_TRUE(mapTouchToZone(300, 1404, 3, 1) == TouchZone::None); // one px below the row
    TEST_ASSERT_TRUE(mapTouchToZone(300, 1319, 3, 1) == TouchZone::None); // one px above the row
}

void test_single_favorite_spans_almost_the_whole_row(void) {
    // buttonWidth = (1872-32-0)/1 = 1840, fav0: [16,1856)
    TEST_ASSERT_TRUE(mapTouchToZone(16, 1350, 1, 1) == TouchZone::Fav0);
    TEST_ASSERT_TRUE(mapTouchToZone(1800, 1350, 1, 1) == TouchZone::Fav0);
    TEST_ASSERT_TRUE(mapTouchToZone(1860, 1350, 1, 1) == TouchZone::None);
}

void test_two_favorites_split_in_half(void) {
    // buttonWidth = (1872-32-16)/2 = 912, fav0: [16,928) fav1: [944,1856)
    TEST_ASSERT_TRUE(mapTouchToZone(500, 1350, 2, 1) == TouchZone::Fav0);
    TEST_ASSERT_TRUE(mapTouchToZone(1000, 1350, 2, 1) == TouchZone::Fav1);
    TEST_ASSERT_TRUE(mapTouchToZone(935, 1350, 2, 1) == TouchZone::None); // gap
}

void test_zero_favorites_means_no_favorite_zones_at_all(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(300, 1350, 0, 1) == TouchZone::None);
}

// --- Pagination-Zonen (y in [1252,1308)) -- rechtsbuendig an x=1083 --------
// Seit der Schlafschirm immer eine zusaetzliche letzte Seite ist (2026-08-23),
// ist totalPages praktisch nie mehr 1 -- die Pille waechst deshalb rechts-
// buendig ab x=1083 nach LINKS (pillWidth = totalPages*87 + 20), statt wie
// vor der Pfeil-Streichung linksbuendig ab einer festen Startposition.

void test_pagination_zones_absent_when_only_one_page(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1280, 3, 1) == TouchZone::None);
}

void test_pagination_pill_two_pages_splits_at_986(void) {
    // totalPages=2: pillWidth=2*87+20=194, pillStartX=1083-194=889, mid=889+97=986
    TEST_ASSERT_TRUE(mapTouchToZone(888, 1280, 3, 2) == TouchZone::None);     // vor der Pille
    TEST_ASSERT_TRUE(mapTouchToZone(889, 1280, 3, 2) == TouchZone::PagePrev); // Pillenanfang
    TEST_ASSERT_TRUE(mapTouchToZone(985, 1280, 3, 2) == TouchZone::PagePrev);
    TEST_ASSERT_TRUE(mapTouchToZone(986, 1280, 3, 2) == TouchZone::PageNext);
    TEST_ASSERT_TRUE(mapTouchToZone(1082, 1280, 3, 2) == TouchZone::PageNext);
    TEST_ASSERT_TRUE(mapTouchToZone(1083, 1280, 3, 2) == TouchZone::None);    // Pillenende, exklusiv
}

void test_pagination_pill_grows_leftward_with_more_pages(void) {
    // totalPages=5: pillWidth=5*87+20=455, pillStartX=1083-455=628, mid=628+227=855
    TEST_ASSERT_TRUE(mapTouchToZone(627, 1280, 3, 5) == TouchZone::None);     // vor der Pille
    TEST_ASSERT_TRUE(mapTouchToZone(700, 1280, 3, 5) == TouchZone::PagePrev);
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1280, 3, 5) == TouchZone::PageNext);
    TEST_ASSERT_TRUE(mapTouchToZone(1082, 1280, 3, 5) == TouchZone::PageNext);
    TEST_ASSERT_TRUE(mapTouchToZone(1083, 1280, 3, 5) == TouchZone::None);
}

void test_pagination_row_boundaries_are_exact(void) {
    // totalPages=2: pill [889,1083), mid=986 -- x=900 liegt links von mid.
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1252, 3, 2) == TouchZone::PagePrev); // top of row
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1307, 3, 2) == TouchZone::PagePrev); // bottom of row
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1308, 3, 2) == TouchZone::None);     // one px below
    TEST_ASSERT_TRUE(mapTouchToZone(900, 1251, 3, 2) == TouchZone::None);     // one px above
}

// --- Zellen ausserhalb beider Reihen ----------------------------------------

void test_touch_in_the_departures_area_maps_to_none(void) {
    TEST_ASSERT_TRUE(mapTouchToZone(500, 700, 3, 2) == TouchZone::None);
}

// --- Header-Wert-Umwandlung -------------------------------------------------

void test_zone_to_header_value(void) {
    TEST_ASSERT_EQUAL_STRING("fav0", touchZoneToHeaderValue(TouchZone::Fav0));
    TEST_ASSERT_EQUAL_STRING("fav1", touchZoneToHeaderValue(TouchZone::Fav1));
    TEST_ASSERT_EQUAL_STRING("fav2", touchZoneToHeaderValue(TouchZone::Fav2));
    TEST_ASSERT_EQUAL_STRING("page_prev", touchZoneToHeaderValue(TouchZone::PagePrev));
    TEST_ASSERT_EQUAL_STRING("page_next", touchZoneToHeaderValue(TouchZone::PageNext));
    TEST_ASSERT_NULL(touchZoneToHeaderValue(TouchZone::None));
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_three_favorites_split_evenly);
    RUN_TEST(test_favorite_zone_boundaries_are_exact);
    RUN_TEST(test_single_favorite_spans_almost_the_whole_row);
    RUN_TEST(test_two_favorites_split_in_half);
    RUN_TEST(test_zero_favorites_means_no_favorite_zones_at_all);
    RUN_TEST(test_pagination_zones_absent_when_only_one_page);
    RUN_TEST(test_pagination_pill_two_pages_splits_at_986);
    RUN_TEST(test_pagination_pill_grows_leftward_with_more_pages);
    RUN_TEST(test_pagination_row_boundaries_are_exact);
    RUN_TEST(test_touch_in_the_departures_area_maps_to_none);
    RUN_TEST(test_zone_to_header_value);
    return UNITY_END();
}
