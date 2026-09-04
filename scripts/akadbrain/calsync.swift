// scripts/akadbrain/calsync.swift
//
// Liest die ausgewaehlten Kalender der naechsten Tage aus EventKit und schreibt
// sie als JSON nach data/calendar/<userId>.json -- dieselbe Rolle wie
// scripts/weather_fetch_cron.php beim Wetter: eine Quelle ausserhalb der App
// wird in einen Cache gelegt, den web/board.php dann nur noch liest.
//
// LAEUFT AUF AKADBRAIN, nicht auf dem MacBook. Erste Fassung schob die Daten per
// HTTPS von Hamish hierher, weil dort der Outlook-Kalender lag. Der ist per
// EventKit ohnehin unerreichbar (er steckt in Outlook.apps eigenem Container,
// Machbarkeitspruefung 2026-08-26) und faellt auf Nutzerentscheidung weg. Damit
// sind alle verbliebenen Kalender iCloud-/Abo-Kalender, die auch hier vorliegen --
// und der Server laeuft durch, statt wie ein Laptop zu schlafen. Das spart den
// ganzen Push-Weg: kein Endpunkt, kein Token, keine veralteten Termine.
//
// WARUM EVENTKIT UND NICHT CALDAV: Geburtstage, Feiertage und "regelmaessig" sind
// Serientermine. CalDAV liefert rohe Wiederholungsregeln, die aufzuloesen waeren;
// EventKit liefert die fertigen Einzeltermine.
//
// DIE TRAGENDE REGEL: Bei Erfolg schreiben, auch wenn die Liste LEER ist (so
// erfaehrt das Board "heute ist nichts"). Bei JEDEM Fehler NICHT schreiben, damit
// der letzte gute Stand stehen bleibt und die Seite ehrlich "veraltet" meldet,
// statt faelschlich Terminfreiheit zu behaupten.
//
// Bauen/Installieren: scripts/akadbrain/install-calsync.sh

import EventKit
import Foundation

// --- Exit-Codes (sprechend, damit das launchd-Log diagnostisch ist) ----------
let EXIT_CONFIG  : Int32 = 64 // EX_USAGE     -- Konfiguration fehlt/unvollstaendig
let EXIT_NOCAL   : Int32 = 75 // EX_TEMPFAIL  -- erwartete Kalender nicht da (noch nicht synchronisiert?)
let EXIT_NOPERM  : Int32 = 77 // EX_NOPERM    -- TCC verweigert

func fehler(_ text: String, _ code: Int32) -> Never {
    FileHandle.standardError.write(Data("calsync: \(text)\n".utf8))
    exit(code)
}

// --- Konfiguration ----------------------------------------------------------
// launchd-Agents erben KEINE Shell-Umgebung, also liest das Programm die Datei
// selbst. chmod 600 -- sie enthaelt den API-Token.

let configPfad = NSString(string: "~/.config/wlmonitor/calsync.env").expandingTildeInPath

func ladeConfig(_ pfad: String) -> [String: String] {
    guard let inhalt = try? String(contentsOfFile: pfad, encoding: .utf8) else { return [:] }
    var werte: [String: String] = [:]
    for zeile in inhalt.split(separator: "\n") {
        let z = zeile.trimmingCharacters(in: .whitespaces)
        if z.isEmpty || z.hasPrefix("#") { continue }
        guard let gleich = z.firstIndex(of: "=") else { continue }
        let schluessel = String(z[z.startIndex..<gleich]).trimmingCharacters(in: .whitespaces)
        var wert = String(z[z.index(after: gleich)...]).trimmingCharacters(in: .whitespaces)
        if wert.count >= 2, wert.hasPrefix("\""), wert.hasSuffix("\"") {
            wert = String(wert.dropFirst().dropLast())
        }
        werte[schluessel] = wert
    }
    return werte
}

let config = ladeConfig(configPfad)
let nurJson = CommandLine.arguments.contains("--json")   // Probelauf: nur ausgeben, nichts schreiben

// Kalenderauswahl (Nutzervorgabe 2026-08-26). Ueber die Konfiguration
// ueberschreibbar, damit ein umbenannter Kalender kein Neubauen erzwingt --
// die Titel tragen Emoji und sind entsprechend wackelig.
let standardKalender = [
    "🔜 Eriks Termine",
    "🅰️ Armins Termine",
    "👨‍❤️‍👨 Gemeinsame Termine",
    "Birthdays",
    "Österreichische Feiertage",
]
// Auswahldatei, die die Admin-Seite schreibt (web/board_settings.php ->
// board_settings_write_calendar_selection()). Sie liegt NEBEN dem Cache in
// data/calendar/ -- dort, wo der Webserver ohnehin schreibt; ~/.config waere
// eine zweite, unnoetige Rechte-Annahme. Fehlt sie oder ist sie leer, gilt
// weiter CALSYNC_CALENDARS bzw. die eingebaute Liste: eine leere Auswahl darf
// NICHT "gar keine Kalender" bedeuten, sonst stuende das Board nach einem
// Fehlgriff in der Oberflaeche stumm da.
func ladeAuswahl(nebenCache ziel: String) -> [String]? {
    let pfad = URL(fileURLWithPath: ziel).deletingLastPathComponent()
        .appendingPathComponent("selection.json")
    guard let daten = try? Data(contentsOf: pfad),
          let namen = try? JSONDecoder().decode([String].self, from: daten) else {
        return nil
    }
    let sauber = namen.map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }
    return sauber.isEmpty ? nil : sauber
}

