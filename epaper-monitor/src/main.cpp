#include <Arduino.h>
#include <WiFi.h>
#include <WiFiManager.h>
#include <WebServer.h>
#include <Preferences.h>
#include <esp_sleep.h>
#include <esp_timer.h>
#include <driver/rtc_io.h>
#include <time.h>
#include "board_config.h"
#include "board_client.h"
#include "display.h"
#include "touch.h"
#include "buttons.h"
#include "battery.h"
#include "sensor.h"
#include "buzzer.h"
#include "error_state.h"
#include "wake_schedule.h"

// Default-Stack von loopTask ist 8192 Byte (arduino-esp32 cores/esp32/main.cpp) --
// zu knapp fuer WiFiManagers eigenen AP-Portal-Pfad (DNSServer + WebServer
// intern), wenn die gespeicherte AP nicht erreichbar ist: gemessener Absturz
// "stack overflow in task loopTask" genau in startConfigPortal() (2026-09-03,
// Boot-Loop am echten Geraet). Offizieller Override-Mechanismus des Cores
// (schwach definierte Funktion), kein Framework-Patch noetig.
size_t getArduinoLoopTaskStackSize(void) {
    return 16384;
}

// Ueberlebt Tiefschlaf (RTC-Speicher, ESP32-intern -- keine externe RTC
// noetig, s. Spec §10 und Global Constraints).
RTC_DATA_ATTR int rtcConsecutiveFailures = 0;
RTC_DATA_ATTR char rtcLastEtag[80] = "";
RTC_DATA_ATTR int rtcLastFavoriteCount = 0;
RTC_DATA_ATTR int rtcLastTotalPages = 1;
// Der Server berechnet Patches als Diff gegen SEIN eigenes letztes Bild --
// weiss aber nichts von lokal aufs Panel gezeichnetem Inhalt (Fehler-Banner,
// Touch-Blob, Status-/Build-/Input-Marker). Bleibt so eine lokale Flaeche
// serverseitig "unveraendert", nimmt kein kuenftiger Patch sie je wieder mit
// -- die lokale Zeichnung bleibt fuer immer stehen (Nutzerbefund 2026-08-22:
// sichtbare Verschiebung/Reste genau im Logo-Bereich, wo der Fehler-Banner
// sitzt). rtcBannerShown erzwingt ein Vollbild, sobald der Banner mal
// gezeichnet wurde, bis der naechste Erfolg das ganze Panel neu synchronisiert.
RTC_DATA_ATTR bool rtcBannerShown = false;

// PATCHES SIND ABGESCHAFFT (Messung 2026-08-22, fw43 am echten Geraet).
//
// Die Teilaktualisierung war die Ursache der Darstellungsfehler (doppelte
// Zeichen, angefressene Buchstaben) -- und hat sich als das schlechtere
// Geschaeft erwiesen, weil das Panel-Schreiben ~500ms FIXKOSTEN hat,
// unabhaengig von der Flaeche:
//
//   40x448   (winziger Patch)  557 ms
//   960x1255                   642 ms
//   1640x1255                  755 ms
//   1872x1404 (Vollbild)      1024 ms
//
// Ein Patch spart also hoechstens ~470ms Panel-Zeit -- kostet aber ein
// ZWEITES Status-Overlay (490-1097ms), weil nur ein Vollbild den Text
// "Bereit" schon serverseitig mitbringt. Unterm Strich ist das Vollbild
// sogar SCHNELLER. Auch am Netz war nichts zu holen: der GET dauert
// 734-821ms, weitgehend unabhaengig von 2 KB oder 257 KB Nutzlast, weil die
// Server-Renderzeit dominiert. Und die Patches waren ohnehin riesig (der
// typische Patch deckte 150-257 KB von 328 KB ab, weil sich Abfahrtszeiten
// ueber die ganze Spalte aendern).
//
// Damit entfaellt auch die ganze Resynchronisations-Mechanik
// (rtcPatchesSinceFull / FULL_REFRESH_EVERY_N_PATCHES) -- jeder Zyklus
// synchronisiert jetzt vollstaendig. Siehe docs/hardware/reterminal-e1003.md §20.8.
static const bool ALWAYS_FULL_FRAME = true;

static const uint32_t HTTP_TIMEOUT_MS = 8000;
static const uint32_t WAKE_BUTTON_LONG_PRESS_MS = 3000;
static const uint32_t WIFI_PORTAL_TIMEOUT_S = 180;

