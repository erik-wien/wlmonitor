---
id: TASK-24
title: 'Statusseite: last_success_ts ergaenzen, OGD-Quelle und Datenfrische pruefen'
status: To Do
assignee: []
created_date: '2026-07-28 08:55'
labels: []
dependencies: []
priority: medium
ordinal: 16000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Zwei verifizierte Befunde aus dem Statusseiten-Audit (2026-07-28). Zwei weitere Verdachtsmomente aus demselben Audit haben sich beim Nachpruefen ALS FALSCH ERWIESEN und sind hier bewusst festgehalten, damit sie nicht wieder aufkommen.

1) KEIN EINZIGER CHECK LIEFERT last_success_ts. Alle drei Checks geben nur state und detail zurueck: 'Datenbank' (web/status.php:43, ueber Status::dbCheck, das last_success_ts grundsaetzlich nie setzt), 'Wiener-Linien-Echtzeit-API' (Zeile 95) und 'OGD-Stammdaten' (Zeilen 112-116). Suite-Policy §5 verlangt den Zeitstempel des letzten Erfolgs fuer alle Nutzer. Auf der Seite steht deshalb nie 'zuletzt ok: ...' - man sieht einen gruenen Punkt, aber nicht, ob er von jetzt oder von der letzten gecachten Antwort stammt.

2) DIE OGD-QUELLE SELBST WIRD NICHT GEPRUEFT, UND FRISCHE AUCH NICHT. Der Check 'OGD-Stammdaten' zaehlt Zeilen in ogd_haltestellen. Die Stammdaten kommen aber per CSV von data.wien.gv.at (inc/ogd.php:9-12, OGD_CSV_URLS) - ein von wienerlinien.at voellig getrennter Host, der nirgends geprueft wird. Und ein Zeilenzahl-Check kann einen monatealten Stand nicht von einem frischen unterscheiden. Der Code dokumentiert das offen (status.php:15-22: die STAND-Spalte wird nicht zuverlaessig befuellt, daher kein Reload-Zeitstempel) - ehrlich, aber die Luecke bleibt. Zu klaeren ist, woran sich Frische ueberhaupt festmachen laesst; ohne belastbares Signal ist ein zusaetzlicher Erreichbarkeits-Check auf data.wien.gv.at der ehrlichere Teilschritt.

WIDERLEGT, NICHT VERFOLGEN:
- 'Der Check fragt ogd_stations ab, einen VIEW, und misst deshalb nicht was er behauptet.' Falsch: ogd_stations ist zwar ein View (inc/ogd.php:174), kommt in web/status.php aber ueberhaupt nicht vor. Der Check liest ogd_haltestellen - eine echte Tabelle (status.php:101).
- 'Die Auth-DB wird nicht geprueft.' Falsch fuer wlmonitor: es gibt nur EINE Verbindung (inc/initialize.php:68), Auth-Tabellen werden ueber AUTH_DB_PREFIX cross-DB auf derselben Verbindung angesprochen. Der DB-Ping deckt den Auth-Pfad also mit ab. Restrisiko nur bei einem rein grant-seitigen Ausfall des auth-Schemas - kein eigener Task wert.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Alle drei Checks liefern last_success_ts, sodass die Seite bei jedem 'zuletzt ok: ...' anzeigt
- [ ] #2 Die CSV-Quelle data.wien.gv.at hat einen eigenen Erreichbarkeits-Check (Timeout <= 3 s, HTTP >= 400 = Fehler)
- [ ] #3 Es ist entschieden und im Code begruendet, ob sich die Frische der OGD-Stammdaten belastbar messen laesst - wenn nein, sagt der Detailtext das ausdruecklich, statt Aktualitaet zu suggerieren
<!-- AC:END -->
