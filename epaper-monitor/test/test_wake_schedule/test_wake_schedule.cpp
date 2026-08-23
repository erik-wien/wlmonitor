#include <unity.h>
#include "wake_schedule.h"

// Nutzervorgabe 2026-08-23: "Automatisches kann auf 1x pro Stunde ab 06:00
// Uhr zurueckgefahren werden. Danach wieder einschlafen." -- Nacht
// (00:00-05:59) still, ab 06:00 stuendlich.

void test_before_six_am_waits_until_exactly_six(void) {
    TEST_ASSERT_EQUAL_UINT32(6UL * 3600, secondsUntilNextAutomaticWake(0, 0, 0));
    TEST_ASSERT_EQUAL_UINT32(3600, secondsUntilNextAutomaticWake(5, 0, 0));
    TEST_ASSERT_EQUAL_UINT32(1, secondsUntilNextAutomaticWake(5, 59, 59));
}

void test_exactly_six_am_waits_a_full_hour(void) {
    TEST_ASSERT_EQUAL_UINT32(3600, secondsUntilNextAutomaticWake(6, 0, 0));
}

void test_daytime_always_waits_exactly_one_hour(void) {
    TEST_ASSERT_EQUAL_UINT32(3600, secondsUntilNextAutomaticWake(9, 0, 0));
    TEST_ASSERT_EQUAL_UINT32(3600, secondsUntilNextAutomaticWake(14, 37, 12));
    TEST_ASSERT_EQUAL_UINT32(3600, secondsUntilNextAutomaticWake(22, 59, 59));
}

void test_at_23_00_the_next_hour_would_land_at_midnight_so_it_skips_to_six_am(void) {
    // 23:00 + 3600s = 00:00 -- das waere die erste Sekunde der Nacht-Zone.
    // 7 Stunden bis 06:00 morgen.
    TEST_ASSERT_EQUAL_UINT32(7UL * 3600, secondsUntilNextAutomaticWake(23, 0, 0));
}

void test_late_evening_skips_the_stray_after_midnight_wake_and_goes_straight_to_six(void) {
    // 23:30 + 3600s waere 00:30 -- mitten in der Nacht-Zone. Muss stattdessen
    // bis 06:00 morgen durchschlafen, nicht bei 00:30 nochmal aufwachen.
    TEST_ASSERT_EQUAL_UINT32(6UL * 3600 + 30 * 60, secondsUntilNextAutomaticWake(23, 30, 0));
}

void test_one_second_before_midnight(void) {
    // 23:59:59 + 1s = Mitternacht, +3600s waere 00:59:59 -- Nacht-Zone.
    // Bis 06:00:01 morgen.
    TEST_ASSERT_EQUAL_UINT32(6UL * 3600 + 1, secondsUntilNextAutomaticWake(23, 59, 59));
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_before_six_am_waits_until_exactly_six);
    RUN_TEST(test_exactly_six_am_waits_a_full_hour);
    RUN_TEST(test_daytime_always_waits_exactly_one_hour);
    RUN_TEST(test_at_23_00_the_next_hour_would_land_at_midnight_so_it_skips_to_six_am);
    RUN_TEST(test_late_evening_skips_the_stray_after_midnight_wake_and_goes_straight_to_six);
    RUN_TEST(test_one_second_before_midnight);
    return UNITY_END();
}
