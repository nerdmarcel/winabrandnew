-- =============================================
-- Win a Brand New - Complete MySQL Database Schema
-- Version: 1.0
-- Target: MySQL 8.0+ with InnoDB engine
-- Charset: UTF8MB4 for international support
-- =============================================

-- Set initial configuration
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = '+00:00';

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS `win_brand_new` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `win_brand_new`;

-- =============================================
-- CORE GAME TABLES
-- =============================================

-- Games: Prize definitions and settings
CREATE TABLE `games` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text,
  `prize_value` decimal(10,2) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'GBP',
  `max_players` int(11) NOT NULL DEFAULT 1000,
  `entry_fee` decimal(10,2) NOT NULL,
  `entry_fee_usd` decimal(10,2) DEFAULT NULL,
  `entry_fee_eur` decimal(10,2) DEFAULT NULL,
  `entry_fee_gbp` decimal(10,2) DEFAULT NULL,
  `entry_fee_cad` decimal(10,2) DEFAULT NULL,
  `entry_fee_aud` decimal(10,2) DEFAULT NULL,
  `auto_restart` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('active','paused','completed','disabled') NOT NULL DEFAULT 'active',
  `featured` tinyint(1) NOT NULL DEFAULT 0,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_games_status` (`status`),
  KEY `idx_games_featured` (`featured`),
  KEY `idx_games_currency` (`currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rounds: Individual game instances
CREATE TABLE `rounds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `game_id` int(11) NOT NULL,
  `round_number` int(11) NOT NULL DEFAULT 1,
  `status` enum('active','full','completed','cancelled') NOT NULL DEFAULT 'active',
  `participant_count` int(11) NOT NULL DEFAULT 0,
  `paid_participant_count` int(11) NOT NULL DEFAULT 0,
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `winner_participant_id` int(11) DEFAULT NULL,
  `winner_selected_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rounds_game_status` (`game_id`, `status`),
  KEY `idx_rounds_game_id` (`game_id`),
  KEY `idx_rounds_status` (`status`),
  KEY `idx_rounds_winner` (`winner_participant_id`),
  CONSTRAINT `fk_rounds_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Questions: Quiz content
CREATE TABLE `questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `game_id` int(11) NOT NULL,
  `question_order` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `option_a` varchar(500) NOT NULL,
  `option_b` varchar(500) NOT NULL,
  `option_c` varchar(500) NOT NULL,
  `correct_answer` enum('A','B','C') NOT NULL,
  `difficulty_level` enum('easy','medium','hard') NOT NULL DEFAULT 'medium',
  `explanation` text DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_questions_game_order` (`game_id`, `question_order`),
  KEY `idx_questions_game_active` (`game_id`, `active`),
  KEY `idx_questions_difficulty` (`difficulty_level`),
  CONSTRAINT `fk_questions_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- PARTICIPANT & USER MANAGEMENT
-- =============================================

-- Participants: Player entries per round
CREATE TABLE `participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `round_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `whatsapp_consent` tinyint(1) NOT NULL DEFAULT 0,
  `payment_status` enum('pending','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `payment_id` varchar(100) DEFAULT NULL,
  `payment_provider` varchar(50) DEFAULT NULL,
  `payment_currency` varchar(3) NOT NULL DEFAULT 'GBP',
  `payment_amount` decimal(10,2) NOT NULL,
  `payment_fee` decimal(10,2) DEFAULT 0.00,
  `payment_confirmed_at` timestamp NULL DEFAULT NULL,
  `total_time_all_questions` decimal(8,3) DEFAULT NULL COMMENT 'Total completion time in seconds (microsecond precision)',
  `pre_payment_time` decimal(8,3) DEFAULT NULL COMMENT 'Time for questions 1-3 in seconds',
  `post_payment_time` decimal(8,3) DEFAULT NULL COMMENT 'Time for questions 4-9 in seconds',
  `question_times_json` text DEFAULT NULL COMMENT 'Individual question times as JSON array',
  `answers_json` text DEFAULT NULL COMMENT 'User answers as JSON array',
  `correct_answers` int(11) DEFAULT 0,
  `game_completed` tinyint(1) NOT NULL DEFAULT 0,
  `completion_rank` int(11) DEFAULT NULL,
  `device_fingerprint` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `user_agent` varchar(1000) DEFAULT NULL,
  `referral_source` varchar(100) DEFAULT NULL,
  `referral_participant_id` int(11) DEFAULT NULL,
  `discount_applied` decimal(5,2) DEFAULT 0.00 COMMENT 'Discount percentage applied',
  `discount_type` enum('replay','referral','promo') DEFAULT NULL,
  `is_winner` tinyint(1) NOT NULL DEFAULT 0,
  `prize_claimed` tinyint(1) NOT NULL DEFAULT 0,
  `prize_claimed_at` timestamp NULL DEFAULT NULL,
  `fraud_score` decimal(3,2) DEFAULT 0.00,
  `fraud_flags` text DEFAULT NULL COMMENT 'JSON array of fraud indicators',
  `is_fraudulent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_participants_round_payment` (`round_id`, `payment_status`),
  KEY `idx_participants_email` (`user_email`),
  KEY `idx_participants_round_id` (`round_id`),
  KEY `idx_participants_payment_status` (`payment_status`),
  KEY `idx_participants_total_time` (`total_time_all_questions`),
  KEY `idx_participants_winner` (`is_winner`),
  KEY `idx_participants_referral` (`referral_participant_id`),
  KEY `idx_participants_session` (`session_id`),
  KEY `idx_participants_ip` (`ip_address`),
  KEY `idx_participants_device` (`device_fingerprint`),
  CONSTRAINT `fk_participants_round` FOREIGN KEY (`round_id`) REFERENCES `rounds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_participants_referral` FOREIGN KEY (`referral_participant_id`) REFERENCES `participants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Participant Question History: Track seen questions per user
