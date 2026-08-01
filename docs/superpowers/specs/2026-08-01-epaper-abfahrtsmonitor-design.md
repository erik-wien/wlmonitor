# E-Paper-Abfahrtsmonitor — Design

**Stand:** 2026-08-01 · entworfen, nicht umgesetzt
**Umfang:** neuer Board-Endpunkt in wlmonitor, serverseitige Favoritenfilterung,
Token-Auth, Härtung von `monitor_json.php`, ESP32-Firmware
**Hardware:** Waveshare 7.5″ e-Paper HAT (B), 800×480, schwarz/weiß/rot, am
Waveshare **e-Paper ESP32 Driver Board** (ESP32-WROVER)

---

## 1. Ziel

Ein Wanddisplay, das ohne Bedienung die nächsten Abfahrten zweier Favoriten
zeigt: **„Arbeit"** und **„➙ Stadt"**. Es aktualisiert sich selbständig alle
zwei Minuten und braucht keinen Server im Haus — es spricht direkt mit
wlmonitor.

Nebenziel: der bestehende, selbstgebaute Endpunkt `monitor_json.php`
(Home-Assistant-Anbindung) wird abgesichert, ohne seine Antwortform zu ändern.

---

## 2. Ausgangslage

`web/monitor_json.php` ist 25 Zeilen alt und für einen Browser gedacht, der
zufällig JSON will. Am 2026-07-31/08-01 nachgemessen:

| Befund | Beleg |
|---|---|
| Jeder anonyme Aufruf legt eine PHP-Session an, Cookie 4 Tage | Sessiondateien 875 → 876 nach einem `curl` |
| Antwort hängt von der Session des Aufrufers ab (`$_SESSION['departures']`) | Code, Zeile 17 |
| Zustandsänderung per GET (`$_SESSION['diva'] = …`) | Code, Zeile 14 |
| Kein Rate-Limiting, obwohl der Kopfkommentar es behauptet | `RATE_LIMIT_FILE` wird definiert, aber nirgends benutzt |
| Interne Ausnahmetexte gehen an jeden, kein Fehlerpfad loggt | HTTP 503 mit `$e->getMessage()`; `api.php` loggt an derselben Stelle |
| Liefert andere Daten als das Web-UI | die Platzhalter für DIVAs ohne Abfahrten fehlen hier |

Dazu ein Datenbefund: in der Antwort für Westbahnhof steht `U3 Steig 1
Simmering` **zweimal**. Ob die WL-API zwei Monitore liefert oder `monitor_get()`
unsauber gruppiert, ist offen — die neue Schicht muss entdoppeln.

**Der Filter ist heute reine Browser-Logik** (`web/js/wl-monitor.js:325-330`).
Der Server speichert `filter_json`, wertet es aber nie aus.

---

## 3. Entscheidungen im Überblick

| Frage | Entscheidung | Begründung |
|---|---|---|
| Takt | **2 min** pollen **und** neu zeichnen | Das (B)-Panel kann kein Partial Refresh; ein Vollbild-Refresh dauert 15–25 s. 30 s wären kürzer als der Refresh selbst. |
| Manueller Auslöser | **keiner** | BOOT ist verbaut nicht erreichbar; ein HC-SR04 kann den ESP32 nicht aus dem Tiefschlaf wecken (er misst nur, wenn der Controller aktiv pingt) und gäbe 5 V auf einen 3,3-V-Eingang. GPIO 32/33 bleiben frei für späteres Nachrüsten. |
| Inhalt | Favoriten des Token-Benutzers, **serverseitig gefiltert** | Ein Gerät kann den Browser-Filter nicht ausführen; sonst entstünde eine dritte Kopie der Regel. |
| Auth | **Bearer-Token** (`erikr/auth`, `auth_api_token_*`) | Bereits vorhanden und über `auth_bootstrap()` verdrahtet; Ausgabe-UI existiert in `web/profil.php`. |
| Alter Endpunkt | bleibt, wird **token-pflichtig** | Home Assistant kann Header senden; die Antwortform bleibt unverändert, HA-Templates bleiben heil. |
| Web-UI-Filter | zieht **später** nach | Das Display-Projekt soll nicht an einem Umbau laufender Oberfläche hängen. |
| Rot | **nächste Abfahrt, wenn Realtime** | Rot trägt damit Information (live vs. Fahrplan) statt Dekoration. |
| Layout | **zwei Spalten**, ein Favorit je Spalte | Einspaltig braucht 572 px, verfügbar sind 404. |

---

## 4. API-Vertrag: `web/board.php`

### Anfrage

