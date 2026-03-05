-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: cineclick_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `cineclick_db`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `cineclick_db` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `cineclick_db`;

--
-- Table structure for table `movies`
--

DROP TABLE IF EXISTS `movies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `movies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `genre` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `video_link` varchar(255) NOT NULL,
  `thumbnail_link` varchar(255) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `movies_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ratings`
--

DROP TABLE IF EXISTS `ratings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ratings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `movie_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL CHECK (`rating` between 1 and 5),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `movie_id` (`movie_id`),
  CONSTRAINT `ratings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `ratings_ibfk_2` FOREIGN KEY (`movie_id`) REFERENCES `movies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `requests`
--

DROP TABLE IF EXISTS `requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','admin','uploader') NOT NULL DEFAULT 'user',
  `uploader_request` tinyint(4) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `password_history`
-- Stores previous passwords to prevent reuse
--

DROP TABLE IF EXISTS `password_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  CONSTRAINT `password_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `password_reset_tokens`
-- Stores password reset tokens for email verification
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  KEY `idx_token_expires` (`token`, `expires_at`),
  CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_activity_log`
-- Audit log for user activities (password changes, logins, etc.)
--

DROP TABLE IF EXISTS `user_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `activity_type` enum('login','logout','password_change','password_reset','registration','role_change') NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_activity_type` (`activity_type`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `user_activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `movie_rating_stats`
-- Cached rating statistics for movies (updated by trigger)
--

DROP TABLE IF EXISTS `movie_rating_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `movie_rating_stats` (
  `movie_id` int(11) NOT NULL,
  `avg_rating` decimal(3,2) NOT NULL DEFAULT 0.00,
  `rating_count` int(11) NOT NULL DEFAULT 0,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`movie_id`),
  CONSTRAINT `movie_rating_stats_ibfk_1` FOREIGN KEY (`movie_id`) REFERENCES `movies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- ============================================
-- TRIGGERS
-- ============================================

--
-- Trigger: after_user_insert
-- Logs user registration in activity log
--

DELIMITER //
DROP TRIGGER IF EXISTS `after_user_insert`//
CREATE TRIGGER `after_user_insert` AFTER INSERT ON `users`
FOR EACH ROW
BEGIN
    INSERT INTO `user_activity_log` (`user_id`, `activity_type`, `description`)
    VALUES (NEW.id, 'registration', CONCAT('New user registered: ', NEW.username));
END//
DELIMITER ;

--
-- Trigger: after_user_role_update
-- Logs role changes in activity log
--

DELIMITER //
DROP TRIGGER IF EXISTS `after_user_role_update`//
CREATE TRIGGER `after_user_role_update` AFTER UPDATE ON `users`
FOR EACH ROW
BEGIN
    IF OLD.role <> NEW.role THEN
        INSERT INTO `user_activity_log` (`user_id`, `activity_type`, `description`)
        VALUES (NEW.id, 'role_change', CONCAT('Role changed from ', OLD.role, ' to ', NEW.role));
    END IF;
END//
DELIMITER ;

--
-- Trigger: after_rating_insert
-- Updates movie rating statistics when a new rating is added
--

DELIMITER //
DROP TRIGGER IF EXISTS `after_rating_insert`//
CREATE TRIGGER `after_rating_insert` AFTER INSERT ON `ratings`
FOR EACH ROW
BEGIN
    INSERT INTO `movie_rating_stats` (`movie_id`, `avg_rating`, `rating_count`)
    SELECT NEW.movie_id, AVG(rating), COUNT(*)
    FROM `ratings` WHERE movie_id = NEW.movie_id
    ON DUPLICATE KEY UPDATE 
        avg_rating = VALUES(avg_rating),
        rating_count = VALUES(rating_count);
END//
DELIMITER ;

--
-- Trigger: after_rating_update
-- Updates movie rating statistics when a rating is modified
--

DELIMITER //
DROP TRIGGER IF EXISTS `after_rating_update`//
CREATE TRIGGER `after_rating_update` AFTER UPDATE ON `ratings`
FOR EACH ROW
BEGIN
    UPDATE `movie_rating_stats`
    SET avg_rating = (SELECT AVG(rating) FROM `ratings` WHERE movie_id = NEW.movie_id),
        rating_count = (SELECT COUNT(*) FROM `ratings` WHERE movie_id = NEW.movie_id)
    WHERE movie_id = NEW.movie_id;
END//
DELIMITER ;

--
-- Trigger: after_rating_delete
-- Updates movie rating statistics when a rating is deleted
--

DELIMITER //
DROP TRIGGER IF EXISTS `after_rating_delete`//
CREATE TRIGGER `after_rating_delete` AFTER DELETE ON `ratings`
FOR EACH ROW
BEGIN
    DECLARE rating_exists INT;
    SELECT COUNT(*) INTO rating_exists FROM `ratings` WHERE movie_id = OLD.movie_id;
    
    IF rating_exists > 0 THEN
        UPDATE `movie_rating_stats`
        SET avg_rating = (SELECT AVG(rating) FROM `ratings` WHERE movie_id = OLD.movie_id),
            rating_count = rating_exists
        WHERE movie_id = OLD.movie_id;
    ELSE
        DELETE FROM `movie_rating_stats` WHERE movie_id = OLD.movie_id;
    END IF;
END//
DELIMITER ;

--
-- Trigger: after_movie_insert
-- Initializes movie rating stats when a new movie is added
--

DELIMITER //
DROP TRIGGER IF EXISTS `after_movie_insert`//
CREATE TRIGGER `after_movie_insert` AFTER INSERT ON `movies`
FOR EACH ROW
BEGIN
    INSERT INTO `movie_rating_stats` (`movie_id`, `avg_rating`, `rating_count`)
    VALUES (NEW.id, 0.00, 0);
END//
DELIMITER ;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-12-19 21:47:05
