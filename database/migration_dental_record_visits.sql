-- ============================================================
-- Creates the dental_record_visits table used by the Patient Profile page
-- (modules/patients/view.php, modules/print/dental_record.php).
-- Missing from cap.sql: on a fresh install the Patient Profile page
-- crashes with HTTP 500 until this runs. Safe to re-run.
-- ============================================================
USE `cap`;

CREATE TABLE IF NOT EXISTS `dental_record_visits` (
  `id`                 INT NOT NULL AUTO_INCREMENT,
  `dental_record_id`   INT NOT NULL,
  `visit_date`         DATE NOT NULL,
  `treatment_rendered` TEXT NULL,
  `fee`                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method`     VARCHAR(30) NULL DEFAULT NULL,
  `recorded_by`        INT NULL DEFAULT NULL,
  `bill_id`            INT NULL DEFAULT NULL,
  `applied_amount`     DECIMAL(10,2) NULL DEFAULT NULL,
  `voided_at`          DATETIME NULL DEFAULT NULL,
  `voided_by`          INT NULL DEFAULT NULL,
  `void_reason`        VARCHAR(255) NULL DEFAULT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_drv_record` (`dental_record_id`),
  KEY `idx_drv_date`   (`visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- bills.dental_record_id — links a bill to the dental record it was raised for
-- (used by the Patient Profile page). MariaDB / MySQL 8.0.29+ syntax-safe version:
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bills' AND COLUMN_NAME = 'dental_record_id');
SET @sql := IF(@col = 0,
               'ALTER TABLE `bills` ADD COLUMN `dental_record_id` INT NULL DEFAULT NULL AFTER `appointment_id`',
               'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
