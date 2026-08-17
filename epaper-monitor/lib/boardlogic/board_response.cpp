#include "board_response.h"
#include <cstdlib>

namespace {

bool parseNonNegativeInt(const std::string& s, int& out, int maxValue) {
    if (s.empty()) return false;
    for (char c : s) {
        if (c < '0' || c > '9') return false;
    }
    long value = std::strtol(s.c_str(), nullptr, 10);
    if (value < 0 || value > maxValue) return false;
    out = (int) value;
    return true;
}

} // namespace

ParsedBoardResponse validateBoardResponse(const BoardHeaders& headers, size_t actualBodyLength) {
    ParsedBoardResponse result;

    if (headers.mode != "full" && headers.mode != "patch") {
        return result;
    }
    if (headers.etag.empty() || headers.generated.empty()) {
        return result;
    }

    int x, y, w, h, contentLength, favoriteCount, totalPages;
    // Grosszuegige Obergrenze fuer x/y/w/h (Panel ist 1872x1404, 100000
    // ist bewusst weit drueber statt exakt 1872/1404 -- die Panelgroesse
    // selbst validiert diese Funktion nicht, nur "ist eine plausible Zahl").
    if (!parseNonNegativeInt(headers.x, x, 100000)
        || !parseNonNegativeInt(headers.y, y, 100000)
        || !parseNonNegativeInt(headers.w, w, 100000)
        || !parseNonNegativeInt(headers.h, h, 100000)
        || !parseNonNegativeInt(headers.contentLength, contentLength, 10000000)
        || !parseNonNegativeInt(headers.favoriteCount, favoriteCount, 3)
        || !parseNonNegativeInt(headers.totalPages, totalPages, 1000)) {
        return result;
    }
    if (totalPages < 1) {
        return result;
    }

    if ((size_t) contentLength != actualBodyLength) {
        result.status = BoardResponseStatus::ContentLengthMismatch;
        return result;
    }

    result.status = BoardResponseStatus::Ok;
    result.isPatch = (headers.mode == "patch");
    result.x = x;
    result.y = y;
    result.w = w;
    result.h = h;
    result.favoriteCount = favoriteCount;
    result.totalPages = totalPages;
    result.etag = headers.etag;
    result.generated = headers.generated;
    return result;
}
