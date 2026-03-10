-- =============================================================================
-- HotelSync Integration – Database Schema
-- Engine: MySQL 5.7+ / MariaDB 10.3+
-- Charset: utf8mb4
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- rooms
-- Stores property rooms/units synced from the HotelSync catalog.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rooms` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `external_id` VARCHAR(100)    NOT NULL COMMENT 'HS-{id}-{slug}',
    `hs_room_id`  VARCHAR(100)    NOT NULL COMMENT 'ID returned by HotelSync API',
    `name`        VARCHAR(255)    NOT NULL,
    `slug`        VARCHAR(255)    NOT NULL,
    `raw_data`    JSON            DEFAULT NULL COMMENT 'Full API response payload',
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rooms_external_id`  (`external_id`),
    UNIQUE KEY `uq_rooms_hs_room_id`   (`hs_room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- rate_plans
-- Stores rate plans / pricing structures from the HotelSync catalog.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_plans` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `external_id`     VARCHAR(100)    NOT NULL COMMENT 'RP-{id}-{meal_plan}',
    `hs_rate_plan_id` VARCHAR(100)    NOT NULL COMMENT 'ID returned by HotelSync API',
    `name`            VARCHAR(255)    NOT NULL,
    `meal_plan`       VARCHAR(50)     NOT NULL COMMENT 'BB | HB | FB | AI | RO',
    `raw_data`        JSON            DEFAULT NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rate_plans_external_id`     (`external_id`),
    UNIQUE KEY `uq_rate_plans_hs_rate_plan_id` (`hs_rate_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- reservations
-- Core table tracking each reservation imported from HotelSync.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reservations` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `lock_id`           VARCHAR(150)    NOT NULL COMMENT 'LOCK-{id}-{arrival_date} – dedup key',
    `hs_reservation_id` VARCHAR(100)    NOT NULL COMMENT 'HotelSync reservation ID',
    `guest_name`        VARCHAR(255)    NOT NULL,
    `arrival_date`      DATE            NOT NULL,
    `departure_date`    DATE            NOT NULL,
    `status`            VARCHAR(50)     NOT NULL DEFAULT 'new' COMMENT 'new | confirmed | cancelled | invoiced',
    `payload_hash`      VARCHAR(64)     DEFAULT NULL COMMENT 'SHA-256 hash for change detection',
    `raw_data`          JSON            DEFAULT NULL,
    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_reservations_lock_id`           (`lock_id`),
    UNIQUE KEY `uq_reservations_hs_reservation_id` (`hs_reservation_id`),
    INDEX `idx_reservations_status`      (`status`),
    INDEX `idx_reservations_arrival`     (`arrival_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- reservation_rooms
-- Many-to-many: which rooms are assigned to a reservation.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reservation_rooms` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reservation_id` INT UNSIGNED NOT NULL,
    `room_id`        INT UNSIGNED NOT NULL,
    `quantity`       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_res_room` (`reservation_id`, `room_id`),
    CONSTRAINT `fk_res_rooms_reservation` FOREIGN KEY (`reservation_id`)
        REFERENCES `reservations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_res_rooms_room` FOREIGN KEY (`room_id`)
        REFERENCES `rooms` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- reservation_rate_plans
-- Many-to-many: which rate plans are applied to a reservation.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reservation_rate_plans` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reservation_id` INT UNSIGNED NOT NULL,
    `rate_plan_id`   INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_res_rate_plan` (`reservation_id`, `rate_plan_id`),
    CONSTRAINT `fk_res_rp_reservation` FOREIGN KEY (`reservation_id`)
        REFERENCES `reservations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_res_rp_rate_plan` FOREIGN KEY (`rate_plan_id`)
        REFERENCES `rate_plans` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- audit_log
-- Immutable record of every meaningful state change on a reservation.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reservation_id` INT UNSIGNED DEFAULT NULL,
    `event_type`     VARCHAR(100) NOT NULL COMMENT 'e.g. reservation_created, status_changed',
    `old_data`       JSON         DEFAULT NULL,
    `new_data`       JSON         DEFAULT NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_audit_reservation` (`reservation_id`),
    INDEX `idx_audit_event_type`  (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- invoice_queue
-- Queue of invoices to be generated and sent to BridgeOne.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoice_queue` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `invoice_number` VARCHAR(30)     NOT NULL COMMENT 'HS-INV-YYYY-000001',
    `reservation_id` INT UNSIGNED    NOT NULL,
    `guest_name`     VARCHAR(255)    NOT NULL,
    `arrival_date`   DATE            NOT NULL,
    `departure_date` DATE            NOT NULL,
    `line_items`     JSON            NOT NULL COMMENT 'Array of invoice line items',
    `total_amount`   DECIMAL(12, 2)  NOT NULL,
    `currency`       CHAR(3)         NOT NULL DEFAULT 'EUR',
    `status`         VARCHAR(30)     NOT NULL DEFAULT 'pending' COMMENT 'pending | sent | failed',
    `retry_count`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_invoice_number`   (`invoice_number`),
    INDEX `idx_invoice_status`       (`status`),
    INDEX `idx_invoice_reservation`  (`reservation_id`),
    CONSTRAINT `fk_invoice_reservation` FOREIGN KEY (`reservation_id`)
        REFERENCES `reservations` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- webhook_events
-- Raw incoming webhook payloads from HotelSync; processed asynchronously.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `webhook_events` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`   VARCHAR(100) NOT NULL,
    `payload`      JSON         NOT NULL,
    `payload_hash` VARCHAR(64)  NOT NULL COMMENT 'SHA-256 for deduplication',
    `processed`    TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_webhook_hash`      (`payload_hash`),
    INDEX `idx_webhook_processed`     (`processed`),
    INDEX `idx_webhook_event_type`    (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