// Aktiv-Session (Nutzervorgabe 2026-08-21): "nach dem Aufwachen nur EINE
// Aktion ist Bloedsinn". Nach MANUELLEM Wecken (Taste) bleibt das Geraet in
// einer ECHTEN Schleife wach -- kein Deep Sleep zwischen einzelnen Aktionen.
// Eingaben werden sofort quittiert, Inhalt laedt periodisch nach. Erst nach
// ACTIVE_IDLE_TIMEOUT_MS ohne Eingabe geht es in den Tiefschlaf zurueck.
// 5 -> 10 Minuten (Nutzervorgabe 2026-08-23: "10 Minuten wach mit 25sec
// polling nur nach manuellem aufwecken") -- gilt seither NUR noch fuer
// manuelles Wecken, automatisches (Timer-)Wecken durchlaeuft diese Schleife
// gar nicht mehr (ein Abruf, sofort zurueck in den Schlaf, s. setup()).
static const uint32_t ACTIVE_IDLE_TIMEOUT_MS = 10UL * 60 * 1000;  // 10 Minuten
static const uint32_t REFRESH_INTERVAL_MS    = 25UL * 1000;      // 25 Sekunden
static const uint32_t INPUT_POLL_MS          = 30;               // wie Seeeds Touch-Beispiel
// POST_HIT_COOLDOWN_MS (900ms) entfernt (2026-08-22): seit fw31 verhindert
// der Press/Release-Zustand in runActiveSession() (touchDown/buttonDown)
// bereits zuverlaessig Mehrfachausloesung bei gehaltenem Finger/Taste -- die
// zusaetzliche Verzoegerung war nur noch spuerbare Wartezeit nach jeder
// Eingabe ohne echten Nutzen (Nutzerbefund 2026-08-22).

// Die Firmware-Marke wird seit fw46 SERVERSEITIG in die Statusleiste
// gerendert (Header X-Device-Firmware, s. board_client.cpp). Lokal gezeichnet
// war sie der teuerste Posten im ganzen Zyklus: gemessene 1104 ms fuer ein
// 256x50-Rechteck, mehr als ein komplettes Vollbild (1024 ms) -- jede lokale
// Teilaktualisierung zahlt die vollen Panel-Fixkosten.

// Zeigt der zuletzt erfolgreich geschriebene Frame GERADE den Schlafschirm
// (X-Board-Is-Sleep-Page, s. fetchAndRender())? Aktualisiert sich bei JEDEM
// erfolgreichen Abruf neu -- egal ob durch bewusstes Hinblaettern oder den
// erzwungenen Abruf vor dem Tiefschlaf. Zwei Stellen werten das aus:
// goToSleep() darf dann nichts mehr lokal darueber malen (jedes Overlay
// kostet einen vollen Panel-Schreibvorgang und wuerde den Inhalt verdecken),
// und die gruene Taste bedeutet dann "jetzt schlafen" statt "Vollupdate"
// (Nutzerwunsch 2026-08-23, Doppelbelegung statt einer eigenen vierten Taste).
// Bewusst KEIN RTC_DATA_ATTR: gilt nur fuer den laufenden Wachzyklus.
static bool showingSleepPage = false;

static Preferences prefs;

static String loadToken() {
    prefs.begin("board", true);
    String token = prefs.getString("token", "");
    prefs.end();
    return token;
}

static void saveToken(const String& token) {
    if (token.length() == 0) return;
    prefs.begin("board", false);
    prefs.putString("token", token);
    prefs.end();
}

// Admin-Webserver: laenger Druck auf die linke Taste (KEY2, sonst "Seite
// zurueck") waehrend das Geraet wach ist startet fuer 5 Minuten einen
// lokalen HTTP-Server im BEREITS VERBUNDENEN Heim-WLAN -- Nutzerwunsch
// 2026-08-25: Token aendern ohne jedes Mal das WiFiManager-Portal (das
// zusaetzlich das WLAN neu verbindet) durchlaufen zu muessen. Bewusst ohne
// Passwortschutz -- privates Heim-WLAN, Trigger erfordert physischen
// Zugriff aufs Geraet.
static WebServer* adminServer = nullptr;
static bool adminTokenSaved = false;

static void handleAdminRoot() {
    adminServer->send(200, "text/html",
        "<!DOCTYPE html><html><head><meta name='viewport' content='width=device-width,initial-scale=1'></head>"
        "<body style='font-family:sans-serif;max-width:480px;margin:2em auto;padding:0 1em'>"
        "<h2>WLmonitor Board -- Admin</h2>"
        "<form method='POST' action='/save'>"
        "<label>API-Token (profil.php)</label><br>"
        "<input name='token' style='width:100%;box-sizing:border-box;font-size:1rem' autofocus><br><br>"
        "<button type='submit' style='font-size:1rem;padding:0.5em 1em'>Speichern</button>"
        "</form></body></html>");
}