CREATE TABLE `participant_question_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `game_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `participant_id` int(11) DEFAULT NULL,
  `seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_question` (`user_email`, `game_id`, `question_id`),
  KEY `idx_history_user_game` (`user_email`, `game_id`),
  KEY `idx_history_question` (`question_id`),
  KEY `idx_history_participant` (`participant_id`),
  CONSTRAINT `fk_history_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_history_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_history_participant` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- DISCOUNT & ACTIONS SYSTEM
-- =============================================

-- User Actions: Discount tracking
CREATE TABLE `user_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `action_type` enum('replay','referral','promo','bundle') NOT NULL,
  `action_subtype` varchar(50) DEFAULT NULL,
  `discount_amount` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_currency` varchar(3) NOT NULL DEFAULT 'GBP',
  `used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `round_id` int(11) DEFAULT NULL COMMENT 'Round where discount was earned',
  `used_round_id` int(11) DEFAULT NULL COMMENT 'Round where discount was used',
  `created_by_participant_id` int(11) DEFAULT NULL,
  `used_by_participant_id` int(11) DEFAULT NULL,
  `terms` text DEFAULT NULL,
  `metadata_json` text DEFAULT NULL,
  `status` enum('active','used','expired','cancelled') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_actions_email_type` (`email`, `action_type`),
  KEY `idx_actions_status` (`status`),
  KEY `idx_actions_expires` (`expires_at`),
  KEY `idx_actions_round` (`round_id`),
  KEY `idx_actions_used_round` (`used_round_id`),
  CONSTRAINT `fk_actions_round` FOREIGN KEY (`round_id`) REFERENCES `rounds` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_actions_used_round` FOREIGN KEY (`used_round_id`) REFERENCES `rounds` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_actions_created_by` FOREIGN KEY (`created_by_participant_id`) REFERENCES `participants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_actions_used_by` FOREIGN KEY (`used_by_participant_id`) REFERENCES `participants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- FINANCIAL & CURRENCY MANAGEMENT
-- =============================================

-- Exchange Rates: Daily currency conversion
CREATE TABLE `exchange_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `base_currency` varchar(3) NOT NULL DEFAULT 'GBP',
  `target_currency` varchar(3) NOT NULL,
  `rate` decimal(10,6) NOT NULL,
  `provider` varchar(50) DEFAULT 'exchangerate-api',
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_exchange_rates_currencies_date` (`base_currency`, `target_currency`, DATE(`last_updated`)),
  KEY `idx_exchange_rates_target` (`target_currency`),
  KEY `idx_exchange_rates_updated` (`last_updated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tax Configuration: Country-specific VAT rates
CREATE TABLE `tax_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `country_code` varchar(3) NOT NULL,
  `country_name` varchar(100) NOT NULL,
  `tax_rate` decimal(5,2) NOT NULL,
  `tax_name` varchar(50) NOT NULL DEFAULT 'VAT',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `country_code` (`country_code`),
  KEY `idx_tax_rates_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- PRIZE FULFILLMENT & CLAIMS
-- =============================================

-- WhatsApp Claim Tokens: Secure winner claim links
CREATE TABLE `claim_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `participant_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL COMMENT '32-character secure random token',
  `token_type` enum('winner_claim','tracking_update') NOT NULL DEFAULT 'winner_claim',
  `used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP that used the token',
  `user_agent` varchar(1000) DEFAULT NULL,
  `metadata_json` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_claim_tokens_participant` (`participant_id`),
  KEY `idx_claim_tokens_expires` (`expires_at`),
  KEY `idx_claim_tokens_type` (`token_type`),
  CONSTRAINT `fk_claim_tokens_participant` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prize Fulfillment: Shipping and tracking management
