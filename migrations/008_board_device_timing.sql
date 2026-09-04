-- Zeitverhalten des E1003 vom Server steuerbar machen (Nutzerwunsch
-- 2026-09-04: "Die Einschlaf-Frist gehoert auch in board settings btw").
--
-- Bisher standen alle vier Werte als Konstanten in der Firmware
-- (epaper-monitor: ACTIVE_IDLE_TIMEOUT_MS, REFRESH_INTERVAL_MS,
-- WAKE_SCHEDULE_INTERVAL_SECONDS, WAKE_SCHEDULE_START_HOUR) -- jede Aenderung
-- kostete also einen Neubau UND ein Flashen am Kabel. Das ist fuer Werte, die
-- man durch Beobachten kalibriert ("wie lange will ich davorstehen"), der
-- falsche Aufwand.
--
-- Alle VIER gemeinsam, nicht nur die Einschlaf-Frist: die Firmware muss
-- ohnehin einmal angefasst und geflasht werden, und es waere unsinnig, drei
-- verwandte Werte dabei hart im Code zu lassen und beim naechsten Wunsch
-- dasselbe noch einmal zu machen.
--
--   device_idle_timeout_sec       nach so langer Untaetigkeit schlafen (war 600)
--   device_refresh_interval_sec   Nachladen waehrend der Aktiv-Session (war 25)
--   device_wake_interval_sec      Abstand der automatischen Weckungen (war 3600)
--   device_quiet_start_hour       Beginn der Ruhezeit (war implizit 0)
--   device_quiet_end_hour         Ende der Ruhezeit (war 6)
--
-- Zur Ruhezeit: die Vorgaben 0..6 geben GENAU das bisherige Verhalten wieder.
-- Ein geplantes Aufwachen, das in die Ruhezeit fiele, entfaellt ersatzlos --
-- bei stuendlichem Intervall ist deshalb faktisch schon ab 23:00 Ruhe, denn
-- der Weckpunkt um 00:00 liegt bereits darin. Genau so rechnete die alte
-- Firmware auch, nur ohne dass es einstellbar war.
--
-- start == end heisst "keine Ruhezeit" (rund um die Uhr wecken).
--
-- DB chosen by connection (db.name in config.yaml) — do not add USE here.

ALTER TABLE `wl_board_settings`
  ADD COLUMN `device_idle_timeout_sec`     smallint(5) unsigned NOT NULL DEFAULT 600  AFTER `battery_display_mode`,
  ADD COLUMN `device_refresh_interval_sec` smallint(5) unsigned NOT NULL DEFAULT 25   AFTER `device_idle_timeout_sec`,
  ADD COLUMN `device_wake_interval_sec`    smallint(5) unsigned NOT NULL DEFAULT 3600 AFTER `device_refresh_interval_sec`,
  ADD COLUMN `device_quiet_start_hour`     tinyint(2)  unsigned NOT NULL DEFAULT 0    AFTER `device_wake_interval_sec`,
  ADD COLUMN `device_quiet_end_hour`       tinyint(2)  unsigned NOT NULL DEFAULT 6    AFTER `device_quiet_start_hour`;

-- ACHTUNG akadbrain: deploy.py fuehrt hier GAR KEINE Migration aus (s. den
-- ausfuehrlichen Hinweis am Ende von 006_wl_board_settings.sql). Von Hand,
-- als root ueber sudo (root@localhost nutzt dort das unix_socket-Plugin,
-- authentifiziert sich also ueber den Betriebssystem-Benutzer, nicht ueber
-- ein Passwort -- 2026-09-04 so verifiziert):
--   ssh -t erik@akadbrain 'sudo /opt/homebrew/bin/mysql -u root jardyx \
--     < /Library/WebServer/Documents/wlmonitor/migrations/008_board_device_timing.sql'
--
-- Kein zusaetzlicher GRANT noetig: die Rechte haengen an der TABELLE
-- wl_board_settings, nicht an einzelnen Spalten (s. 006).