static void handleAdminSave() {
    String newToken = adminServer->arg("token");
    newToken.trim();
    if (newToken.length() == 0) {
        adminServer->send(400, "text/html", "<body>Leerer Token.</body>");
        return;
    }

    // Live gegen board.php pruefen statt blind zu speichern (Nutzerfrage
    // 2026-08-25: "wird der Token geprueft?") -- ein Tippfehler fiele sonst
    // erst NACH dem Neustart als "Token ungueltig"-Banner auf, statt sofort
    // im Browser. Nur eine bestaetigte Ablehnung (401) blockiert das
    // Speichern; bei Netzwerk-Unsicherheit (Timeout, 503, ...) lieber
    // trotzdem speichern, als den Nutzer ohne triftigen Grund auszubremsen.
    // Der Abruf blockiert bis zu 8s -- ohne diese Meldung stuende auf dem
    // Panel weiter der Admin-QR, als waere nichts passiert.
    showBootMessage("Token wird geprueft ...", "Einen Moment, bitte nichts druecken.");

    BoardFetchResult probe;
    fetchBoard(newToken.c_str(), nullptr, nullptr, 0, 0, 8000, probe);

    if (probe.outcome == BoardFetchOutcome::Unauthorized) {
        // Zurueck auf den Admin-Schirm: der Server laeuft weiter, der Nutzer
        // soll den QR erneut scannen bzw. die Seite neu laden koennen.
        char again[64];
        snprintf(again, sizeof(again), "http://%s/", WiFi.localIP().toString().c_str());
        char againText[128];
        snprintf(againText, sizeof(againText),
                 "Nicht gespeichert. QR scannen oder %s erneut oeffnen", again);
        showQrScreen("Token wurde abgelehnt", againText, again,
                     "Der Server hat den Token nicht akzeptiert. Neuen Token in profil.php erzeugen.");
        adminServer->send(200, "text/html",
            "<!DOCTYPE html><html><body style='font-family:sans-serif;max-width:480px;margin:2em auto'>"
            "<h2>Token ungueltig.</h2><p>board.php hat den Token abgelehnt (401) -- nicht gespeichert. "
            "<a href='/'>Nochmal versuchen</a></p></body></html>");
        return;
    }

    saveToken(newToken);
    adminTokenSaved = true;
    const char* verified = (probe.outcome == BoardFetchOutcome::Success)
        ? "Gegen board.php geprueft -- funktioniert."
        : "Konnte nicht eindeutig geprueft werden (Netzwerk) -- trotzdem gespeichert.";
    adminServer->send(200, "text/html",
        String("<!DOCTYPE html><html><body style='font-family:sans-serif;max-width:480px;margin:2em auto'>"
               "<h2>Gespeichert.</h2><p>") + verified + "</p><p>Geraet startet jetzt neu.</p></body></html>");

    // Dasselbe auch aufs Panel (Nutzerbefund 2026-08-25: "das sieht man im
    // Browser, aber nicht am Display") -- sonst steht dort weiter der
    // Admin-QR, obwohl der Server gleich weg ist und neu gestartet wird.
    showBootMessage("Token gespeichert", "Geraet startet neu -- bitte kurz warten.");
}

static void runAdminServer() {
    Serial.println("[admin] langer Druck erkannt, starte lokalen Webserver");
    WebServer server(80);
    adminServer = &server;
    adminTokenSaved = false;

    server.on("/", HTTP_GET, handleAdminRoot);
    server.on("/save", HTTP_POST, handleAdminSave);
    server.begin();

    char url[64];
    snprintf(url, sizeof(url), "http://%s/", WiFi.localIP().toString().c_str());
    char subtext[128];
    snprintf(subtext, sizeof(subtext),
             "QR-Code scannen -- oder am Handy/PC %s oeffnen", url);
    showQrScreen("Einstellungen aendern", subtext, url,
                 "Geraet muss im selben WLAN sein. Endet nach 5 Minuten von selbst.");
    Serial.printf("[admin] laeuft auf %s\n", url);

    const uint32_t ADMIN_TIMEOUT_MS = 5UL * 60UL * 1000UL;
    uint32_t start = millis();
    while (millis() - start < ADMIN_TIMEOUT_MS && !adminTokenSaved) {
        server.handleClient();
        delay(10);
    }
    if (adminTokenSaved) {
        delay(500); // Antwort noch ausliefern lassen, bevor der Server stoppt.
    }

    server.stop();
    adminServer = nullptr;
    Serial.println("[admin] beendet");

    if (adminTokenSaved) {
        ESP.restart(); // Einfachster Weg zu einem sauberen Zustand mit dem neuen Token.
    }
}

// WiFiManager ruft das nur auf, wenn tatsaechlich der Access-Point-Modus
// startet (kein gespeichertes WLAN oder Reset per Tastendruck) -- bei
// erfolgreichem Auto-Connect mit gespeicherten Daten bleibt die
// Boot-Meldung von provisionAndConnect() stehen, kein zusaetzlicher Refresh
// (Nutzerwunsch 2026-08-25).
static void onConfigPortalStarted(WiFiManager* wm) {
    // Offener Access Point (kein Passwort an wm.autoConnect() uebergeben) --
    // "T:nopass" im WIFI-QR-Format, sonst wuerden Handys ein Passwort
    // erwarten und die Verbindung ablehnen.
    showQrScreen("WLAN einrichten",
                 "1. QR scannen -- verbindet mit dem offenen WLAN 'wlmonitor-setup'",
                 "WIFI:T:nopass;S:wlmonitor-setup;;",
                 "2. Die Seite oeffnet sich meist von selbst. Sonst http://192.168.4.1/ aufrufen.");
}