CREATE TABLE `prize_fulfillments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `participant_id` int(11) NOT NULL,
  `claim_token_id` int(11) DEFAULT NULL,
  `shipping_name` varchar(255) NOT NULL,
  `shipping_email` varchar(255) DEFAULT NULL,
  `shipping_phone` varchar(20) DEFAULT NULL,
  `shipping_address_line1` varchar(255) NOT NULL,
  `shipping_address_line2` varchar(255) DEFAULT NULL,
  `shipping_city` varchar(100) NOT NULL,
  `shipping_state` varchar(100) DEFAULT NULL,
  `shipping_postal_code` varchar(20) NOT NULL,
  `shipping_country` varchar(100) NOT NULL,
  `shipping_country_code` varchar(3) DEFAULT NULL,
  `special_instructions` text DEFAULT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `shipping_provider` varchar(100) DEFAULT NULL,
  `tracking_url` varchar(500) DEFAULT NULL,
  `tracking_sent_at` timestamp NULL DEFAULT NULL,
  `estimated_delivery` date DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','processing','shipped','delivered','cancelled','returned') NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `shipping_cost` decimal(10,2) DEFAULT 0.00,
  `insurance_value` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fulfillment_participant` (`participant_id`),
  KEY `idx_fulfillment_claim_token` (`claim_token_id`),
  KEY `idx_fulfillment_status` (`status`),
  KEY `idx_fulfillment_tracking` (`tracking_number`),
  KEY `idx_fulfillment_country` (`shipping_country_code`),
  CONSTRAINT `fk_fulfillment_participant` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fulfillment_claim_token` FOREIGN KEY (`claim_token_id`) REFERENCES `claim_tokens` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- CONVERSION & ANALYTICS
-- =============================================

-- Conversion Tracking: WhatsApp replay conversions
CREATE TABLE `conversion_tracking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_type` enum('whatsapp_replay','email_replay','referral','social_media','direct') NOT NULL,
  `source_id` varchar(100) DEFAULT NULL COMMENT 'Original participant ID or campaign ID',
  `target_participant_id` int(11) NOT NULL COMMENT 'New participant created from conversion',
  `conversion_value` decimal(10,2) DEFAULT NULL COMMENT 'Revenue generated from conversion',
  `conversion_currency` varchar(3) DEFAULT 'GBP',
  `utm_source` varchar(100) DEFAULT NULL,
  `utm_medium` varchar(100) DEFAULT NULL,
  `utm_campaign` varchar(100) DEFAULT NULL,
  `utm_content` varchar(100) DEFAULT NULL,
  `referrer_url` varchar(500) DEFAULT NULL,
  `landing_page` varchar(500) DEFAULT NULL,
  `device_type` enum('mobile','tablet','desktop') DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `country_code` varchar(3) DEFAULT NULL,
  `converted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conversion_source` (`source_type`, `source_id`),
  KEY `idx_conversion_target` (`target_participant_id`),
  KEY `idx_conversion_date` (`converted_at`),
  KEY `idx_conversion_utm` (`utm_source`, `utm_medium`, `utm_campaign`),
  CONSTRAINT `fk_conversion_target` FOREIGN KEY (`target_participant_id`) REFERENCES `participants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Business Analytics: Conversion and revenue tracking
CREATE TABLE `analytics_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` enum('game_start','payment_attempt','payment_success','payment_failure','game_complete','winner_selected','claim_initiated','prize_shipped','whatsapp_sent','email_sent','referral_used') NOT NULL,
  `participant_id` int(11) DEFAULT NULL,
  `round_id` int(11) DEFAULT NULL,
  `game_id` int(11) DEFAULT NULL,
  `revenue_amount` decimal(10,2) DEFAULT NULL,
  `revenue_currency` varchar(3) DEFAULT 'GBP',
  `conversion_source` varchar(100) DEFAULT NULL,
  `event_properties` text DEFAULT NULL COMMENT 'JSON object with additional event data',
  `session_id` varchar(128) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_analytics_events_type_date` (`event_type`, `created_at`),
  KEY `idx_analytics_events_participant` (`participant_id`),
  KEY `idx_analytics_events_round` (`round_id`),
  KEY `idx_analytics_events_game` (`game_id`),
  KEY `idx_analytics_events_session` (`session_id`),
  CONSTRAINT `fk_analytics_participant` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_analytics_round` FOREIGN KEY (`round_id`) REFERENCES `rounds` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_analytics_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- MESSAGING & QUEUE SYSTEM
