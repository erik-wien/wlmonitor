#pragma once
#include <string>
#include <vector>

struct Departure {
    int inMinutes = 0;
    std::string towards;   // leer = keine Abweichung von Line::towards
    std::string line;      // leer = keine Abweichung von Line::name
    bool delayed = false;
};

struct Line {
    std::string name;
    std::string platform;
    std::string towards;
    std::string type;      // "metro" | "tram" | "bus" | "train" | "other"
    bool realtime = true;
    bool alert = false;
    std::vector<Departure> departures;
};

struct Station {
    std::string diva;
    std::string name;
    std::vector<Line> lines;
};

struct Favorite {
    int id = 0;
    std::string title;
    std::vector<Station> stations;
};

struct BoardResponse {
    std::string generated;   // ISO 8601 mit Zone, z. B. "2026-08-02T16:44:43+02:00"
    std::vector<Favorite> favorites;
};

enum class ParseStatus {
    Ok,
    ErrorUnauthorized,        // Koerper war {"error":"unauthorized"}
    ErrorUpstreamUnavailable, // {"error":"upstream_unavailable"}
    ErrorServerError,         // {"error":"server_error"}
    ErrorUnknownBody,         // gueltiges JSON, aber weder Antwort noch bekannter Fehler
    ErrorMalformedJson,       // das JSON selbst liess sich nicht parsen
};

ParseStatus parseBoardResponse(const std::string& json, BoardResponse& out);
