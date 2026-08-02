#include "error_state.h"

static const int OFFLINE_THRESHOLD = 3;

ErrorState nextErrorState(FetchOutcome outcome, int previousConsecutiveFailures) {
    ErrorState state;

    if (outcome == FetchOutcome::Success) {
        state.consecutiveFailures = 0;
        state.banner = ErrorBanner::None;
        return state;
    }

    if (outcome == FetchOutcome::Unauthorized) {
        // Behebt sich nicht von selbst -> sofort anzeigen, unabhaengig vom Zaehler.
        state.consecutiveFailures = previousConsecutiveFailures + 1;
        state.banner = ErrorBanner::TokenInvalid;
        return state;
    }

    // NetworkUnavailable und UnreadableResponse verhalten sich wie ein
    // WLAN-Ausfall: Bild stehen lassen, erst nach 3 Fehlversuchen melden.
    state.consecutiveFailures = previousConsecutiveFailures + 1;
    state.banner = (state.consecutiveFailures >= OFFLINE_THRESHOLD)
        ? ErrorBanner::Offline
        : ErrorBanner::None;
    return state;
}
