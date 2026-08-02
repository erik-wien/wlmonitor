#include <unity.h>
#include "board_model.h"

void test_parses_full_response(void) {
    const char* json = R"JSON(
    {
      "generated": "2026-08-02T16:44:43+02:00",
      "favorites": [
        {
          "id": 219,
          "title": "Arbeit",
          "stations": [
            {
              "diva": "60200103",
              "name": "Aßmayergasse",
              "lines": [
                {
                  "line": "59A",
                  "platform": "1",
                  "towards": "Bhf. Meidling S U",
                  "type": "bus",
                  "realtime": true,
                  "alert": false,
                  "departures": [
                    { "in": 4 },
                    { "in": 23, "towards": "Alterlaa" }
                  ]
                }
              ]
            }
          ]
        }
      ]
    }
    )JSON";

    BoardResponse out;
    ParseStatus status = parseBoardResponse(json, out);

    TEST_ASSERT_TRUE(status == ParseStatus::Ok);
    TEST_ASSERT_EQUAL_STRING("2026-08-02T16:44:43+02:00", out.generated.c_str());
    TEST_ASSERT_EQUAL(1, out.favorites.size());

    const Favorite& fav = out.favorites[0];
    TEST_ASSERT_EQUAL(219, fav.id);
    TEST_ASSERT_EQUAL_STRING("Arbeit", fav.title.c_str());
    TEST_ASSERT_EQUAL(1, fav.stations.size());

    const Station& st = fav.stations[0];
    TEST_ASSERT_EQUAL_STRING("60200103", st.diva.c_str());
    TEST_ASSERT_EQUAL(1, st.lines.size());

    const Line& ln = st.lines[0];
    TEST_ASSERT_EQUAL_STRING("59A", ln.name.c_str());
    TEST_ASSERT_EQUAL_STRING("1", ln.platform.c_str());
    TEST_ASSERT_EQUAL_STRING("Bhf. Meidling S U", ln.towards.c_str());
    TEST_ASSERT_EQUAL_STRING("bus", ln.type.c_str());
    TEST_ASSERT_TRUE(ln.realtime);
    TEST_ASSERT_FALSE(ln.alert);
    TEST_ASSERT_EQUAL(2, ln.departures.size());

    TEST_ASSERT_EQUAL(4, ln.departures[0].inMinutes);
    TEST_ASSERT_TRUE(ln.departures[0].towards.empty());
    TEST_ASSERT_FALSE(ln.departures[0].delayed);

    TEST_ASSERT_EQUAL(23, ln.departures[1].inMinutes);
    TEST_ASSERT_EQUAL_STRING("Alterlaa", ln.departures[1].towards.c_str());
}

void test_delayed_flag_is_read(void) {
    const char* json =
        "{\"generated\":\"2026-08-02T16:44:43+02:00\",\"favorites\":[{\"id\":1,\"title\":\"T\","
        "\"stations\":[{\"diva\":\"1\",\"name\":\"N\",\"lines\":[{\"line\":\"U6\",\"platform\":\"1\","
        "\"towards\":\"X\",\"type\":\"metro\",\"realtime\":true,\"alert\":false,"
        "\"departures\":[{\"in\":3,\"delayed\":true}]}]}]}]}";

    BoardResponse out;
    TEST_ASSERT_TRUE(parseBoardResponse(json, out) == ParseStatus::Ok);
    TEST_ASSERT_TRUE(out.favorites[0].stations[0].lines[0].departures[0].delayed);
}

void test_empty_favorites_is_ok(void) {
    BoardResponse out;
    ParseStatus status = parseBoardResponse(
        "{\"generated\":\"2026-08-02T16:44:43+02:00\",\"favorites\":[]}", out);
    TEST_ASSERT_TRUE(status == ParseStatus::Ok);
    TEST_ASSERT_EQUAL(0, out.favorites.size());
}

void test_unauthorized_error_body(void) {
    BoardResponse out;
    ParseStatus status = parseBoardResponse("{\"error\":\"unauthorized\"}", out);
    TEST_ASSERT_TRUE(status == ParseStatus::ErrorUnauthorized);
}

void test_upstream_unavailable_error_body(void) {
    BoardResponse out;
    ParseStatus status = parseBoardResponse("{\"error\":\"upstream_unavailable\"}", out);
    TEST_ASSERT_TRUE(status == ParseStatus::ErrorUpstreamUnavailable);
}

void test_server_error_body(void) {
    BoardResponse out;
    ParseStatus status = parseBoardResponse("{\"error\":\"server_error\"}", out);
    TEST_ASSERT_TRUE(status == ParseStatus::ErrorServerError);
}

void test_malformed_json_is_reported(void) {
    BoardResponse out;
    ParseStatus status = parseBoardResponse("{not json", out);
    TEST_ASSERT_TRUE(status == ParseStatus::ErrorMalformedJson);
}

int main(int argc, char** argv) {
    UNITY_BEGIN();
    RUN_TEST(test_parses_full_response);
    RUN_TEST(test_delayed_flag_is_read);
    RUN_TEST(test_empty_favorites_is_ok);
    RUN_TEST(test_unauthorized_error_body);
    RUN_TEST(test_upstream_unavailable_error_body);
    RUN_TEST(test_server_error_body);
    RUN_TEST(test_malformed_json_is_reported);
    return UNITY_END();
}