static bool provisionAndConnect(String& outToken) {
    // Compile-Zeit-Token aus board_secrets.h (falls vorhanden) erzwingen --
    // ueberschreibt einen evtl. abweichenden gespeicherten Wert bei JEDEM
    // Boot, damit das Portal fuer den Token-Wert nicht mehr noetig ist
    // (Nutzerwunsch 2026-08-25). Leerer BOARD_API_TOKEN (keine
    // board_secrets.h) laesst den gespeicherten Wert unangetastet.
    if (strlen(BOARD_API_TOKEN) > 0) {
        saveToken(BOARD_API_TOKEN);
    }

    // Sprechender DHCP/mDNS-Hostname statt des ESP32-Standardnamens
    // ("esp32s3-C003D8") -- Nutzerwunsch 2026-08-25. Muss VOR dem Verbinden
    // gesetzt werden (WiFi.setHostname() wirkt erst beim naechsten
    // WiFi.begin(), das wm.autoConnect() intern ausloest).
    WiFi.setHostname("wlmonitor-eink");

    WiFiManager wm;
    String previousToken = loadToken();
    WiFiManagerParameter tokenParam("token", "API-Token (profil.php)", previousToken.c_str(), 128);
    wm.addParameter(&tokenParam);
    wm.setAPCallback(onConfigPortalStarted);

    if (isWakeButtonHeld(WAKE_BUTTON_LONG_PRESS_MS)) {
        wm.resetSettings(); // langer Tastendruck -> zurueck in den Access-Point-Modus (Spec §10)
    }

    wm.setConfigPortalTimeout(WIFI_PORTAL_TIMEOUT_S);
    bool connected = wm.autoConnect("wlmonitor-setup");
    if (!connected) return false;

    outToken = String(tokenParam.getValue());
    saveToken(outToken);
    return true;
}

static void syncTimeForTls() {
    configTime(0, 0, "pool.ntp.org", "time.nist.gov");
    struct tm timeinfo;
    getLocalTime(&timeinfo, 3000);
}

static void goToSleep() {
    // Ueber den Schlafschirm wird NICHTS mehr lokal gemalt: er ist bereits das
    // fertige Bild fuer die naechsten Stunden, und jedes Overlay kostet einen
    // vollen Panel-Schreibvorgang (~500ms Fixkosten, s. §20.8) und wuerde
    // seinen Inhalt ueberdecken. Nur wenn wir OHNE Schlafschirm einschlafen
    // (Verbindungsfehler beim Abruf), bleibt der bisherige Hinweis sinnvoll.
    if (!showingSleepPage) {
        showStatus(StatusIcon::Sleep, "Schlaf");
        markSleepIcon();
    }
    sleepPanel();
    WiFi.disconnect(true);
    // GPIO2 (Touch-INT) bewusst NICHT in der Weckmaske: der GT911 haelt
    // seinen Interrupt im normalen Scanbetrieb dauerhaft aktiv, quittieren
    // hilft nicht (am Geraet gemessen: Dauerwecken alle ~9s). Aufwecken
    // laeuft ueber die Tasten und den Timer; Touch wird waehrend der
    // Aktiv-Session ausgewertet, s. runActiveSession().
    const uint64_t wakePinMask = (1ULL << 3) | (1ULL << 4) | (1ULL << 5);
    esp_sleep_enable_ext1_wakeup(wakePinMask, ESP_EXT1_WAKEUP_ANY_LOW);

    for (int pin : {3, 4, 5}) {
        rtc_gpio_pullup_en(static_cast<gpio_num_t>(pin));
        rtc_gpio_pulldown_dis(static_cast<gpio_num_t>(pin));
    }
    touchClearInterrupt();

    // Zeitplan fuer das naechste automatische (Timer-)Wecken (Nutzervorgabe
    // 2026-08-23): 1x/Stunde ab 06:00, Nacht (00:00-05:59) komplett still --
    // s. wake_schedule.h. Braucht die lokale Uhrzeit; ist sie NICHT bekannt
    // (WLAN/NTP fehlgeschlagen, s. syncTimeForTls()), waere jede
    // Zeitplan-Berechnung Zufall -- dann der kurze Verbindungs-Retry
    // (NETWORK_RETRY_INTERVAL_SEC), damit ein voruebergehender Ausfall das
    // Geraet nicht fuer den Rest der Nacht verstummen laesst.
    struct tm localNow;
    const uint32_t sleepSeconds = getLocalTime(&localNow, 100)
        ? secondsUntilNextAutomaticWake(localNow.tm_hour, localNow.tm_min, localNow.tm_sec)
        : NETWORK_RETRY_INTERVAL_SEC;
    Serial.printf("[sleep] naechstes automatisches Wecken in %lu s\n", (unsigned long) sleepSeconds);

    esp_sleep_enable_timer_wakeup((uint64_t) sleepSeconds * 1000000ULL);
    esp_deep_sleep_start();
}

// Protokollwert der erkannten Eingabe -> Klartext fuer die Statusleiste.
// touchValue == nullptr heisst "kein Tipp, kein Tastendruck" (Zeit-Refresh
// oder der erste Abruf einer Session).
static const char* inputStatusText(const char* touchValue, bool forceFull) {
    if (forceFull && touchValue == nullptr) return "Vollbild";
    if (touchValue == nullptr)              return "Lade ...";
    if (strcmp(touchValue, "fav0") == 0)      return "Favorit 1";
    if (strcmp(touchValue, "fav1") == 0)      return "Favorit 2";
    if (strcmp(touchValue, "fav2") == 0)      return "Favorit 3";
    if (strcmp(touchValue, "page_next") == 0) return "Seite vor";
    if (strcmp(touchValue, "page_prev") == 0) return "Seite zurueck";
    return "Lade ...";
}