let gewuenschteKalender: [String] = {
    if let ziel = config["CALSYNC_OUT"], let ausDatei = ladeAuswahl(nebenCache: ziel) {
        return ausDatei
    }
    guard let roh = config["CALSYNC_CALENDARS"], !roh.isEmpty else { return standardKalender }
    return roh.split(separator: "|").map { $0.trimmingCharacters(in: .whitespaces) }
}()

// --- Zugriff ----------------------------------------------------------------

let store = EKEventStore()
let sem = DispatchSemaphore(value: 0)
var erlaubt = false

if #available(macOS 14.0, *) {
    store.requestFullAccessToEvents { ok, _ in erlaubt = ok; sem.signal() }
} else {
    store.requestAccess(to: .event) { ok, _ in erlaubt = ok; sem.signal() }
}
if sem.wait(timeout: .now() + 30) == .timedOut {
    fehler("Zeitueberschreitung beim Zugriffsantrag (wartet ein Dialog?)", EXIT_NOPERM)
}
guard erlaubt else {
    fehler("Kalenderzugriff verweigert -- Systemeinstellungen > Datenschutz & Sicherheit > Kalender", EXIT_NOPERM)
}

// --- Kalender auswaehlen ----------------------------------------------------

let alle = store.calendars(for: .event)
let ausgewaehlt = alle.filter { gewuenschteKalender.contains($0.title) }

// Kein einziger Treffer heisst: umbenannt, entfernt, oder noch nicht
// synchronisiert. Dann NICHT pushen -- eine leere Liste waere hier gelogen.
if ausgewaehlt.isEmpty {
    let vorhanden = alle.map { $0.title }.joined(separator: ", ")
    fehler("keiner der erwarteten Kalender gefunden. Vorhanden: \(vorhanden)", EXIT_NOCAL)
}

// Fehlende einzeln melden, aber weitermachen -- ein entfernter Nebenkalender
// darf den ganzen Push nicht verhindern.
let fehlend = gewuenschteKalender.filter { wunsch in !ausgewaehlt.contains { $0.title == wunsch } }
if !fehlend.isEmpty {
    FileHandle.standardError.write(Data("calsync: Hinweis, nicht gefunden: \(fehlend.joined(separator: ", "))\n".utf8))
}

// --- Termine holen ----------------------------------------------------------

var kalender = Calendar(identifier: .gregorian)
kalender.timeZone = TimeZone(identifier: "Europe/Vienna") ?? .current

// Sieben Tage statt zwei (Nutzerwunsch 2026-09-04: "nicht nur die Termine
// morgen, sondern einfach die naechsten, like Morgen, Sonntag, Montag").
// Das Board zeigt so viele davon, wie auf die Seite passen -- die Auswahl
// trifft der Renderer, nicht dieser Leser. Sieben deckt eine Woche ab und
// bleibt billig: EventKit liefert die fertig aufgeloesten Einzeltermine.
let VORSCHAU_TAGE = 7

let heuteStart = kalender.startOfDay(for: Date())
let fensterEnde = kalender.date(byAdding: .day, value: VORSCHAU_TAGE, to: heuteStart)!

let pred = store.predicateForEvents(withStart: heuteStart, end: fensterEnde, calendars: ausgewaehlt)
let roh = store.events(matching: pred).filter { ev in
    if ev.status == .canceled { return false }
    // Abgelehnte Einladungen gehoeren nicht aufs Board.
    if let teilnehmer = ev.attendees?.first(where: { $0.isCurrentUser }),
       teilnehmer.participantStatus == .declined { return false }
    return true
}

// --- JSON bauen -------------------------------------------------------------

let iso = ISO8601DateFormatter()
iso.formatOptions = [.withInternetDateTime]
iso.timeZone = kalender.timeZone

let tagFmt = DateFormatter()
tagFmt.dateFormat = "yyyy-MM-dd"
tagFmt.timeZone = kalender.timeZone
tagFmt.locale = Locale(identifier: "en_US_POSIX")

struct Termin: Encodable {
    let title: String
    let all_day: Bool
    let start: String?
    let end: String?
}
struct Tag: Encodable {
    let date: String
    let events: [Termin]
}
struct Nutzlast: Encodable {
    let schema: Int
    let generated_at: String
    let timezone: String
    let days: [Tag]
    let available_calendars: [String]
    let selected_calendars: [String]
}

