---
id: TASK-11
title: >-
  Live-Board: bei Poll-Fehler letzte gültige Daten stehen lassen + Server-Detail
  zeigen (Adoption apiCall())
status: Done
assignee: []
created_date: '2026-07-16 06:59'
updated_date: '2026-07-16 10:43'
labels: []
dependencies: []
priority: high
ordinal: 3000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Problem

**[HOCH] Frontend verwirft Server-Detail und räumt das Live-Board leer statt zu behalten**

Datei: `web/js/wl-monitor.js:50-57` (`apiFetch`/`apiPost`), `:71-83` (`loadMonitor`).

`apiFetch`/`apiPost` werfen bei `!res.ok` sofort `new Error('API '+action+' failed: '+res.status)`,
**ohne** `res.json()` zu lesen — das vom Server mitgelieferte `{"error": "..."}` (das z.B. bei
`monitor_json.php` die konkrete WL-API-Fehlermeldung trägt) geht komplett verloren.
`loadMonitor()`s catch überschreibt daraufhin unbedingt
`container.textContent = 'Keine Abfahrtsdaten verfügbar.'` — auch bei jedem der alle 20s
laufenden Hintergrund-Refreshes (`startMonitorTimer`, Zeile 535-538). Ein einzelner
Hänger/Timeout der WL-API während des Pollings löscht damit die zuletzt erfolgreich angezeigten
(noch gültigen) Abfahrtszeiten und ersetzt sie durch eine nichtssagende Meldung.

**[MITTEL, ergänzend] `api.php`-Action `monitor` maskiert die konkrete Fehlermeldung, die
serverseitig bereits vorliegt**

Datei: `web/api.php:115-125` (kein eigener try/catch um `monitor_get`, fällt in den äußeren Catch
`:322-325`), im Vergleich zu `web/monitor_json.php:19-25`. `monitor_get()` wirft aussagekräftige
`RuntimeException`s ("Wiener Linien API request failed.", "Invalid JSON from API: …", "No
monitors found for the given DIVA numbers."). `monitor_json.php` gibt `$e->getMessage()` 1:1 im
JSON zurück (gutes Verhalten). Die von `wl-monitor.js` genutzte `api.php`-Action landet dagegen im
generischen äußeren `catch (Throwable $e)`, der zwar korrekt
`appendLog($con, 'api', 'Error: '.$e->getMessage())` loggt, dem Client aber nur
`{'error': 'Internal server error'}` (Status 500) liefert — die im selben Codepfad bereits
vorhandene konkrete Ursache wird für den eingeloggten Haupt-Client weggeworfen, obwohl der
öffentliche HA-Feed sie zeigt.

## Auswirkung

Bei einer kurzen WL-API-Störung sieht der Nutzer sein zuvor korrekt angezeigtes Live-Board
plötzlich verschwinden und durch "Keine Abfahrtsdaten verfügbar." ersetzt — ohne zu wissen, ob das
an WL, am eigenen Netz oder an einem Bug liegt, und ohne dass die alten (noch brauchbaren) Zeiten
stehen bleiben.

Zwei Endpunkte, zwei Fehlerqualitäten — der eingeloggte Nutzer (Web-UI) bekommt bei genau demselben
WL-Ausfall weniger Information als ein unauthentifizierter Home-Assistant-Client.

## Empfehlung

`apiFetch`/`apiPost` immer `res.json()` versuchen (mit Fallback bei kaputtem Body, wie es
`css_library/js/admin.js:40-55`'s `adminPost()` bereits vorbildlich macht) und den `error`-Text
anzeigen. Bei Refresh-Fehlern die zuletzt gerenderte Ansicht stehen lassen und nur einen dezenten
Hinweis ("Aktualisierung fehlgeschlagen, zeige letzten Stand") einblenden statt den Container zu
leeren.

`case 'monitor'` in `api.php` analog zu `monitor_json.php` behandeln — konkrete Meldung +
passenden Statuscode (503) statt der generischen 500/„Internal server error" durchreichen (ggf.
optisch reduziert, aber inhaltlich gleich).

**Empfohlener Weg: Adoption der geteilten `apiCall()`-Hülle** (siehe
`/Users/erikr/TUEV/audit-robustheit-20260716/spec-apicall.md`) für `apiFetch`/`apiPost` statt
Eigenbau — liest bei `!res.ok` den JSON-Body, liefert Status/`detail`/`kind` strukturiert.

**Abhängigkeit:** Diese Task kann erst umgesetzt werden, wenn die für ~/Git-Apps vorgesehene
Instanz `~/Git/css_library/js/api-call.js` gebaut ist (siehe Spec, Abschnitt "Zwei Instanzen,
getrennte Ökosysteme"). Bis dahin kann als Zwischenlösung `adminPost()`-artiges Verhalten direkt
in `apiFetch`/`apiPost` nachgebaut werden; endgültig sollte auf `apiCall()` migriert werden.

## Acceptance Criteria

- [ ] `apiFetch`/`apiPost` (`web/js/wl-monitor.js:50-57`) versuchen bei jeder Antwort
      `res.json()` (mit Fallback bei kaputtem/nicht-JSON-Body) und geben den `error`-Text mit —
      statt bei `!res.ok` sofort nur mit dem HTTP-Status zu werfen.
- [ ] `loadMonitor()` lässt bei einem Refresh-Fehler die zuletzt erfolgreich gerenderten
      Abfahrtszeiten stehen und zeigt stattdessen einen dezenten Hinweis
      ("Aktualisierung fehlgeschlagen, zeige letzten Stand") statt den Container zu leeren.
- [ ] `case 'monitor'` in `web/api.php` gibt bei einem Fehler aus `monitor_get()` die konkrete
      Exception-Message + Status 503 zurück, analog `web/monitor_json.php:19-25`, statt in den
      generischen 500/"Internal server error"-Catch zu fallen.
- [ ] Falls `apiCall()` noch nicht existiert: Task bleibt blockiert bzw. nutzt eine
      Übergangslösung; Migration auf `apiCall()` nachziehen, sobald `css_library/js/api-call.js`
      verfügbar ist.
- [ ] Bestehende Tests bleiben grün; ggf. neuer Test für "alte Daten bleiben stehen bei
      Poll-Fehler".
<!-- SECTION:DESCRIPTION:END -->
