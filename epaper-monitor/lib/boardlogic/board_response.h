#pragma once
#include <string>
#include <cstddef>

// Rohe Header-Werte, so wie HTTPClient::header(name) sie liefert (immer als
// String, auch Zahlen) -- string statt Arduino::String, damit diese Datei
// ohne Arduino-Toolchain nativ testbar bleibt (Spec: kein Hardware-Zugriff
// vorhanden, siehe Global Constraints).
struct BoardHeaders {
    std::string mode;           // "X-Board-Mode": "full" | "patch"
    std::string etag;           // "X-Board-ETag"
    std::string generated;      // "X-Board-Generated" (ISO8601)
    std::string x;              // "X-Board-X" (Dezimalstring)
    std::string y;
    std::string w;
    std::string h;
    std::string contentLength;  // "Content-Length"
    std::string favoriteCount;  // "X-Board-Favorite-Count" (0-3)
    std::string totalPages;     // "X-Board-Total-Pages" (>=1)
};

enum class BoardResponseStatus {
    Ok,
    MissingOrMalformedHeaders,
    ContentLengthMismatch,
};

struct ParsedBoardResponse {
    BoardResponseStatus status = BoardResponseStatus::MissingOrMalformedHeaders;
    bool isPatch = false;
    int x = 0;
    int y = 0;
    int w = 0;
    int h = 0;
    int favoriteCount = 0;
    int totalPages = 1;
    std::string etag;
    std::string generated;
};

// Reine Funktion: validiert die vom Server gesendeten X-Board-*-Header
// gegen die tatsaechlich empfangene Body-Laenge (Spec §5/§11 "Antwort
// unlesbar"). Nimmt rohe String-Werte entgegen (kein HTTPClient/Arduino
// noetig), damit sie ohne Hardware testbar ist.
ParsedBoardResponse validateBoardResponse(const BoardHeaders& headers, size_t actualBodyLength);