// Ein Abruf+Render-Zyklus. touchValue darf nullptr sein (reiner Zeit-Refresh).
// forceFull leert rtcLastEtag, damit board.php ohne If-None-Match antwortet
// und automatisch ein Vollbild statt eines Patches liefert.
// Liefert true, wenn ein Bild empfangen UND aufs Panel geschrieben wurde.
static bool fetchAndRender(const String& token, const char* touchValue, bool forceFull,
                           const char* screen = nullptr) {
    const uint32_t tCycle = millis();
    // Vollbild erzwingen, wenn ein Fehler-Banner lokal steht (der Server weiss
    // nichts davon) oder das periodische Resync-Intervall erreicht ist --
    // s. rtcBannerShown/ALWAYS_FULL_FRAME weiter oben.
    if (rtcBannerShown || ALWAYS_FULL_FRAME) {
        forceFull = true;
    }
    if (forceFull) rtcLastEtag[0] = '\0';

    // Ladezustand lokal VOR dem Request zeichnen -- der Server kann ihn nicht
    // rendern (er gilt waehrend/vor dem Request, nicht als dessen Ergebnis).
    // Am Ende dieser Funktion zurueck auf "Bereit", ausser die Antwort war ein
    // Vollbild (das bringt den Server-Standardtext schon mit) -- ein Patch
    // deckt nur die serverseitig geaenderten Pixel ab und koennte die
    // Statusleiste auslassen.
    //
    // Der Text nennt auch gleich die erkannte Eingabe. Das ersetzt den
    // frueheren separaten "in:<label>"-Kasten in der Abfahrtenspalte
    // (Nutzerwunsch 2026-08-22, "kompakter"): dieselbe Information, aber in
    // der Zeile, die ohnehin schon da ist, und in Klartext statt Protokoll-ID.
    // Beim Schlafschirm KEIN Overlay: es wuerde einen vollen Panel-Schreib-
    // vorgang kosten (~500ms Fixkosten, s. §20.8) und im selben Zyklus vom
    // ankommenden Vollbild wieder ueberschrieben.
    if (screen == nullptr) {
        showStatus(forceFull ? StatusIcon::Full : StatusIcon::Loading,
                   inputStatusText(touchValue, forceFull));
    }

    int batteryMv = readBatteryMillivolts();
    int rssi = WiFi.RSSI();

    BoardFetchResult fetch;
    fetchBoard(token.c_str(), touchValue, rtcLastEtag, batteryMv, rssi, HTTP_TIMEOUT_MS, fetch, screen);

    FetchOutcome outcome;
    switch (fetch.outcome) {
        case BoardFetchOutcome::Success:           outcome = FetchOutcome::Success; break;
        case BoardFetchOutcome::Unauthorized:       outcome = FetchOutcome::Unauthorized; break;
        case BoardFetchOutcome::UnreadableResponse: outcome = FetchOutcome::UnreadableResponse; break;
        default:                                    outcome = FetchOutcome::NetworkUnavailable; break;
    }

    ErrorState st = nextErrorState(outcome, rtcConsecutiveFailures);
    rtcConsecutiveFailures = st.consecutiveFailures;

    Serial.printf("[board] outcome=%d mode=%s %dx%d @%d,%d body=%u\n",
                  (int) fetch.outcome, fetch.parsed.isPatch ? "patch" : "full",
                  fetch.parsed.w, fetch.parsed.h, fetch.parsed.x, fetch.parsed.y,
                  (unsigned) fetch.body.size());

    bool wasFullFrame = false;
    if (outcome == FetchOutcome::Success) {
        if (fetch.parsed.isPatch) {
            // Sollte mit ALWAYS_FULL_FRAME nicht mehr vorkommen (leeres ETag ->
            // der Server hat nichts zu diffen). Bleibt als Notpfad stehen,
            // damit eine unerwartete Patch-Antwort nicht verworfen wird.
            applyPatch(fetch.body.data(), fetch.parsed.x, fetch.parsed.y, fetch.parsed.w, fetch.parsed.h);
        } else {
            applyFullFrame(fetch.body.data(), fetch.parsed.w, fetch.parsed.h);
            wasFullFrame = true;
        }
        rtcBannerShown = false;
        strncpy(rtcLastEtag, fetch.parsed.etag.c_str(), sizeof(rtcLastEtag) - 1);
        rtcLastEtag[sizeof(rtcLastEtag) - 1] = '\0';
        rtcLastFavoriteCount = fetch.parsed.favoriteCount;
        rtcLastTotalPages = fetch.parsed.totalPages;
        showingSleepPage = fetch.isSleepPage;
    } else if (st.banner != ErrorBanner::None) {
        showErrorBanner(st.banner, "??:??");
        rtcBannerShown = true;
    }

    // Bei einem Vollbild steht "Bereit" samt Piktogramm bereits serverseitig
    // im Frame (BOARD_STATUS_IDLE_TEXT, s. board_template.php) -- lokales
    // Nachzeichnen waere ein doppelter Panel-Write ohne sichtbaren Effekt.
    // Bei Patch oder Fehlerfall bleibt es noetig (Patch deckt die Statusleiste
    // i. d. R. nicht ab, Fehlerfall zeichnet gar keinen neuen Frame).
    if (!wasFullFrame) {
        showStatus(StatusIcon::Ready, "Bereit");
    }

    // Erst NACH allen lokalen Zeichnungen dieses Zyklus hochladen -- der
    // Schnappschuss soll den tatsaechlich fertig eingestellten Panel-Inhalt
    // zeigen, nicht einen Zwischenstand (Debug-Feature, s. web/board_snapshot.php).
    if (fetch.snapshotRequested) {
        uploadSnapshot(token.c_str(), getPanelBuffer(), getPanelBufferSize(), HTTP_TIMEOUT_MS);
    }

    Serial.printf("[perf] Zyklus gesamt (%s): %lu ms\n",
                  wasFullFrame ? "Vollbild" : "Patch/Fehler",
                  (unsigned long) (millis() - tCycle));

    return outcome == FetchOutcome::Success;
}

