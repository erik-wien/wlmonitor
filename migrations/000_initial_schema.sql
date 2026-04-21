-- 000_initial_schema.sql
-- Canonical base schema for the `wlmonitor` app DB.
-- Idempotent: CREATE TABLE IF NOT EXISTS / CREATE OR REPLACE VIEW / DROP VIEW IF EXISTS.
-- For a clean-slate install this is the only file that needs to run.
-- Deltas 001_*.sql..005_*.sql remain as historical artifacts and should not
-- be applied on top of this file.
--
-- DB: wlmonitor  (use `mariadb -uroot wlmonitor < 000_initial_schema.sql`)
-- FK target: auth.auth_accounts (the shared auth DB, NOT jardyx_auth).

USE wlmonitor;

/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `db_migrations` (
  `name` varchar(255) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ogd_haltestellen` (
  `HALTESTELLEN_ID` int(9) NOT NULL,
  `TYP` varchar(4) DEFAULT NULL,
  `DIVA` int(8) DEFAULT NULL,
  `NAME` varchar(80) NOT NULL,
  `GEMEINDE` varchar(20) DEFAULT NULL,
  `GEMEINDE_ID` int(5) DEFAULT NULL,
  `WGS84_LAT` double DEFAULT NULL,
  `WGS84_LON` double DEFAULT NULL,
  `STAND` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ogd_linien` (
  `LINIEN_ID` int(9) NOT NULL,
  `BEZEICHNUNG` varchar(3) DEFAULT NULL,
  `REIHENFOLGE` int(3) DEFAULT NULL,
  `ECHTZEIT` int(1) DEFAULT NULL,
  `VERKEHRSMITTEL` varchar(20) DEFAULT NULL,
  `STAND` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE OR REPLACE VIEW `ogd_stations` AS SELECT
 1 AS `HALTESTELLEN_ID`,
  1 AS `Haltestelle`,
  1 AS `diva`,
  1 AS `Linien`,
  1 AS `LAT`,
  1 AS `LON` */;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ogd_steige` (
  `STEIG_ID` int(9) NOT NULL,
  `FK_LINIEN_ID` int(9) DEFAULT NULL,
  `FK_HALTESTELLEN_ID` int(9) DEFAULT NULL,
  `RICHTUNG` varchar(1) DEFAULT NULL,
  `REIHENFOLGE` int(2) DEFAULT NULL,
  `RBL` varchar(10) DEFAULT NULL,
  `DIVA` varchar(20) DEFAULT NULL,
  `BEREICH` varchar(2) DEFAULT NULL,
  `STEIG` varchar(5) DEFAULT NULL,
  `STEIG_WGS84_LAT` varchar(16) DEFAULT NULL,
  `STEIG_WGS84_LON` varchar(16) DEFAULT NULL,
  `STAND` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`STEIG_ID`),
  KEY `FK_LINIEN_ID` (`FK_LINIEN_ID`),
  KEY `FK_HALTESTELLEN_ID` (`FK_HALTESTELLEN_ID`),
  KEY `RBL_NUMMER` (`RBL`),
  KEY `STEIG_WGS84_LAT` (`STEIG_WGS84_LAT`),
  KEY `STEIG_WGS84_LON` (`STEIG_WGS84_LON`),
  KEY `REIHENFOLGE` (`REIHENFOLGE`),
  KEY `RICHTUNG` (`RICHTUNG`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `wl_favorites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idUser` int(11) NOT NULL,
  `title` varchar(100) DEFAULT NULL,
  `sort` int(11) DEFAULT NULL,
  `diva` varchar(255) DEFAULT NULL,
  `bclass` varchar(50) NOT NULL,
  `updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `filter_json` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idUser` (`idUser`)
) ENGINE=InnoDB AUTO_INCREMENT=418 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `wl_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idUser` int(11) NOT NULL,
  `context` varchar(50) NOT NULL COMMENT 'wl-monitor, search, ...',
  `activity` mediumtext NOT NULL COMMENT 'login, logout, delete, add, ...',
  `origin` varchar(25) NOT NULL COMMENT 'web, sql, ...',
  `ipAdress` int(10) unsigned DEFAULT NULL COMMENT 'use INET_NTOA(), INET_ATON()',
  `logTime` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idUser` (`idUser`),
  KEY `ip-adress` (`ipAdress`),
  FULLTEXT KEY `context` (`context`),
  FULLTEXT KEY `activity` (`activity`)
) ENGINE=InnoDB AUTO_INCREMENT=447 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `wl_preferences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT 'references auth.auth_accounts.id',
  `departures` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `last_fav_id` int(11) DEFAULT NULL,
  `last_diva` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`),
  KEY `fk_wlprefs_last_fav` (`last_fav_id`),
  CONSTRAINT `fk_wlprefs_last_fav` FOREIGN KEY (`last_fav_id`) REFERENCES `wl_favorites` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `wl_userprefs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `departures` smallint(6) NOT NULL DEFAULT 2,
  `updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `users ` FOREIGN KEY (`id`) REFERENCES `auth`.`auth_accounts` (`id`) ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50001 DROP VIEW IF EXISTS `ogd_stations`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_uca1400_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`wlmonitor`@`localhost` SQL SECURITY INVOKER */
/*!50001 VIEW `ogd_stations` AS select `h`.`HALTESTELLEN_ID` AS `HALTESTELLEN_ID`,`h`.`NAME` AS `Haltestelle`,`h`.`DIVA` AS `diva`,group_concat(distinct `l`.`BEZEICHNUNG` order by `l`.`BEZEICHNUNG` ASC separator ',') AS `Linien`,`h`.`WGS84_LAT` AS `LAT`,`h`.`WGS84_LON` AS `LON` from ((`ogd_steige` `s` join `ogd_linien` `l` on(`s`.`FK_LINIEN_ID` = `l`.`LINIEN_ID`)) join `ogd_haltestellen` `h` on(`s`.`FK_HALTESTELLEN_ID` = `h`.`HALTESTELLEN_ID`)) where `h`.`DIVA` is not null and `h`.`DIVA` <> '' group by `h`.`HALTESTELLEN_ID`,`h`.`NAME`,`h`.`DIVA`,`h`.`WGS84_LAT`,`h`.`WGS84_LON` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

