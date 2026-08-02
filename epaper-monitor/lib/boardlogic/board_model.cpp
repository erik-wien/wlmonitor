#include "board_model.h"
#include <ArduinoJson.h>

ParseStatus parseBoardResponse(const std::string& json, BoardResponse& out) {
    JsonDocument doc;
    DeserializationError err = deserializeJson(doc, json);
    if (err) {
        return ParseStatus::ErrorMalformedJson;
    }

    if (doc["error"].is<const char*>()) {
        std::string code = doc["error"].as<const char*>();
        if (code == "unauthorized") return ParseStatus::ErrorUnauthorized;
        if (code == "upstream_unavailable") return ParseStatus::ErrorUpstreamUnavailable;
        if (code == "server_error") return ParseStatus::ErrorServerError;
        return ParseStatus::ErrorUnknownBody;
    }

    if (!doc["generated"].is<const char*>() || !doc["favorites"].is<JsonArray>()) {
        return ParseStatus::ErrorUnknownBody;
    }

    out.generated = doc["generated"].as<const char*>();
    out.favorites.clear();

    for (JsonObject favJson : doc["favorites"].as<JsonArray>()) {
        Favorite fav;
        fav.id = favJson["id"] | 0;
        fav.title = std::string((const char*) (favJson["title"] | ""));

        for (JsonObject stJson : favJson["stations"].as<JsonArray>()) {
            Station st;
            st.diva = std::string((const char*) (stJson["diva"] | ""));
            st.name = std::string((const char*) (stJson["name"] | ""));

            for (JsonObject lnJson : stJson["lines"].as<JsonArray>()) {
                Line ln;
                ln.name = std::string((const char*) (lnJson["line"] | ""));
                ln.platform = std::string((const char*) (lnJson["platform"] | ""));
                ln.towards = std::string((const char*) (lnJson["towards"] | ""));
                ln.type = std::string((const char*) (lnJson["type"] | "other"));
                ln.realtime = lnJson["realtime"] | true;
                ln.alert = lnJson["alert"] | false;

                for (JsonObject depJson : lnJson["departures"].as<JsonArray>()) {
                    Departure dep;
                    dep.inMinutes = depJson["in"] | 0;
                    dep.towards = std::string((const char*) (depJson["towards"] | ""));
                    dep.line = std::string((const char*) (depJson["line"] | ""));
                    dep.delayed = depJson["delayed"] | false;
                    ln.departures.push_back(dep);
                }
                st.lines.push_back(ln);
            }
            fav.stations.push_back(st);
        }
        out.favorites.push_back(fav);
    }

    return ParseStatus::Ok;
}