```
GET /board.php?fav=<id>[,<id>…]
Authorization: Bearer <token>
```

- **Token-Pflicht.** Ohne gültiges Token: `401`. `auth_bootstrap()` löst das
  Token bereits auf (`auth_apply_api_token()`, akzeptiert auch
  `X-Auth-Token`, weil manche FPM-Setups `Authorization` verschlucken).
- `fav` ist eine Liste von **Favoriten-IDs**, die dem Token-Benutzer gehören
  müssen; fremde IDs werden ignoriert, nicht gemeldet (keine Existenzauskunft
  über fremde Datensätze). Ohne `fav`: alle Favoriten des Benutzers in
  `sort`-Reihenfolge.
- Die ID steht in der URL von `editFavorite.php?id=…`.
- **Keine Sitzung.** Der Endpunkt schreibt nichts in `$_SESSION` und liest
  nichts daraus. Alles, was die Antwort bestimmt, kommt aus dem Token-Benutzer.

### Antwort

```json
{
  "generated": "2026-08-01T19:13:47+02:00",
  "favorites": [
    {
      "id": 12,
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
```

Regeln:

- `favorites` und `stations` sind **Arrays**, keine Maps — die Reihenfolge ist
  die Favoriten- bzw. DIVA-Reihenfolge. (Die alte API mischt Stationen und
  Metadaten in einer flachen Map; daran bin ich beim Entwurf selbst
  hängengeblieben.)
- `in` ist eine **Zahl in Minuten**. „Fährt jetzt" ist `0` — die alte API
  kodiert das als String `"*"`.
- `towards` und `line` **auf Abfahrtsebene** erscheinen nur, wenn sie von der
  Zeile abweichen (Kurzführung, Ersatzverkehr). Client-Regel: *hat eine
  Abfahrt ein eigenes `towards`, endet sie woanders.* Ohne dieses Feld zeigte
  das Display „U6 → Siebenhirten in 7 min", während der Zug in Alterlaa endet
  — eine falsche Zeile ist schlimmer als eine fehlende.
- `realtime` = `false` heißt Fahrplanzeit ohne Echtzeit.
- `type` ist auf `metro | tram | bus | train` normalisiert (statt `ptMetro`,
  `ptTram`, `ptBusCity`, …).
- **Weggelassen:** `direction`, `barrier_free`, `trafficjam`, `api_ping`,
  `trains`. Weder HA noch das Display brauchen sie.
- `generated` ist ISO 8601 **mit Zone**. `"19:13:47"` (alte API) reicht nicht,
  um Veralterung zu erkennen.

### Fehler

| Lage | Status | Körper |
|---|---|---|
| kein/ungültiges Token | 401 | `{"error":"unauthorized"}` |
| WL-API nicht erreichbar / kaputte Antwort | 503 | `{"error":"upstream_unavailable"}` |
| sonstiger Fehler | 500 | `{"error":"server_error"}` |

**Keine internen Texte nach außen.** Die konkrete Ursache geht per
`appendLog($con, 'board', …)` ins `auth_log` — jeder Fehlerpfad loggt
(Fehler-Regeln §21). Heute tut `monitor_json.php` das Gegenteil: es schweigt
im Log und plaudert nach außen.

---

## 5. Serverseitige Filterung

Neue Datei `inc/board.php`, eine Funktion, die aus Favorit + Monitordaten die
sichtbaren Zeilen macht. Sie bildet die Semantik von `wl-monitor.js:325-330`
exakt nach:

1. `filter_json` ist `{ "<diva>": [ {line, platform}, … ], … }` — eine
   **Positivliste je Haltestelle**.
2. Eine DIVA **ohne** Eintrag bedeutet *kein Filter* → alle Linien dieser
   Haltestelle. (Bei den aktuellen Favoriten des Benutzers kommt das nicht
   vor — alle zwölf sind vollständig gefiltert.)
3. Vergleich: `line === l.name && String(platform) === String(l.platform)`.
   Die Identität einer Zeile ist **(Linie, Steig)**, nicht das Fahrtziel:
   Ziele ändern sich bei Kurzführungen, Steige nicht.
4. **Leere gefilterte Karten bleiben stehen**, leere ungefilterte fliegen
   raus. Eine gefilterte Karte, die verschwindet, sähe aus wie „alles in
   Ordnung", obwohl die einzige Linie ausgefallen ist.
5. **Entdopplung:** identische `(diva, line, platform, towards)` erscheinen nur
   einmal (siehe Westbahnhof-Befund oben).

Diese Funktion ist rein — Eingabe: Monitordaten + Filterstruktur, Ausgabe:
gefilterte Struktur. Kein Netz, keine DB, damit direkt testbar.

