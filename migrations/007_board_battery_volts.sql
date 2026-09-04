-- Akku-Kalibrierung auf ECHTE SPANNUNG umstellen (Nutzerbefund 2026-09-04:
-- "Sie ueber die % zu kalibrieren war auch ein Bloedsinn, das sollten wir
-- ueber die echten Volt machen").
--
-- Warum die alten Felder Unsinn waren: das Geraet schickt Millivolt
-- (X-Device-Battery-mV). board_battery_percent_from_mv() rechnete die mit
-- einer FEST VERDRAHTETEN Spanne 3300..4200 mV in Prozent um, und erst auf
-- dieses abgeleitete Prozent wirkten battery_charging_threshold /
-- battery_full_threshold. Kalibriert wurde also eine Zahl, deren Herleitung
-- selbst nie kalibriert war -- zwei Stellschrauben hinter einer dritten,
-- unsichtbaren.
--
-- Jetzt drei Spannungen, an denen direkt gemessen werden kann:
--   battery_empty_mv    hier wird 0 % angezeigt   (ersetzt die harte 3300)
--   battery_full_mv     hier wird 100 % angezeigt (ersetzt die harte 4200
--                       UND battery_full_threshold -- "92-94 % sind in
--                       Wahrheit voll" faellt weg, wenn die Spanne stimmt)
--   battery_charging_mv ab hier Blitz statt Wert  (ersetzt
--                       battery_charging_threshold)
-- Prozent = (gemessen - empty) / (full - empty) * 100, geklemmt auf 0..100.
--
-- Vorgaben entsprechen dem bisherigen Verhalten, in Spannung zurueckgerechnet:
-- 92 % der alten Spanne = 3300 + 0,92*900 = 4128 mV, 95 % = 4155 mV.
--
-- battery_display_mode: Nutzerwunsch "eine Schalterpille ob am Display % oder
-- Volt angezeigt werden sollen". Der Balken fuellt sich in beiden Faellen nach
-- Prozent -- ein Balken ist ein Balken; nur die Beschriftung wechselt.
--
-- DB chosen by connection (db.name in config.yaml) — do not add USE here.

ALTER TABLE `wl_board_settings`
  ADD COLUMN `battery_empty_mv`    smallint(5) unsigned NOT NULL DEFAULT 3300 AFTER `wifi_hidden`,
  ADD COLUMN `battery_full_mv`     smallint(5) unsigned NOT NULL DEFAULT 4128 AFTER `battery_empty_mv`,
  ADD COLUMN `battery_charging_mv` smallint(5) unsigned NOT NULL DEFAULT 4155 AFTER `battery_full_mv`,
  ADD COLUMN `battery_display_mode` enum('percent','volt') NOT NULL DEFAULT 'percent' AFTER `battery_charging_mv`;

-- BESTEHENDE Kalibrierung mitnehmen, nicht auf die Vorgaben zuruecksetzen.
-- Auf akadbrain standen 93 % / 89 %, nicht 95 / 92 -- ohne diesen Schritt
-- waere die von Hand ermittelte Kalibrierung beim Migrieren still verloren.
-- Umrechnung mit genau der Formel, die board_battery_percent_from_mv() bisher
-- fest verdrahtet hatte: mV = 3300 + Prozent * (4200-3300)/100.
UPDATE `wl_board_settings`
   SET `battery_full_mv`     = 3300 + ROUND(`battery_full_threshold` * 9),
       `battery_charging_mv` = 3300 + ROUND(`battery_charging_threshold` * 9)
 WHERE `battery_full_threshold` > 0
   AND `battery_charging_threshold` > 0;

-- Die alten Prozentfelder liest nach dieser Migration niemand mehr. Stehen
-- lassen hiesse: eine Admin-Seite, die sie nicht mehr zeigt, und eine Spalte,
-- die beim naechsten Lesen des Schemas Fragen aufwirft.
ALTER TABLE `wl_board_settings`
  DROP COLUMN `battery_charging_threshold`,
  DROP COLUMN `battery_full_threshold`;

-- ACHTUNG akadbrain: deploy.py fuehrt hier GAR KEINE Migration aus (s. den
-- ausfuehrlichen Hinweis am Ende von 006_wl_board_settings.sql). Von Hand:
--   ssh erik@akadbrain "/opt/homebrew/bin/mysql -u <user> -p<pass> jardyx \
--     < /Library/WebServer/Documents/wlmonitor/migrations/007_board_battery_volts.sql"
--
-- Kein zusaetzlicher GRANT noetig: die Rechte haengen an der TABELLE
-- wl_board_settings, nicht an einzelnen Spalten (s. 006).
