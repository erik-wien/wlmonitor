---
id: TASK-13
title: 'OGD-Update: Fortschritt/Dauer-Hinweis statt statischem ''Verbinde...'''
status: To Do
assignee: []
created_date: '2026-07-16 06:59'
labels: []
dependencies: []
priority: medium
ordinal: 5000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Problem

**[MITTEL] OGD-Stationsdaten-Update läuft als ein synchroner Request ohne echten Fortschritt
(§20-nah)**

Datei: `web/admin.php:274-296` (Button-Handler), `web/api.php:285-292` (`admin_ogd_update`),
`inc/ogd.php:70-192` (`ogd_update`). Ein Klick auf „Jetzt aktualisieren" löst einen einzigen
Request aus, der serverseitig 3 CSVs herunterlädt und alle Zeilen einzeln per prepared statement
in 3 Tabellen einfügt (`inc/ogd.php:90-156`), mit `set_time_limit(120)` (`api.php:288`). Das
Frontend zeigt während der gesamten Laufzeit nur die statische Meldung "Verbinde..."
(`admin.php:282`) — der Log (`$log`-Array, `inc/ogd.php:71-182`) wird erst nach Abschluss des
kompletten Requests auf einmal ausgegeben, kein Zwischenstand ("X/Y Zeilen").

## Auswirkung

Bei einer langsameren `data.wien.gv.at`-Antwort oder vielen Haltestellen-Zeilen sieht der Admin
minutenlang nur "Verbinde...", ohne zu wissen, ob der Vorgang überhaupt läuft. Bricht der Request
wegen eines Proxy-/Browser-Timeouts vor Rückgabe ab, zeigt der Client "Netzwerkfehler beim
OGD-Update" (`admin.php:290-291`), obwohl das Update serverseitig (Lock + Transaktion) ggf.
bereits erfolgreich durchgelaufen ist — der Admin kann das nicht unterscheiden.

## Empfehlung

Sofern die CSV-Größen das rechtfertigen, den Ablauf in Teilschritte mit Client-Zwischenstatus
zerlegen (z.B. ein Status-Endpoint, den das Frontend während des Laufs pollt, oder zumindest den
`$log`-Array-Aufbau streamen statt am Ende komplett zurückzugeben). Minimal: klareres Hinweistext
("Kann bis zu 2 Minuten dauern, bitte Seite nicht schließen") statt nur "Verbinde...", damit ein
Timeout nicht als Fehlschlag missverstanden wird.

## Acceptance Criteria

- [ ] Mindestlösung: Der Button-Handler in `web/admin.php:274-296` zeigt während des Laufs einen
      Hinweistext, der die mögliche Dauer benennt ("Kann bis zu 2 Minuten dauern, bitte Seite
      nicht schließen") statt nur "Verbinde...".
- [ ] Bei einem Proxy-/Browser-Timeout unterscheidet die Fehlermeldung klar zwischen "Anfrage
      abgebrochen, Update läuft ggf. serverseitig weiter" und einem echten Fehlschlag — nicht
      pauschal "Netzwerkfehler beim OGD-Update".
- [ ] Optional (falls im Zuge umgesetzt): Ein Status-Endpoint liefert Zwischenstand ("X/Y Zeilen")
      während `ogd_update()` läuft, den das Frontend pollt, analog dem last.fm-Sync-Status-Muster.
- [ ] Bestehendes Lock-/Transaktionsverhalten von `ogd_update()` bleibt unverändert.
- [ ] Bestehende Tests bleiben grün.
<!-- SECTION:DESCRIPTION:END -->
