-- Board-Einstellungen (TASK-27): Gaeste-WLAN, Akku-Kalibrierung,
-- MQTT-Sender-Credentials -- bisher hart im Code/in Dateien (data/guest_wifi.json,
-- inc/board.php-Konstanten, web/mqtt/index.php-Konstanten), jetzt ueber eine
-- Admin-Seite pflegbar. Single-Row-Tabelle (id=1 fix), analog zu einer
-- Konfigurationszeile statt Key-Value, weil die Feldzahl klein und stabil ist.
--
-- Passwortfelder starten LEER, nicht mit den aktuell hartcodierten Werten --
-- die wuerden sonst in dieser (versionierten) Migration landen. Solange
-- mqtt_sender_password leer ist, faellt web/mqtt/index.php auf die alte
-- Konstante zurueck (Uebergang bis zum ersten Speichern in der neuen UI).
-- DB chosen by connection (db.name in config.yaml) — do not add USE here.

CREATE TABLE IF NOT EXISTS `wl_board_settings` (
  `id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `wifi_ssid` varchar(64) DEFAULT NULL,
  `wifi_password` varchar(128) DEFAULT NULL,
  `wifi_encryption` varchar(10) NOT NULL DEFAULT 'WPA',
  `wifi_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `battery_charging_threshold` tinyint(3) unsigned NOT NULL DEFAULT 95,
  `battery_full_threshold` tinyint(3) unsigned NOT NULL DEFAULT 92,
  `mqtt_sender_user` varchar(64) NOT NULL DEFAULT 'sender',
  `mqtt_sender_password` varchar(128) NOT NULL DEFAULT '',
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `wl_board_settings` (`id`) VALUES (1);

-- ACHTUNG akadbrain: deploy.py fuehrt hier GAR KEINE Migration aus.
-- do_deploy() ruft run_migrations() nur, wenn scripts/ssh_deploy.php FEHLT
-- (existiert bei wlmonitor), und deploy_rsync_ssh() delegiert an dieses
-- Skript nur bei gesetztem deploy.<target>.ftp_base_dir (nur world4you).
-- Diese Datei muss auf akadbrain also VON HAND eingespielt werden:
--   ssh erik@akadbrain "/opt/homebrew/bin/mysql -u <user> -p<pass> jardyx \
--     < /Library/WebServer/Documents/wlmonitor/migrations/006_wl_board_settings.sql"
-- Ohne das liefert board_settings_load() nur Vorgabewerte (es faengt die
-- Exception bewusst ab, damit das E-Paper-Board nicht auf 503 faellt) --
-- die Admin-Seite speichert dann aber ins Leere.
--
-- Nicht Teil der automatisierten Migration (Nutzer/Host je Umgebung
-- verschieden, s. mcp/config.yaml): pro Zielumgebung zusaetzlich ausfuehren
--   GRANT SELECT, INSERT, UPDATE, DELETE ON <db>.wl_board_settings TO '<app_user>'@'<host>';
-- Ohne den Grant scheitert jeder Zugriff mit "command denied" trotz
-- erfolgreicher CREATE TABLE (per-Tabelle-Rechte-Modell dieses Projekts,
-- kein ALL PRIVILEGES fuer den App-User).
