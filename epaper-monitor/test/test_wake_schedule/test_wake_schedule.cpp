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

// --- Konfigurierbar vom Server (Nutzerwunsch 2026-09-04) -------------------
//
// Die Tests oben rufen ohne Zusatzargumente auf und pruefen damit zugleich,
// dass die VORGABEN das frueher fest verdrahtete Verhalten unveraendert
// wiedergeben. Ab hier die Werte, die ueber die Header hereinkommen.

void test_a_custom_interval_is_used_verbatim(void) {
    TEST_ASSERT_EQUAL_UINT32(1800, secondsUntilNextAutomaticWake(9, 0, 0, 1800));
    TEST_ASSERT_EQUAL_UINT32(7200, secondsUntilNextAutomaticWake(9, 0, 0, 7200));
}

void test_quiet_hours_can_span_midnight(void) {
    // Ruhezeit 22..07: um 23:00 steckt es schon drin -> bis 07:00 morgen.
    TEST_ASSERT_EQUAL_UINT32(8UL * 3600, secondsUntilNextAutomaticWake(23, 0, 0, 3600, 22, 7));
    // Um 21:00 noch nicht -- aber 22:00 laege drin, also gleich bis 07:00.
    TEST_ASSERT_EQUAL_UINT32(10UL * 3600, secondsUntilNextAutomaticWake(21, 0, 0, 3600, 22, 7));
}

void test_quiet_hours_within_one_day_work_too(void) {
    // Mittagsruhe 13..15: um 13:30 -> bis 15:00.
    TEST_ASSERT_EQUAL_UINT32(90UL * 60, secondsUntilNextAutomaticWake(13, 30, 0, 3600, 13, 15));
    // Um 09:00 stoert sie nicht.
    TEST_ASSERT_EQUAL_UINT32(3600, secondsUntilNextAutomaticWake(9, 0, 0, 3600, 13, 15));
}

void test_equal_start_and_end_means_no_quiet_period_at_all(void) {
    // Gueltige Einstellung, keine Fehleingabe: rund um die Uhr wecken.
    TEST_ASSERT_EQUAL_UINT32(3600, secondsUntilNextAutomaticWake(3, 0, 0, 3600, 0, 0));
    TEST_ASSERT_EQUAL_UINT32(3600, secondsUntilNextAutomaticWake(23, 30, 0, 3600, 0, 0));
    TEST_ASSERT_FALSE(isWithinQuietHours(0, 0, 0));
}

void test_a_zero_interval_falls_back_instead_of_waking_forever(void) {
    // Der Server laesst 0 nicht zu, aber dieser Wert kommt ueber das Netz --
    // ungerettet waere es ein Weckzyklus ohne Pause.
    TEST_ASSERT_EQUAL_UINT32(3600, secondsUntilNextAutomaticWake(9, 0, 0, 0));
}

void test_the_result_is_never_zero(void) {
    // Ein "Wecken in 0 Sekunden" waere ein Endlos-Weckzyklus, der den Akku
    // in Stunden leert. Ueber jede volle Stunde und jede Ruhezeit-Variante.
    for (int h = 0; h < 24; h++) {
        for (int qs = 0; qs < 24; qs += 5) {
            for (int qe = 0; qe < 24; qe += 7) {
                TEST_ASSERT_GREATER_THAN_UINT32(
                    0, secondsUntilNextAutomaticWake(h, 0, 0, 3600, qs, qe));
            }
        }
    }
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_a_custom_interval_is_used_verbatim);
    RUN_TEST(test_quiet_hours_can_span_midnight);
    RUN_TEST(test_quiet_hours_within_one_day_work_too);
    RUN_TEST(test_equal_start_and_end_means_no_quiet_period_at_all);
    RUN_TEST(test_a_zero_interval_falls_back_instead_of_waking_forever);
    RUN_TEST(test_the_result_is_never_zero);
    RUN_TEST(test_before_six_am_waits_until_exactly_six);
    RUN_TEST(test_exactly_six_am_waits_a_full_hour);
    RUN_TEST(test_daytime_always_waits_exactly_one_hour);
    RUN_TEST(test_at_23_00_the_next_hour_would_land_at_midnight_so_it_skips_to_six_am);
    RUN_TEST(test_late_evening_skips_the_stray_after_midnight_wake_and_goes_straight_to_six);
    RUN_TEST(test_one_second_before_midnight);
    return UNITY_END();
}