-- =============================================

-- Email Queue: Rate-limited message delivery
CREATE TABLE `email_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `to_email` varchar(255) NOT NULL,
  `to_name` varchar(255) DEFAULT NULL,
  `from_email` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `reply_to` varchar(255) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` longtext DEFAULT NULL,
  `body_text` longtext DEFAULT NULL,
  `template_name` varchar(100) DEFAULT NULL,
  `template_vars` text DEFAULT NULL COMMENT 'JSON object with template variables',
  `priority` tinyint(4) NOT NULL DEFAULT 2 COMMENT '1=high, 2=normal, 3=low',
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `max_attempts` tinyint(4) NOT NULL DEFAULT 3,
  `last_error` text DEFAULT NULL,
  `send_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processing` tinyint(1) NOT NULL DEFAULT 0,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_queue_send_at` (`send_at`),
  KEY `idx_email_queue_priority` (`priority`),
  KEY `idx_email_queue_processing` (`processing`),
  KEY `idx_email_queue_attempts` (`attempts`),
  KEY `idx_email_queue_to_email` (`to_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed Emails: Track failed email deliveries
CREATE TABLE `failed_emails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `original_queue_id` int(11) DEFAULT NULL,
  `to_email` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `error_message` text NOT NULL,
  `error_code` varchar(50) DEFAULT NULL,
  `attempts_made` tinyint(4) NOT NULL DEFAULT 0,
  `bounce_type` enum('hard','soft','complaint','delivery') DEFAULT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_failed_emails_to_email` (`to_email`),
  KEY `idx_failed_emails_failed_at` (`failed_at`),
  KEY `idx_failed_emails_bounce_type` (`bounce_type`),
  CONSTRAINT `fk_failed_emails_queue` FOREIGN KEY (`original_queue_id`) REFERENCES `email_queue` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- WhatsApp Queue: Rate-limited message delivery
CREATE TABLE `whatsapp_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `to_phone` varchar(20) NOT NULL,
  `message_template` varchar(100) NOT NULL,
  `variables_json` text DEFAULT NULL COMMENT 'JSON object with template variables',
  `message_type` enum('winner_notification','non_winner_consolation','weekly_promotion','tracking_update','reminder') NOT NULL,
  `participant_id` int(11) DEFAULT NULL,
  `priority` tinyint(4) NOT NULL DEFAULT 2 COMMENT '1=high, 2=normal, 3=low',
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `max_attempts` tinyint(4) NOT NULL DEFAULT 3,
  `last_error` text DEFAULT NULL,
  `whatsapp_message_id` varchar(100) DEFAULT NULL,
  `send_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processing` tinyint(1) NOT NULL DEFAULT 0,
  `processed_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_whatsapp_queue_send_at` (`send_at`),
  KEY `idx_whatsapp_queue_priority` (`priority`),
  KEY `idx_whatsapp_queue_processing` (`processing`),
  KEY `idx_whatsapp_queue_participant` (`participant_id`),
  KEY `idx_whatsapp_queue_phone` (`to_phone`),
  CONSTRAINT `fk_whatsapp_queue_participant` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- ADMIN & SECURITY
-- =============================================

-- Admin Users: Authentication and permissions
CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `role` enum('super_admin','admin','moderator','viewer') NOT NULL DEFAULT 'admin',
  `permissions` text DEFAULT NULL COMMENT 'JSON array of specific permissions',
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_secret` varchar(32) DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `failed_login_attempts` tinyint(4) NOT NULL DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `password_reset_token` varchar(64) DEFAULT NULL,
  `password_reset_expires` timestamp NULL DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_admin_users_role` (`role`),
  KEY `idx_admin_users_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin Sessions: Track admin login sessions
CREATE TABLE `admin_sessions` (
  `id` varchar(128) NOT NULL,
  `admin_user_id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(1000) DEFAULT NULL,
  `data` text DEFAULT NULL,
  `last_activity` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_sessions_user` (`admin_user_id`),
  KEY `idx_admin_sessions_expires` (`expires_at`),
  KEY `idx_admin_sessions_activity` (`last_activity`),
  CONSTRAINT `fk_admin_sessions_user` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security Log: Failed token attempts tracking
CREATE TABLE `security_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `event_type` enum('invalid_token','failed_login','suspicious_activity','rate_limit_exceeded','fraud_detection') NOT NULL,
  `details_json` text DEFAULT NULL COMMENT 'Additional event details as JSON',
  `user_agent` varchar(1000) DEFAULT NULL,
  `