// Bleibt wach, bis ACTIVE_IDLE_TIMEOUT_MS lang keine Eingabe mehr kam.
// Eingaben (Touch/Tasten) loesen sofort einen Abruf aus; ohne Eingabe wird
// trotzdem alle REFRESH_INTERVAL_MS nachgeladen.
//
// NUR fuer manuelles (Tasten-)Wecken (Nutzervorgabe 2026-08-23: "10 Minuten
// wach mit 25sec polling nur nach manuellem aufwecken") -- automatisches
// (Timer-)Wecken durchlaeuft diese Funktion gar nicht mehr, s. setup(). Der
// "bereit"-Piep ist deshalb wieder bedingungslos: wer hier landet, hat das
// Geraet gerade selbst geweckt.
static void runActiveSession(const String& token) {
    // Ersten Abruf VOR dem "bereit"-Piep, nicht mehr danach (2026-08-21):
    // ein sofortiger erzwungener Refresh als allererste Schleifenaktion
    // blockierte 2-3s, WAEHREND der Piep schon "jetzt tippen" signalisierte
    // -- Touch wurde in dieser Zeit gar nicht gepollt (Nutzerbefund: "Piep,
    // dann noch 2s bis wirklich empfangsbereit"). Jetzt: erst laden, dann
    // piepen, dann WIRKLICH sofort pollen. Kein erzwungenes Vollbild fuer
    // diesen ersten Abruf mehr (Nutzervorgabe 2026-08-22): die gruene Taste
    // weckt nur noch, "Vollupdate" gilt erst innerhalb der Schleife unten.
    fetchAndRender(token, nullptr, false);

    beepConfirm();
    Serial.println("[active] bereit, Eingaben werden jetzt ausgewertet");
    uint32_t lastActivity = millis();
    // Press/Release-Zustand statt Zeit-/Distanz-Heuristik (2026-08-22): der
    // Fetch allein braucht schon 2-3s -- ein 3000ms-Zeitfenster ab
    // Touch-Erkennung ist da laengst abgelaufen,
    // WAEHREND der Finger noch aufliegt (Nutzerbefund: zweiter Piep+Blob
    // nach jedem Refresh trotz durchgehend gehaltenem Finger). Stattdessen:
    // ein Touch zaehlt erst wieder als "neu", wenn der Sensor zwischenzeitlich
    // mehrmals in Folge KEINEN Touch gemeldet hat (gegen Sensor-Flackern,
    // im Log als vereinzelte status=0x80 innerhalb einer echten Beruehrung
    // beobachtet).
    bool touchDown = false;
    int touchReleaseStreak = 0;
    const int TOUCH_RELEASE_STREAK_NEEDED = 5;
    // readPageButtons()/isFullUpdateButtonHeld() liefern anders als pollTouch()
    // keinen eigenen Press/Release-Zustand -- jeder Poll waehrend gehaltener
    // Taste liefert erneut "gedrueckt". Ohne Gegenmassnahme wuerde ein etwas
    // laenger gehaltener Tastendruck bei jedem Schleifendurchlauf (30ms) nach
    // Rueckkehr aus fetchAndRender() sofort den naechsten Fetch ausloesen.
    // Gleiches Muster wie touchDown: erst bei Loslassen (Taste nicht mehr
    // gedrueckt) zaehlt der naechste Druck wieder als neue Eingabe. Anders als
    // beim Touch-Sensor braucht das GPIO-Signal keinen Release-Streak gegen
    // Flackern -- readPageButtons()/isFullUpdateButtonHeld() entprellen die
    // Flanke bereits selbst (~50ms, s. buttons.cpp).
    bool buttonDown = false;
    uint32_t lastRefresh = millis(); // naechster automatischer Refresh erst in REFRESH_INTERVAL_MS

    // Die linke Taste (KEY2) wird KOMPLETT hier behandelt, nicht mehr ueber
    // readPageButtons(): kurz = "Seite zurueck" (erst beim LOSLASSEN, sonst
    // blaettert jeder Admin-Versuch auf dem Weg noch eine Seite zurueck),
    // lang (3s) = Admin-Modus (Nutzerwunsch 2026-08-25). Deshalb liest sie
    // den rohen Pin-Zustand statt der edge-getriggerten readPageButtons().
    uint32_t key2DownSince = 0;
    bool key2ConsumedByAdmin = false;
    const uint32_t ADMIN_TRIGGER_HOLD_MS = 3000;

    while (millis() - lastActivity < ACTIVE_IDLE_TIMEOUT_MS) {
        const char* touchValue = nullptr;
        bool forceFull = false;
        // Puffer fuer "page_<N>" (TouchZone::Page, TASK-25) -- der Wert ist
        // dynamisch, touchZoneToHeaderValue() kann dafuer keinen festen
        // const char* liefern. Lebt bis fetchAndRender() weiter unten in
        // diesem Schleifendurchlauf, das reicht.
        char pageTouchBuf[16];

        if (readRawButtonStates().key2Low) {
            if (key2DownSince == 0) {
                key2DownSince = millis();
            } else if (!key2ConsumedByAdmin && millis() - key2DownSince >= ADMIN_TRIGGER_HOLD_MS) {
                key2ConsumedByAdmin = true; // kein "Seite zurueck" mehr beim Loslassen
                beepConfirm();              // 3s sind um -- Finger darf runter
                runAdminServer();
                fetchAndRender(token, nullptr, true); // Panel zeigte "Admin-Modus" -- echtes Board zurueckholen.
                lastRefresh = millis();
                lastActivity = millis();
                continue;
            }
        } else {
            if (key2DownSince != 0 && !key2ConsumedByAdmin) {
                touchValue = "page_prev"; // kurzer Druck, jetzt losgelassen
                beepRecognized();
            }
            key2DownSince = 0;
            key2ConsumedByAdmin = false;
        }

        int touchX = 0, touchY = 0;
        TouchResult touch = pollTouch(rtcLastFavoriteCount, rtcLastTotalPages, &touchX, &touchY);
        if (touchValue != nullptr) {
            // Schon von der linken Taste belegt (kurzer Druck, oben erkannt) --
            // Touch/andere Tasten in diesem Durchlauf nicht mehr auswerten.
        } else if (touch.zone != TouchZone::None) {
            touchReleaseStreak = 0;
            if (!touchDown) {
                if (touch.zone == TouchZone::Page) {
                    // Absolute Pagination-Pille (TASK-25) -- Fav0/1/2 haben
                    // einen festen Header-Wert, die Zielseite hier nicht.
                    snprintf(pageTouchBuf, sizeof(pageTouchBuf), "page_%d", touch.page);
                    touchValue = pageTouchBuf;
                } else {
                    touchValue = touchZoneToHeaderValue(touch.zone);
                }
                beepRecognized();
                drawTouchBlob(touchX, touchY);
                touchDown = true;
            }
        } else if (const char* btn = readPageButtons()) {
            // "page_prev" (linke Taste) wird oben eigenstaendig behandelt --
            // hier nur die mittlere Taste ("page_next") durchlassen.
            if (!buttonDown && strcmp(btn, "page_prev") != 0) {
                touchValue = btn;
                beepRecognized();
                buttonDown = true;
            }
        } else if (isFullUpdateButtonHeld()) {
            if (!buttonDown) {
                buttonDown = true;
                if (showingSleepPage) {
                    // Doppelbelegung (Nutzerwunsch 2026-08-23): auf dem
                    // Schlafschirm gibt es keine Abfahrten zum Aktualisieren --
                    // "Vollupdate" waere hier bedeutungslos. Stattdessen bricht
                    // ein Druck auf die gruene Taste sofort in den Tiefschlaf
                    // ab, statt den Rest von ACTIVE_IDLE_TIMEOUT_MS zu warten.
                    beepConfirm();
                    Serial.println("[active] gruene Taste auf Schlafschirm -- sofort schlafen");
                    break;
                }
                forceFull = true;
                beepRecognized();
            }
        } else {
            if (++touchReleaseStreak >= TOUCH_RELEASE_STREAK_NEEDED) touchDown = false;
            buttonDown = false;
        }

        bool dueForRefresh = (millis() - lastRefresh >= REFRESH_INTERVAL_MS);
        if (touchValue != nullptr || forceFull || dueForRefresh) {
            fetchAndRender(token, touchValue, forceFull);
            lastRefresh = millis();
            if (touchValue != nullptr || forceFull) {
                lastActivity = millis();
            }
        }

        delay(INPUT_POLL_MS);
    }

    // Letztes Bild vor dem Tiefschlaf ist der SCHLAFSCHIRM (Nutzerwunsch
    // 2026-08-23): "Bei naeherer Betrachtung macht der Abfahrtsmonitor im
    // Schlafmodus wenig Sinn." Eingefrorene Abfahrtszeiten, die stundenlang
    // stehen, sind schlimmer als nutzlos -- sie sehen aus wie gueltige Daten.
    // Stattdessen Wetter heute/morgen und der Gaeste-WLAN-QR-Code.
    //
    // IMMER neu holen, auch wenn showingSleepPage schon true ist (z.B. weil
    // der Nutzer bewusst dorthin geblaettert und ihn stehen gelassen hat):
    // der Server rendert den erzwungenen Abruf OHNE Seitenzahlen-Pille
    // (screen="sleep" -> X-Device-Screen: sleep -> board.php setzt
    // $showPagination=false), der vorherige Frame hatte sie noch. Ohne
    // diesen zweiten Abruf bliebe die Pille sichtbar, WAEHREND das Panel
    // tatsaechlich schlaeft (Nutzerbefund 2026-08-23) -- ein fruehes "spart
    // sich das Geraet den erneuten Abruf" war hier ein echter Bug, keine
    // reine Optimierung.
    Serial.println("[active] 10 Minuten ohne Eingabe -- Schlafschirm ohne Paginierung holen");
    // Nur wenn der Schirm wirklich ankam: bei einem Netzfehler steht weiter
    // die Abfahrtenliste auf dem Panel, und dann ist der lokale Schlafhinweis
    // in goToSleep() genau richtig.
    fetchAndRender(token, nullptr, true, "sleep");
}

