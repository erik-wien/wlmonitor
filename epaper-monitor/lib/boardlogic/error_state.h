#pragma once

enum class FetchOutcome {
    Success,
    NetworkUnavailable,   // WLAN-Verbindung fehlgeschlagen, HTTP-Zeitueberschreitung, oder 503
    Unauthorized,          // HTTP 401 bzw. Antwortkoerper {"error":"unauthorized"}
    UnreadableResponse,    // Antwort kam an, liess sich aber nicht als BoardResponse parsen
};

enum class ErrorBanner {
    None,
    Offline,        // "offline seit HH:MM"
    TokenInvalid,   // "Token ungueltig"
};

struct ErrorState {
    int consecutiveFailures = 0;
    ErrorBanner banner = ErrorBanner::None;
};

// Reiner Zustandsuebergang: aus dem Ergebnis EINES Zyklus und dem Zaehler
// des vorigen Zyklus wird der naechste Zaehler und die anzuzeigende
// Kopfzeile (Spec §9).
ErrorState nextErrorState(FetchOutcome outcome, int previousConsecutiveFailures);
