#include <unity.h>
#include "layout.h"

// ── Zeit ─────────────────────────────────────────────────────────────────

void test_parseIso8601_epoch_zero(void) {
    time_t epoch = -1;
    TEST_ASSERT_TRUE(parseIso8601("1970-01-01T00:00:00+00:00", epoch));
    TEST_ASSERT_EQUAL(0, (long) epoch);
}

void test_parseIso8601_positive_offset(void) {
    time_t epoch = -1;
    TEST_ASSERT_TRUE(parseIso8601("2026-08-02T16:44:43+02:00", epoch));
    TEST_ASSERT_EQUAL(1785681883L, (long) epoch);
}

void test_parseIso8601_negative_offset(void) {
    time_t epoch = -1;
    TEST_ASSERT_TRUE(parseIso8601("2026-08-02T16:44:43-05:00", epoch));
    TEST_ASSERT_EQUAL(1785707083L, (long) epoch);
}

void test_parseIso8601_rejects_malformed(void) {
    time_t epoch = -1;
    TEST_ASSERT_FALSE(parseIso8601("not-a-date", epoch));
}

void test_estimateNow_adds_elapsed_uptime(void) {
    time_t now = estimateNow((time_t) 1000, (uint64_t) 5000, (uint64_t) 65000);
    TEST_ASSERT_EQUAL(1060L, (long) now);
}

void test_isStale_false_below_threshold(void) {
    TEST_ASSERT_FALSE(isStale((time_t) (14 * 60), (time_t) 0, 15));
}

void test_isStale_true_at_threshold(void) {
    TEST_ASSERT_TRUE(isStale((time_t) (15 * 60), (time_t) 0, 15));
}

void test_isStale_false_when_fresh(void) {
    TEST_ASSERT_FALSE(isStale((time_t) 0, (time_t) 0, 15));
}

// ── Abfahrten ────────────────────────────────────────────────────────────

void test_departureStyle_first_realtime_is_red(void) {
    TEST_ASSERT_TRUE(departureStyle(true, true, false) == DepartureStyle::RedLive);
}

void test_departureStyle_first_schedule_only_is_black_italic(void) {
    TEST_ASSERT_TRUE(departureStyle(true, false, false) == DepartureStyle::BlackItalic);
}

void test_departureStyle_following_is_black_small(void) {
    TEST_ASSERT_TRUE(departureStyle(false, true, false) == DepartureStyle::BlackSmall);
}

void test_departureStyle_delayed_wins_when_first(void) {
    TEST_ASSERT_TRUE(departureStyle(true, true, true) == DepartureStyle::Inverted);
}

void test_departureStyle_delayed_wins_when_following(void) {
    TEST_ASSERT_TRUE(departureStyle(false, true, true) == DepartureStyle::Inverted);
}

void test_isDepartingNow(void) {
    TEST_ASSERT_TRUE(isDepartingNow(0));
    TEST_ASSERT_TRUE(isDepartingNow(-1));
    TEST_ASSERT_FALSE(isDepartingNow(1));
}

// ── Text ─────────────────────────────────────────────────────────────────

void test_utf8Length_counts_codepoints_not_bytes(void) {
    // A-sz-m-a-y-e-r-g-a-s-s-e = 12 Codepoints, aber 13 Bytes (sz ist 2 Bytes).
    TEST_ASSERT_EQUAL(12, (int) utf8Length("A\xc3\x9fmayergasse"));
}

void test_truncateToWidth_leaves_short_text_unchanged(void) {
    TEST_ASSERT_EQUAL_STRING("Arbeit", truncateToWidth("Arbeit", 400, 20).c_str());
}

void test_truncateToWidth_cuts_at_codepoint_boundary(void) {
    // "Flurschuetzstrasse" (16 Codepoints) mit ue als Multibyte-Zeichen genau
    // an der Schnittstelle -- darf nicht mitten im Zeichen kappen.
    // String-Literal-Bruch vor "e" noetig: "\xc3\x9fe" waere ein einziges,
    // ungueltiges 3-stelliges Hex-Escape (\x9fe), da 'e' selbst Hex-Ziffer ist.
    std::string input = "Flurschu\xc3\x9ftzstra\xc3\x9f" "e"; // "Flurschützstraße"
    std::string result = truncateToWidth(input, 100, 10);
    // maxChars = 100/10 = 10 -> 9 Zeichen + Ellipse.
    TEST_ASSERT_EQUAL_STRING("Flurschu\xc3\x9f\xe2\x80\xa6", result.c_str());
}

void test_truncateToWidth_tiny_budget_returns_ellipsis_only(void) {
    TEST_ASSERT_EQUAL_STRING("\xe2\x80\xa6", truncateToWidth("Arbeit", 5, 10).c_str());
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_parseIso8601_epoch_zero);
    RUN_TEST(test_parseIso8601_positive_offset);
    RUN_TEST(test_parseIso8601_negative_offset);
    RUN_TEST(test_parseIso8601_rejects_malformed);
    RUN_TEST(test_estimateNow_adds_elapsed_uptime);
    RUN_TEST(test_isStale_false_below_threshold);
    RUN_TEST(test_isStale_true_at_threshold);
    RUN_TEST(test_isStale_false_when_fresh);
    RUN_TEST(test_departureStyle_first_realtime_is_red);
    RUN_TEST(test_departureStyle_first_schedule_only_is_black_italic);
    RUN_TEST(test_departureStyle_following_is_black_small);
    RUN_TEST(test_departureStyle_delayed_wins_when_first);
    RUN_TEST(test_departureStyle_delayed_wins_when_following);
    RUN_TEST(test_isDepartingNow);
    RUN_TEST(test_utf8Length_counts_codepoints_not_bytes);
    RUN_TEST(test_truncateToWidth_leaves_short_text_unchanged);
    RUN_TEST(test_truncateToWidth_cuts_at_codepoint_boundary);
    RUN_TEST(test_truncateToWidth_tiny_budget_returns_ellipsis_only);
    return UNITY_END();
}