void setup() {
    // Weckgrund VOR jeder anderen Aktion sichern (Nutzervorgabe 2026-08-23,
    // s. runActiveSession()) -- esp_sleep_get_wakeup_cause() bleibt zwar bis
    // zum naechsten Schlaf gueltig, aber so steht die Absicht unmissverstaendlich
    // am Anfang von setup(), statt sich auf eine spaetere zufaellige
    // Gueltigkeit zu verlassen.
    const bool wokenByTimer = esp_sleep_get_wakeup_cause() == ESP_SLEEP_WAKEUP_TIMER;

    buzzerWarmup();
    // KEIN Boot-Zeit-Check von isFullUpdateButtonHeld() mehr (Nutzervorgabe
    // 2026-08-22): die gruene Taste ist jetzt derselbe Pin, der das Geraet
    // aus dem Tiefschlaf weckt -- ein normaler Weck-Druck wuerde sonst JEDE
    // Session mit einem erzwungenen Vollbild starten. Die Taste zaehlt erst
    // als "Vollupdate", sobald das Geraet in der Aktiv-Session-Schleife
    // laeuft (s. runActiveSession()), also wirklich schon wach ist.
    Serial.begin(115200);
    delay(300);
    Serial.printf("[boot] wokenByTimer=%d (cause=%d)\n", wokenByTimer, (int) esp_sleep_get_wakeup_cause());

    initDisplay();
    // Nur beim "echten" Boot (Kaltstart/manuelles Wecken), nicht beim
    // stuendlichen automatischen Timer-Wecken -- das soll laut Nutzervorgabe
    // 2026-08-23 schnell und ohne zusaetzliches Vollupdate/Geflacker bleiben,
    // s. Kommentar bei "automatisches Wecken" weiter unten.
    if (!wokenByTimer) {
        showBootMessage("Startet ...", "Verbindet sich mit dem WLAN und holt die Abfahrten.");
    }
    initTouch(); // Rueckgabewert bewusst ignoriert -- fehlender Touch ist nicht fatal, s. touch.h
    applyPanelTemperature(readAmbientTemperature());

    String token;
    if (!provisionAndConnect(token)) {
        // Hier steht noch "Startet ..." (kein zuvor gerendertes Abfahrtenbild
        // zum Schuetzen) -- der 3-Fehlversuche-Schwellwert von
        // nextErrorState() ist fuer den Fall gedacht, ein gueltiges Board
        // nicht wegen eines einzelnen WLAN-Hakelers zu ueberschreiben; beim
        // Boot gilt das nicht, also sofortige Rueckmeldung statt bis zu drei
        // stumme Zyklen (Nutzerbefund 2026-08-25: "kann sich offensichtlich
        // nicht verbinden, ich sehe aber keine Fehlermeldung").
        if (!wokenByTimer) {
            // Nennt auch den Ausweg: ohne diesen Hinweis ist der Bildschirm
            // eine Sackgasse -- der Admin-Modus braucht WLAN, ist hier also
            // gerade nicht erreichbar, und dass die gruene Taste beim Booten
            // ins WLAN-Setup fuehrt, steht sonst nirgends am Geraet.
            showBootMessage("Keine WLAN-Verbindung",
                            "Versucht es automatisch erneut. Zum Neu-Einrichten: gruene Taste beim Start 3 Sek. halten.");
        }
        ErrorState st = nextErrorState(FetchOutcome::NetworkUnavailable, rtcConsecutiveFailures);
        rtcConsecutiveFailures = st.consecutiveFailures;
        if (st.banner != ErrorBanner::None) {
            showErrorBanner(st.banner, "??:??");
        }
        goToSleep();
        return;
    }

    syncTimeForTls();

    if (wokenByTimer) {
        // Automatisches Wecken (Nutzervorgabe 2026-08-23): EIN Abruf, KEIN
        // Piep (niemand steht davor), sofort zurueck in den Schlaf -- keine
        // 10-Minuten-Aktiv-Session. Zeigt die zuletzt aktive Abfahrtenseite
        // (nicht den Schlafschirm): stuendliche Abfahrtsdaten sind noch
        // brauchbar frisch, anders als nach einer stundenlangen Funkstille.
        Serial.println("[boot] automatisches Wecken -- ein Abruf, kein Piep");
        fetchAndRender(token, nullptr, false);
    } else {
        // Touch-/Tastenpruefung startet HIER, nicht erst nach einem ersten
        // Abruf (der bisher WLAN-Connect+Zeit-Sync+Fetch bloehte -- spuerbare
        // Verzoegerung zwischen Piep und tatsaechlicher Touch-Reaktion,
        // Nutzerbefund 2026-08-21). runActiveSession() macht den ersten Abruf
        // selbst, als Teil der Schleife.
        runActiveSession(token);
    }

    goToSleep();
}

void loop() {
    // Ungenutzt: setup() kehrt erst nach goToSleep() zurueck.
}
