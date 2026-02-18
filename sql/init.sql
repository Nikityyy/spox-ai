-- SpoX+AI Database Schema
-- BHAK & BHAS Steyr
-- Run: mysql -u user -p spoxai < init.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ─── Users ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ms_oid`        VARCHAR(64)  NOT NULL UNIQUE COMMENT 'Microsoft Object ID',
  `email`         VARCHAR(255) NOT NULL UNIQUE,
  `display_name`  VARCHAR(255) NOT NULL,
  `username`      VARCHAR(128) NOT NULL,
  `avatar_url`    VARCHAR(512) NULL,
  `role`          ENUM('student','teacher','admin') NOT NULL DEFAULT 'student',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` TIMESTAMP NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`    TIMESTAMP NULL COMMENT 'Soft delete for GDPR'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Projects ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `projects` (
  `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `uuid`       VARCHAR(36) NOT NULL UNIQUE,
  `name`       VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL,
  CONSTRAINT `fk_projects_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Chats ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `chats` (
  `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    BIGINT UNSIGNED NULL COMMENT 'NULL = guest (ephemeral)',
  `project_id` BIGINT UNSIGNED NULL,
  `uuid`       VARCHAR(36) NOT NULL UNIQUE,
  `title`      VARCHAR(255) NULL,
  `synced`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = synced from local storage',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL,
  CONSTRAINT `fk_chats_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_chats_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL,
  INDEX `idx_chats_user`    (`user_id`),
  INDEX `idx_chats_project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Messages ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `messages` (
  `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `chat_id`    BIGINT UNSIGNED NOT NULL,
  `uuid`       VARCHAR(36) NOT NULL UNIQUE,
  `sender`     ENUM('user','assistant') NOT NULL,
  `content`    LONGTEXT NOT NULL,
  `tokens`     INT UNSIGNED NULL COMMENT 'Token count for this message',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL,
  CONSTRAINT `fk_messages_chat` FOREIGN KEY (`chat_id`) REFERENCES `chats`(`id`) ON DELETE CASCADE,
  INDEX `idx_messages_chat` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Files ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `files` (
  `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `project_id`     BIGINT UNSIGNED NULL,
  `chat_id`        BIGINT UNSIGNED NULL,
  `filename`       VARCHAR(255) NOT NULL,
  `original_name`  VARCHAR(255) NOT NULL,
  `mime_type`      VARCHAR(128) NOT NULL,
  `size`           BIGINT UNSIGNED NOT NULL,
  `storage_path`   VARCHAR(512) NOT NULL,
  `extracted_text` LONGTEXT NULL COMMENT 'PDF/text content for RAG',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`     TIMESTAMP NULL,
  CONSTRAINT `fk_files_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_files_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_files_chat`    FOREIGN KEY (`chat_id`)    REFERENCES `chats`(`id`)    ON DELETE SET NULL,
  INDEX `idx_files_project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Gemini API Calls (masked PII) ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `gemini_calls` (
  `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`      BIGINT UNSIGNED NULL,
  `chat_id`      BIGINT UNSIGNED NULL,
  `model`        VARCHAR(128) NOT NULL,
  `prompt_tokens`  INT UNSIGNED NULL,
  `output_tokens`  INT UNSIGNED NULL,
  `duration_ms`    INT UNSIGNED NULL,
  `status`       ENUM('success','error','rate_limited') NOT NULL DEFAULT 'success',
  `error_code`   VARCHAR(64) NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_gemini_user`    (`user_id`),
  INDEX `idx_gemini_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Rate Limiting ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key_hash`   VARCHAR(64) NOT NULL COMMENT 'SHA256 of user_id or IP',
  `window_start` TIMESTAMP NOT NULL,
  `count`      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_rate_key_window` (`key_hash`, `window_start`),
  INDEX `idx_rate_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Audit Logs (no PII) ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    BIGINT UNSIGNED NULL,
  `action`     VARCHAR(128) NOT NULL,
  `ip_hash`    VARCHAR(64) NULL COMMENT 'SHA256 of IP, not raw IP',
  `details`    JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_user`    (`user_id`),
  INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── GDPR Consent ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `consent` (
  `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`      BIGINT UNSIGNED NULL COMMENT 'NULL = guest (cookie-based)',
  `session_hash` VARCHAR(64) NULL,
  `consent_data` JSON NOT NULL COMMENT '{"functional":true,"ts":...}',
  `ip_hash`      VARCHAR(64) NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_consent_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Sessions ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`         VARCHAR(128) PRIMARY KEY,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `data`       TEXT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_sessions_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Error Logs ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `error_logs` (
  `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `level`      ENUM('error','warning','info') NOT NULL DEFAULT 'error',
  `message`    TEXT NOT NULL,
  `context`    JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_errors_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
