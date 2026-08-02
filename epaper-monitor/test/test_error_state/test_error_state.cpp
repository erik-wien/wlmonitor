#include <unity.h>
#include "error_state.h"

void test_success_resets_counter_and_banner(void) {
    ErrorState st = nextErrorState(FetchOutcome::Success, 5);
    TEST_ASSERT_EQUAL(0, st.consecutiveFailures);
    TEST_ASSERT_TRUE(st.banner == ErrorBanner::None);
}

void test_unauthorized_shows_immediately_on_first_failure(void) {
    ErrorState st = nextErrorState(FetchOutcome::Unauthorized, 0);
    TEST_ASSERT_EQUAL(1, st.consecutiveFailures);
    TEST_ASSERT_TRUE(st.banner == ErrorBanner::TokenInvalid);
}

void test_unauthorized_stays_shown_after_a_streak(void) {
    ErrorState st = nextErrorState(FetchOutcome::Unauthorized, 5);
    TEST_ASSERT_EQUAL(6, st.consecutiveFailures);
    TEST_ASSERT_TRUE(st.banner == ErrorBanner::TokenInvalid);
}

void test_network_unavailable_stays_silent_below_threshold(void) {
    ErrorState st1 = nextErrorState(FetchOutcome::NetworkUnavailable, 0);
    TEST_ASSERT_EQUAL(1, st1.consecutiveFailures);
    TEST_ASSERT_TRUE(st1.banner == ErrorBanner::None);

    ErrorState st2 = nextErrorState(FetchOutcome::NetworkUnavailable, 1);
    TEST_ASSERT_EQUAL(2, st2.consecutiveFailures);
    TEST_ASSERT_TRUE(st2.banner == ErrorBanner::None);
}

void test_network_unavailable_shows_offline_at_third_failure(void) {
    ErrorState st = nextErrorState(FetchOutcome::NetworkUnavailable, 2);
    TEST_ASSERT_EQUAL(3, st.consecutiveFailures);
    TEST_ASSERT_TRUE(st.banner == ErrorBanner::Offline);
}

void test_network_unavailable_stays_offline_after_threshold(void) {
    ErrorState st = nextErrorState(FetchOutcome::NetworkUnavailable, 6);
    TEST_ASSERT_EQUAL(7, st.consecutiveFailures);
    TEST_ASSERT_TRUE(st.banner == ErrorBanner::Offline);
}

void test_unreadable_response_behaves_like_network_unavailable(void) {
    ErrorState st = nextErrorState(FetchOutcome::UnreadableResponse, 2);
    TEST_ASSERT_EQUAL(3, st.consecutiveFailures);
    TEST_ASSERT_TRUE(st.banner == ErrorBanner::Offline);
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_success_resets_counter_and_banner);
    RUN_TEST(test_unauthorized_shows_immediately_on_first_failure);
    RUN_TEST(test_unauthorized_stays_shown_after_a_streak);
    RUN_TEST(test_network_unavailable_stays_silent_below_threshold);
    RUN_TEST(test_network_unavailable_shows_offline_at_third_failure);
    RUN_TEST(test_network_unavailable_stays_offline_after_threshold);
    RUN_TEST(test_unreadable_response_behaves_like_network_unavailable);
    return UNITY_END();
}
