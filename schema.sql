-- ============================================================
-- RetroApp v1.0.0 — Database Schema
-- Engine: MySQL / MariaDB
-- Charset: utf8mb4
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ─── Admin users (single admin in v1) ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(80)     NOT NULL UNIQUE,
    `password_hash` VARCHAR(255)    NOT NULL,
    `role`          ENUM('admin')   NOT NULL DEFAULT 'admin',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Retrospective rooms ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `retro_rooms` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `room_uuid`     CHAR(36)        NOT NULL UNIQUE,
    `name`          VARCHAR(200)    NOT NULL,
    `description`   TEXT            DEFAULT NULL,
    `template_name` VARCHAR(100)    NOT NULL DEFAULT 'Start-Stop-Continue',
    `status`        ENUM('draft','active','revealed','closed','archived')
                                    NOT NULL DEFAULT 'draft',
    `max_votes`     TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `allow_edit_notes` TINYINT(1)   NOT NULL DEFAULT 1,
    `session_date`  DATE            DEFAULT NULL,
    `reveal_at`     DATETIME        DEFAULT NULL,
    `created_by`    INT UNSIGNED    NOT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Board columns ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `retro_columns` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `room_id`       INT UNSIGNED    NOT NULL,
    `title`         VARCHAR(100)    NOT NULL,
    `color`         VARCHAR(7)      NOT NULL DEFAULT '#6366f1', -- hex color for column header
    `display_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_room_order` (`room_id`, `display_order`),
    FOREIGN KEY (`room_id`) REFERENCES `retro_rooms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Participants (lightweight session tracking) ───────────────────────────────
CREATE TABLE IF NOT EXISTS `participants` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `room_id`       INT UNSIGNED    NOT NULL,
    `session_token` CHAR(64)        NOT NULL,
    `nickname`      VARCHAR(80)     DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_room_token` (`room_id`, `session_token`),
    INDEX `idx_token` (`session_token`),
    FOREIGN KEY (`room_id`) REFERENCES `retro_rooms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Retro notes ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `retro_notes` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `room_id`       INT UNSIGNED    NOT NULL,
    `column_id`     INT UNSIGNED    NOT NULL,
    `participant_id` INT UNSIGNED   NOT NULL,
    `content`       TEXT            NOT NULL,
    `is_revealed`   TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_room_column` (`room_id`, `column_id`),
    INDEX `idx_participant` (`participant_id`),
    FOREIGN KEY (`room_id`)       REFERENCES `retro_rooms`(`id`)    ON DELETE CASCADE,
    FOREIGN KEY (`column_id`)     REFERENCES `retro_columns`(`id`)  ON DELETE CASCADE,
    FOREIGN KEY (`participant_id`) REFERENCES `participants`(`id`)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Note votes ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `note_votes` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `room_id`       INT UNSIGNED    NOT NULL,
    `note_id`       INT UNSIGNED    NOT NULL,
    `participant_id` INT UNSIGNED   NOT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_note_participant` (`note_id`, `participant_id`), -- one vote per note per participant
    INDEX `idx_participant_room` (`participant_id`, `room_id`),
    FOREIGN KEY (`room_id`)        REFERENCES `retro_rooms`(`id`)   ON DELETE CASCADE,
    FOREIGN KEY (`note_id`)        REFERENCES `retro_notes`(`id`)   ON DELETE CASCADE,
    FOREIGN KEY (`participant_id`) REFERENCES `participants`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Action items ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `action_items` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `room_id`       INT UNSIGNED    NOT NULL,
    `note_id`       INT UNSIGNED    DEFAULT NULL,
    `title`         VARCHAR(300)    NOT NULL,
    `description`   TEXT            DEFAULT NULL,
    `owner_name`    VARCHAR(100)    DEFAULT NULL,
    `status`        ENUM('open','in_progress','done','cancelled')
                                    NOT NULL DEFAULT 'open',
    `due_date`      DATE            DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_room` (`room_id`),
    INDEX `idx_status` (`status`),
    FOREIGN KEY (`room_id`) REFERENCES `retro_rooms`(`id`)  ON DELETE CASCADE,
    FOREIGN KEY (`note_id`) REFERENCES `retro_notes`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Board templates (saved for reuse) ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `board_templates` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100)    NOT NULL,
    `columns_json`  TEXT            NOT NULL, -- JSON array of {title, color}
    `is_default`    TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── App meta (key/value store for app settings) ─────────────────────────────
CREATE TABLE IF NOT EXISTS `app_meta` (
    `meta_key`      VARCHAR(100)    NOT NULL,
    `meta_value`    TEXT            DEFAULT NULL,
    PRIMARY KEY (`meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Upgrade history ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `upgrade_history` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `version_from`  VARCHAR(20)     NOT NULL,
    `version_to`    VARCHAR(20)     NOT NULL,
    `executed_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes`         TEXT            DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Seed: Default board templates ───────────────────────────────────────────
INSERT INTO `board_templates` (`name`, `columns_json`, `is_default`) VALUES
(
    'Start · Stop · Continue',
    '[{"title":"Start","color":"#22c55e"},{"title":"Stop","color":"#ef4444"},{"title":"Continue","color":"#3b82f6"}]',
    1
),
(
    'Mad · Sad · Glad',
    '[{"title":"Mad","color":"#ef4444"},{"title":"Sad","color":"#f59e0b"},{"title":"Glad","color":"#22c55e"}]',
    0
),
(
    'What Went Well · Improvements · Questions',
    '[{"title":"What Went Well","color":"#22c55e"},{"title":"Improvements","color":"#f59e0b"},{"title":"Questions","color":"#8b5cf6"}]',
    0
),
(
    'Liked · Learned · Lacked · Longed For (4Ls)',
    '[{"title":"Liked","color":"#22c55e"},{"title":"Learned","color":"#3b82f6"},{"title":"Lacked","color":"#ef4444"},{"title":"Longed For","color":"#8b5cf6"}]',
    0
);

-- ─── Seed: App meta ───────────────────────────────────────────────────────────
INSERT INTO `app_meta` (`meta_key`, `meta_value`) VALUES
('app_version',   '1.0.0'),
('installed_at',  NOW()),
('schema_version','1');

SET foreign_key_checks = 1;
