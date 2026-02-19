-- SpoX+ AI — Database Schema & Repair Script
-- Run this in phpMyAdmin or your SQL client

-- 1. FIX: Missing 'message_uuid' in 'files' table
-- This is the solution for the "Column not found: 1054 Unknown column 'message_uuid'" error.
ALTER TABLE files ADD COLUMN IF NOT EXISTS message_uuid VARCHAR(36) NULL AFTER chat_id;
CREATE INDEX IF NOT EXISTS idx_files_message_uuid ON files (message_uuid);

-- 2. Ensure other columns exist (Migration safety)
ALTER TABLE projects ADD COLUMN IF NOT EXISTS uuid VARCHAR(36) NOT NULL UNIQUE AFTER user_id;
ALTER TABLE files ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL AFTER created_at;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL AFTER created_at;

-- 3. Correct column types if necessary
-- Ensure UUIDs are the right length
ALTER TABLE chats MODIFY COLUMN uuid VARCHAR(36) NOT NULL;
ALTER TABLE messages MODIFY COLUMN uuid VARCHAR(36) NOT NULL;

-- 4. Full Table Definitions (For reference/clean install)
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `ip_hash` varchar(64) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `user_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `chat_id` int(11) DEFAULT NULL,
  `message_uuid` varchar(36) DEFAULT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `size` int(11) DEFAULT NULL,
  `storage_path` varchar(512) DEFAULT NULL,
  `extracted_text` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_files_message_uuid` (`message_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` int(11) NOT NULL,
  `uuid` varchar(36) NOT NULL,
  `sender` enum('user','assistant') NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