---

## 6. `monitor_json.php` — Härtung ohne Formänderung

Home Assistant bleibt funktionsfähig; nur die **Anfrage** ändert sich:

- **Token-Pflicht** (`Authorization: Bearer`), sonst 401. HA schickt Header in
  der REST-Integration problemlos mit.
- Kein `$_SESSION`-Zugriff mehr. `maxDep` kommt aus den `wl_preferences` des
  **Token-Benutzers**, nicht aus der Sitzung eines zufälligen Aufrufers.
  Damit ist die Antwort auch reproduzierbar.
- Kein `$e->getMessage()` nach außen; stattdessen `appendLog()`.
- `session_start()` unterbleibt für Token-Anfragen — keine 2.880
  Sessiondateien pro Tag mehr.

Die Antwortstruktur bleibt Byte-für-Byte kompatibel.

---

## 7. Layout (800 × 480)

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ▛▜ WIENER LINIEN                                            Stand 19:13  │  44 px
├────────────────────────────────────┬─────────────────────────────────────┤
│ ARBEIT                             │ ➙ STADT                             │
│  Aßmayergasse                      │  Flurschützstraße                   │
│ ⬤59A 1 Bhf. Meidling S U   4 · 23  │ ◯62  2 Quartier Belvedere  2 · 14   │
│ ◯62  1 Lainz, Wolkersberg… 1 · 10  │  Hans-Mandl-Berufsschule            │
│  Hans-Mandl-Berufsschule           │ ⬤59A 2 Oper, Karlsplatz U  ✱ · 3    │
│ ⬤59A 1 Bhf. Meidling S U   2 · 21  │  Längenfeldgasse U                  │
│  Niederhofstraße U                 │ ▪U4  1 Landstraße          1 · 6    │
│ ▪U6  2 Siebenhirten        1 · 6   │ ▪U6  1 Floridsdorf         ✱ · 4    │
│  Bhf. Meidling S U                 │                                     │
│ ▪U6  2 Siebenhirten        2 · 8   │                                     │
└──────────────────────────────────────────────────────────────────────────┘
```

**Platzbudget** (gemessen an den echten Favoriten):

| | Zeilen | Stationen | Höhe |
|---|---|---|---|
| Arbeit | 6 | 4 | 316 px |
| ➙ Stadt | 5 | 3 | 256 px |
| einspaltig zusammen | | | 572 px ✗ |
| zweispaltig, je Spalte | | | 316 / 256 px ✓ (404 verfügbar) |

**Farben** (das Panel kann nur Schwarz, Weiß, Rot):

| Element | Darstellung |
|---|---|
| nächste Abfahrt, Realtime | **rot, groß** |
| nächste Abfahrt, nur Fahrplan | schwarz, groß, **kursiv** |
| gestörte Abfahrt | **weiß auf rotem Block** (invertiert) |
| folgende Abfahrt | klein, schwarz |
| `"in": 0` („fährt jetzt") | als `✱` gezeichnet, in der Farbe der Zeile |
| Logo `#e3000f` / `#240c4b` | rot / schwarz |
| alles Übrige | schwarz |

Rot ist damit doppelt belegt, aber unverwechselbar: rote Ziffern auf Weiß =
live, weiße Ziffern auf Rot = gestört. Ist die nächste Abfahrt beides, gewinnt
die Invertierung.

