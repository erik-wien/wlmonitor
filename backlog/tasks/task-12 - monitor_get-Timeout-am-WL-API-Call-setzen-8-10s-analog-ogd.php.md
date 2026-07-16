---
id: TASK-12
title: 'monitor_get(): Timeout am WL-API-Call setzen (8-10s, analog ogd.php)'
status: To Do
assignee: []
created_date: '2026-07-16 06:59'
labels: []
dependencies: []
priority: medium
ordinal: 4000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Problem

**[MITTEL] Kein Timeout beim WL-Realtime-API-Aufruf**

Datei: `inc/monitor.php:75` (`$raw = file_get_contents($apiUrl);`). Im Gegensatz zu
`inc/ogd.php:48` (`stream_context_create(['http' => ['timeout' => 30]])`) wird hier kein
Stream-Context/Timeout gesetzt. Bei einer hängenden WL-API blockiert der PHP-Worker bis zum
Default (`default_socket_timeout`, meist 60s) — währenddessen hängt der alle-20s-Poll-Request im
Frontend ohne jede Fortschrittsanzeige.

**[NIEDRIG, ergänzend] CSV-Downloadfehler ohne HTTP-Detail**

Datei: `inc/ogd.php:47-52` (`ogd_download_csv`). Bei
`file_get_contents($url, false, $ctx) === false` wird nur `"CSV download failed: $url"` geworfen —
kein HTTP-Status/`$http_response_header`, keine Unterscheidung 404 vs. DNS-Fehler vs. Timeout.

## Auswirkung

Ein lange hängender Request kann den 20s-Refresh-Zyklus überlappen lassen bzw. das UI für bis zu
einer Minute ohne sichtbare Rückmeldung "einfrieren" (keine Spinner/Text), bevor die generische
Fehlermeldung erscheint.

Admin sieht im OGD-Log nur, *dass* ein Download fehlschlug, nicht *warum*.

## Empfehlung

Denselben Timeout-Ansatz wie in `ogd_download_csv` (kurzer, expliziter Timeout, z.B. 8-10s —
passend zum 20s-Poll-Intervall) auch für den Monitor-Call setzen.

`$http_response_header` (sofern gesetzt) oder `error_get_last()` in die Exception-Message von
`ogd_download_csv` aufnehmen.

## Acceptance Criteria

- [ ] `monitor_get()` (`inc/monitor.php:75`) setzt einen expliziten Stream-Context-Timeout von
      8-10s beim `file_get_contents()`-Aufruf gegen die WL-API, analog `inc/ogd.php:48`.
- [ ] Bei Timeout wirft `monitor_get()` eine aussagekräftige `RuntimeException`
      ("Wiener Linien API request failed" o.ä., wie bereits vorhanden), statt bis zum
      PHP-Default-Socket-Timeout zu blockieren.
- [ ] `ogd_download_csv()` nimmt `$http_response_header` (sofern gesetzt) oder
      `error_get_last()` in die Exception-Message auf, statt nur "CSV download failed: $url".
- [ ] Bestehende Tests bleiben grün.
<!-- SECTION:DESCRIPTION:END -->
