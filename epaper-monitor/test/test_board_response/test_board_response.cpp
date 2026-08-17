#include <unity.h>
#include "board_response.h"

void test_valid_full_frame_headers_parse_ok(void) {
    BoardHeaders h;
    h.mode = "full"; h.etag = "\"abc\""; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "1872"; h.h = "1404";
    h.contentLength = "10"; h.favoriteCount = "1"; h.totalPages = "1";

    ParsedBoardResponse r = validateBoardResponse(h, 10);

    TEST_ASSERT_TRUE(r.status == BoardResponseStatus::Ok);
    TEST_ASSERT_FALSE(r.isPatch);
    TEST_ASSERT_EQUAL(1872, r.w);
    TEST_ASSERT_EQUAL(1404, r.h);
    TEST_ASSERT_EQUAL(1, r.favoriteCount);
    TEST_ASSERT_EQUAL(1, r.totalPages);
}

void test_valid_patch_headers_parse_ok(void) {
    BoardHeaders h;
    h.mode = "patch"; h.etag = "\"def\""; h.generated = "2026-08-17T10:05:00+02:00";
    h.x = "8"; h.y = "16"; h.w = "64"; h.h = "32";
    h.contentLength = "256"; h.favoriteCount = "3"; h.totalPages = "2";

    ParsedBoardResponse r = validateBoardResponse(h, 256);

    TEST_ASSERT_TRUE(r.status == BoardResponseStatus::Ok);
    TEST_ASSERT_TRUE(r.isPatch);
    TEST_ASSERT_EQUAL(8, r.x);
    TEST_ASSERT_EQUAL(16, r.y);
    TEST_ASSERT_EQUAL(3, r.favoriteCount);
    TEST_ASSERT_EQUAL(2, r.totalPages);
}

void test_missing_mode_is_malformed(void) {
    BoardHeaders h;
    h.etag = "\"abc\""; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "1872"; h.h = "1404";
    h.contentLength = "10"; h.favoriteCount = "1"; h.totalPages = "1";

    TEST_ASSERT_TRUE(validateBoardResponse(h, 10).status == BoardResponseStatus::MissingOrMalformedHeaders);
}

void test_unknown_mode_value_is_malformed(void) {
    BoardHeaders h;
    h.mode = "partial"; h.etag = "\"abc\""; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "1872"; h.h = "1404";
    h.contentLength = "10"; h.favoriteCount = "1"; h.totalPages = "1";

    TEST_ASSERT_TRUE(validateBoardResponse(h, 10).status == BoardResponseStatus::MissingOrMalformedHeaders);
}

void test_non_numeric_width_is_malformed(void) {
    BoardHeaders h;
    h.mode = "full"; h.etag = "\"abc\""; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "abc"; h.h = "1404";
    h.contentLength = "10"; h.favoriteCount = "1"; h.totalPages = "1";

    TEST_ASSERT_TRUE(validateBoardResponse(h, 10).status == BoardResponseStatus::MissingOrMalformedHeaders);
}

void test_favorite_count_out_of_range_is_malformed(void) {
    BoardHeaders h;
    h.mode = "full"; h.etag = "\"abc\""; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "1872"; h.h = "1404";
    h.contentLength = "10"; h.favoriteCount = "4"; h.totalPages = "1"; // spec: max 3

    TEST_ASSERT_TRUE(validateBoardResponse(h, 10).status == BoardResponseStatus::MissingOrMalformedHeaders);
}

void test_content_length_mismatch_is_detected(void) {
    BoardHeaders h;
    h.mode = "full"; h.etag = "\"abc\""; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "1872"; h.h = "1404";
    h.contentLength = "999"; h.favoriteCount = "1"; h.totalPages = "1";

    ParsedBoardResponse r = validateBoardResponse(h, 10);

    TEST_ASSERT_TRUE(r.status == BoardResponseStatus::ContentLengthMismatch);
}

void test_empty_etag_is_malformed(void) {
    BoardHeaders h;
    h.mode = "full"; h.generated = "2026-08-17T10:00:00+02:00";
    h.x = "0"; h.y = "0"; h.w = "1872"; h.h = "1404";
    h.contentLength = "10"; h.favoriteCount = "1"; h.totalPages = "1";

    TEST_ASSERT_TRUE(validateBoardResponse(h, 10).status == BoardResponseStatus::MissingOrMalformedHeaders);
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_valid_full_frame_headers_parse_ok);
    RUN_TEST(test_valid_patch_headers_parse_ok);
    RUN_TEST(test_missing_mode_is_malformed);
    RUN_TEST(test_unknown_mode_value_is_malformed);
    RUN_TEST(test_non_numeric_width_is_malformed);
    RUN_TEST(test_favorite_count_out_of_range_is_malformed);
    RUN_TEST(test_content_length_mismatch_is_detected);
    RUN_TEST(test_empty_etag_is_malformed);
    return UNITY_END();
}