**Störungstexte werden nicht angezeigt.** Sie sind lang („U4: Bauarbeiten …
Voraussichtliche Dauer: Montag, 03. August 2026, 04:00 Uhr … "), und eine
Erklärung, die den Platz der Information wegnimmt, ist auf 800×480 die falsche
Wahl. Die Störung ist an der invertierten Abfahrt erkennbar; das Warum steht
in der Web-App.

**Weiteres:**

- Zielnamen werden bei Überlänge mit `…` gekürzt (400 px Spaltenbreite).
  Linie und Steig stehen davor und bleiben immer lesbar.
- Haltestellen-Zwischenüberschriften schmal (22 px) — bei mehreren DIVAs je
  Favorit beantworten sie „von wo fährt das eigentlich".
- Das Logo ist 561 × 132 (**4,3 : 1**). Über die volle Breite wären es 187 px
  Höhe, mehr als ein Drittel des Displays. Es wird auf ~240 × 56 px in die
  Kopfzeile gesetzt und beim Bauen einmalig in eine Zwei-Farb-Bitmap
  umgerechnet (ein ESP32 rendert kein SVG).
- „Stand HH:MM" ist Pflicht, nicht Zierde: E-Paper hält das letzte Bild auch
  ohne Strom und ohne Netz. Ohne Zeitstempel hält man Stunden alte Daten für
  aktuell.

---

## 8. Firmware

**Ablauf je Zyklus:** aufwachen → WLAN → `GET /board.php` → zeichnen →
Tiefschlaf (2 min). Kein Dauerbetrieb mit Timer. Diese Form ist einfacher als
eine Schleife **und** hält den späteren Akku-Versuch offen (~10 µA statt
40–80 mA); am Netz verhält sie sich identisch.

- **Ort:** `firmware/` im wlmonitor-Repo — API und Client entwickeln sich
  gemeinsam, eine Spec, ein Repo. Muss in `scripts/ssh_deploy.php` von der
  Deploy-Synchronisierung ausgeschlossen werden.
- **Pins** (vom Treiberboard fest verdrahtet): SCLK 13 · DIN 14 · CS 15 ·
  BUSY 25 · RST 26 · DC 27. Frei und RTC-fähig bleiben u. a. 32 (T9) und
  33 (T8) für einen späteren Auslöser.
- **Bibliothek:** GxEPD2 (unterstützt das 7.5″-B-Panel) + Adafruit_GFX.
- **Schriften:** eine Größe für Abfahrtszeiten, eine kleinere für Ziele, dazu
  eine **kursive** Variante für Fahrplanzeiten — als separate
  `fontconvert`-Ausgabe eingebettet.
- **Keine Uhr nötig.** Der Stand-Zeitstempel kommt aus `generated` der
  Antwort; NTP entfällt.
- **Zugangsdaten** (WLAN, Token, Favoriten-IDs) in `firmware/config.h`, die
  **nicht** ins Repo kommt (`config.example.h` als Vorlage, `.gitignore`).
- **TLS:** wlmonitor läuft über HTTPS. Das Wurzelzertifikat wird eingebettet.
  Risiko: läuft es aus, schweigt das Display. Deshalb Punkt 9.

## 9. Fehlerfälle

E-Paper hat eine Eigenheit, die man aktiv behandeln muss: **bei jedem Fehler
bleibt das letzte gültige Bild stehen** und sieht aus wie frische Daten.

| Lage | Verhalten |
|---|---|
| WLAN nicht verfügbar | Bild bleiben lassen, nächster Zyklus. Nach 3 Fehlversuchen: Kopfzeile zeigt „⚠ offline seit HH:MM" |
| HTTP 401 | „⚠ Token ungültig" — das behebt sich nicht von selbst, also sofort anzeigen |
| HTTP 503 / Zeitüberschreitung | wie WLAN-Ausfall |
| Antwort unlesbar | wie 503, zusätzlich Zähler |
| Stand älter als 15 min | Zeitstempel invertiert (weiß auf rot) |

Das Display lügt damit nie: entweder es zeigt frische Daten, oder es sagt, dass
es das nicht tut.

---

## 10. Tests

- `inc/board.php` — reine Filterfunktion, gegen Beispieldaten: Positivliste
  greift, DIVA ohne Eintrag = alle, leere gefilterte Karte bleibt, leere
  ungefilterte fliegt, Duplikate entdoppelt.
- **Gegenprobe zur Browser-Implementierung:** dieselben Beispieldaten durch
  `wl-monitor.js` und `inc/board.php`; das Ergebnis muss übereinstimmen. Das
  ist die Absicherung des Divergenzfensters, solange beide existieren.
- `board.php` — Endpunkttest: ohne Token 401, fremde Favoriten-ID wird
  ignoriert, keine `Set-Cookie`-Antwort, kein interner Text im Fehlerkörper.
- `monitor_json.php` — Antwortform vor/nach der Härtung identisch (Absicherung
  für Home Assistant).

## 11. Nicht Teil dieses Entwurfs

- Umstellung des Web-UI auf die Serverfilterung (später, eigener Schritt).
- Zwischenspeichern der WL-Antwort. Gemessen: der Aufruf dauert 330–510 ms und
  dominiert die Antwortzeit. Das ist ein eigenes Thema — und **nicht** der
  Grund für die Serverfilterung: die macht die Web-Anzeige nicht schneller
  (der Filter selbst kostet Mikrosekunden, und gzip drückt 22 KB auf 1,4 KB).
- Akkubetrieb: Ladeelektronik, Spannungsüberwachung, Intervall-Anpassung.
- Ein manueller Auslöser (Taster oder Touch auf GPIO 32/33).
- Die Ursache der doppelten `U3`-Zeile in den WL-Daten — die neue Schicht
  entdoppelt, klärt aber nicht, woher es kommt.
