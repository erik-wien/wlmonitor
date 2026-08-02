#include "display.h"
#include "layout.h"
#include "logo.h"
#include "panel_select.h"
#include <GxEPD2_3C.h>
#include <Fonts/FreeSans9pt7b.h>
#include <Fonts/FreeSansBold9pt7b.h>
#include <Fonts/FreeSansBold24pt7b.h>
#include <Fonts/FreeSansBoldOblique24pt7b.h>

static GxEPD2_3C<PANEL_DRIVER, PANEL_DRIVER::HEIGHT / 2> epd(PANEL_DRIVER(EPD_CS, EPD_DC, EPD_RST, EPD_BUSY));

static const int16_t SCREEN_W = 800;
static const int16_t SCREEN_H = 480;
static const int16_t HEADER_H = 44;
static const int16_t COLUMN_PAD = 12;
static const int16_t AVG_CHAR_WIDTH_PX = 11;   // grobe Naeherung, siehe layout.h::truncateToWidth

void initDisplay() {
    epd.init(115200);
    epd.setRotation(0);
}

static void drawHeader(time_t generatedEpoch, time_t estimatedNow, ErrorBanner banner) {
    epd.drawBitmap(16, (HEADER_H - LOGO_HEIGHT) / 2, LOGO_BLACK, LOGO_WIDTH, LOGO_HEIGHT, GxEPD_BLACK);
    epd.drawBitmap(16, (HEADER_H - LOGO_HEIGHT) / 2, LOGO_RED, LOGO_WIDTH, LOGO_HEIGHT, GxEPD_RED);

    struct tm t;
    localtime_r(&generatedEpoch, &t);
    char stamp[16];
    snprintf(stamp, sizeof(stamp), "Stand %02d:%02d", t.tm_hour, t.tm_min);

    bool stale = isStale(estimatedNow, generatedEpoch, 15);
    epd.setFont(&FreeSansBold9pt7b);
    int16_t bx, by;
    uint16_t bw, bh;
    epd.getTextBounds(stamp, 0, 0, &bx, &by, &bw, &bh);
    int16_t sx = SCREEN_W - bw - 16;
    int16_t sy = HEADER_H / 2 + bh / 2;

    if (stale) {
        epd.fillRect(sx - 6, sy - bh - 4, bw + 12, bh + 10, GxEPD_RED);
        epd.setTextColor(GxEPD_WHITE);
    } else {
        epd.setTextColor(GxEPD_BLACK);
    }
    epd.setCursor(sx, sy);
    epd.print(stamp);

    if (banner == ErrorBanner::Offline || banner == ErrorBanner::TokenInvalid) {
        const char* msg = (banner == ErrorBanner::Offline) ? "offline" : "Token ungueltig";
        epd.setFont(&FreeSans9pt7b);
        epd.setTextColor(GxEPD_RED);
        epd.setCursor(SCREEN_W / 2 - 40, HEADER_H - 6);
        epd.print(msg);
    }
}

static void drawDeparture(int16_t x, int16_t y, const Departure& dep, DepartureStyle style) {
    bool big = (style == DepartureStyle::RedLive || style == DepartureStyle::BlackItalic);
    epd.setFont(big
        ? (style == DepartureStyle::BlackItalic
            ? (const GFXfont*) &FreeSansBoldOblique24pt7b
            : (const GFXfont*) &FreeSansBold24pt7b)
        : (const GFXfont*) &FreeSans9pt7b);

    uint16_t color = (style == DepartureStyle::RedLive) ? GxEPD_RED : GxEPD_BLACK;

    if (style == DepartureStyle::Inverted) {
        char probe[8];
        snprintf(probe, sizeof(probe), "%d", dep.inMinutes);
        int16_t bx, by;
        uint16_t bw, bh;
        epd.getTextBounds(probe, x, y, &bx, &by, &bw, &bh);
        epd.fillRect(bx - 3, by - 3, bw + 6, bh + 6, GxEPD_RED);
        color = GxEPD_WHITE;
    }

    epd.setTextColor(color);
    epd.setCursor(x, y);
    if (isDepartingNow(dep.inMinutes)) {
        epd.print("*");
    } else {
        epd.print(dep.inMinutes);
    }
}

static void drawColumn(int16_t colX, const Favorite& fav) {
    int16_t y = HEADER_H + 24;
    const int16_t colWidth = SCREEN_W / 2 - 16 - COLUMN_PAD;

    epd.setFont(&FreeSansBold9pt7b);
    epd.setTextColor(GxEPD_BLACK);
    epd.setCursor(colX, y);
    String title = fav.title.c_str();
    title.toUpperCase();
    epd.print(title);
    y += 26;

    for (const Station& st : fav.stations) {
        epd.setFont(&FreeSans9pt7b);
        epd.setTextColor(GxEPD_BLACK);
        epd.setCursor(colX, y);
        epd.print(truncateToWidth(st.name, colWidth, AVG_CHAR_WIDTH_PX).c_str());
        y += 22;

        for (const Line& ln : st.lines) {
            epd.setFont(&FreeSansBold9pt7b);
            epd.setTextColor(GxEPD_BLACK);
            epd.setCursor(colX, y);
            char prefix[24];
            snprintf(prefix, sizeof(prefix), "%s %s ", ln.name.c_str(), ln.platform.c_str());
            epd.print(prefix);
            epd.print(truncateToWidth(ln.towards, colWidth - 90, AVG_CHAR_WIDTH_PX).c_str());
            y += 20;

            int16_t depX = colX;
            for (size_t i = 0; i < ln.departures.size(); i++) {
                const Departure& dep = ln.departures[i];
                DepartureStyle style = departureStyle(i == 0, ln.realtime, dep.delayed);
                drawDeparture(depX, y, dep, style);
                bool big = (style == DepartureStyle::RedLive || style == DepartureStyle::BlackItalic);
                depX += big ? 60 : 36;
            }
            if (!ln.departures.empty()) y += 30;
        }
    }
}

void renderBoard(const BoardResponse& board, time_t generatedEpoch, time_t estimatedNow, ErrorBanner banner) {
    epd.setFullWindow();
    epd.firstPage();
    do {
        epd.fillScreen(GxEPD_WHITE);
        drawHeader(generatedEpoch, estimatedNow, banner);
        epd.drawFastVLine(SCREEN_W / 2, HEADER_H, SCREEN_H - HEADER_H, GxEPD_BLACK);

        if (board.favorites.size() > 0) drawColumn(16, board.favorites[0]);
        if (board.favorites.size() > 1) drawColumn(SCREEN_W / 2 + 16, board.favorites[1]);
    } while (epd.nextPage());
}