var tage: [Tag] = []
for versatz in 0..<VORSCHAU_TAGE {
    let tagStart = kalender.date(byAdding: .day, value: versatz, to: heuteStart)!
    let tagEnde  = kalender.date(byAdding: .day, value: 1, to: tagStart)!

    let desTages = roh.filter { ev in
        guard let s = ev.startDate else { return false }
        let e = ev.endDate ?? s
        return s < tagEnde && e > tagStart          // ueberlappt diesen Tag
            || (ev.isAllDay && s < tagEnde && e >= tagStart)
    }

    // Deterministische Reihenfolge: ganztaegig zuerst, dann nach Beginn, dann
    // nach Titel. Ohne feste Ordnung entstuende bei jedem Push ein anderes SVG --
    // das wuerde board_frame_diff() aushebeln und unnoetige Vollbilder erzwingen.
    let sortiert = desTages.sorted { a, b in
        if a.isAllDay != b.isAllDay { return a.isAllDay }
        let sa = a.startDate ?? .distantPast, sb = b.startDate ?? .distantPast
        if sa != sb { return sa < sb }
        return (a.title ?? "") < (b.title ?? "")
    }

    tage.append(Tag(
        date: tagFmt.string(from: tagStart),
        events: sortiert.map { ev in
            Termin(
                title: (ev.title ?? "").trimmingCharacters(in: .whitespacesAndNewlines),
                all_day: ev.isAllDay,
                start: ev.startDate.map { iso.string(from: $0) },
                end: ev.endDate.map { iso.string(from: $0) }
            )
        }
    ))
}

let nutzlast = Nutzlast(
    schema: 1,
    generated_at: iso.string(from: Date()),
    timezone: kalender.timeZone.identifier,
    days: tage,
    available_calendars: alle.map { $0.title }.sorted(),
    selected_calendars: ausgewaehlt.map { $0.title }.sorted()
)

let encoder = JSONEncoder()
encoder.outputFormatting = [.sortedKeys]  // stabile Byte-Folge, s. Sortierung oben
guard let json = try? encoder.encode(nutzlast), let jsonText = String(data: json, encoding: .utf8) else {
    fehler("JSON-Kodierung fehlgeschlagen", EXIT_CONFIG)
}

if nurJson {
    print(jsonText)
    exit(0)
}

// --- Schreiben ---------------------------------------------------------------
// Atomar (tmp + rename), wie scripts/weather_fetch_cron.php: ein halb
// geschriebener Cache waere fuer board.php nicht von Muell zu unterscheiden.
//
// received_at stempelt DIESES Programm -- es laeuft auf demselben Rechner wie der
// Renderer, die Veraltungsrechnung vergleicht also dieselbe Uhr mit sich selbst.

guard let ziel = config["CALSYNC_OUT"], !ziel.isEmpty else {
    fehler("CALSYNC_OUT fehlt in \(configPfad)", EXIT_CONFIG)
}

struct Cache: Encodable {
    let schema: Int
    let received_at: String
    let generated_at: String
    let timezone: String
    let days: [Tag]
    // Alle Kalender, die EventKit hier kennt -- NICHT nur die ausgewaehlten.
    // Nur dieses Programm sieht sie; die Admin-Seite kann daraus die
    // Ankreuzliste bauen, statt dass jemand Namen abtippt (Nutzerwunsch
    // 2026-09-04). Mitgeliefert wird auch, was gerade aktiv ist.
    let available_calendars: [String]
    let selected_calendars: [String]
}

let cache = Cache(
    schema: 1,
    received_at: iso.string(from: Date()),
    generated_at: iso.string(from: Date()),
    timezone: kalender.timeZone.identifier,
    days: tage,
    available_calendars: alle.map { $0.title }.sorted(),
    selected_calendars: ausgewaehlt.map { $0.title }.sorted()
)

guard let cacheJson = try? encoder.encode(cache) else {
    fehler("JSON-Kodierung fehlgeschlagen", EXIT_CONFIG)
}

let zielUrl = URL(fileURLWithPath: ziel)
let tmpUrl = zielUrl.appendingPathExtension("tmp")

do {
    try FileManager.default.createDirectory(
        at: zielUrl.deletingLastPathComponent(), withIntermediateDirectories: true)
    try cacheJson.write(to: tmpUrl)
    _ = try FileManager.default.replaceItemAt(zielUrl, withItemAt: tmpUrl)
} catch {
    try? FileManager.default.removeItem(at: tmpUrl)
    fehler("Cache nicht schreibbar (\(ziel)): \(error.localizedDescription)", EXIT_CONFIG)
}

let anzahl = tage.reduce(0) { $0 + $1.events.count }
print("calsync: \(anzahl) Termine aus \(ausgewaehlt.count) Kalendern -> \(ziel)")
