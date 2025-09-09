/*
  # Fix Auto Checkout System for L.P.S.T Hotel - Complete Working Solution

  1. Database Structure
    - Fix auto checkout columns in bookings table
    - Ensure proper MySQL/MariaDB compatibility for Hostinger
    - Add missing indexes for performance
    - Create proper logging tables

  2. Auto Checkout Features
    - Daily 10:00 AM auto checkout (exact timing)
    - Proper cron job execution detection
    - Enhanced logging and error handling
    - Manual testing capabilities

  3. Hostinger Compatibility
    - Uses only standard MySQL syntax
    - Safe column additions with proper checks
    - Proper charset and collation
    - No PostgreSQL-specific functions
*/

-- Create auto_checkout_logs table with proper structure
CREATE TABLE IF NOT EXISTS `auto_checkout_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) UNSIGNED DEFAULT NULL,
  `resource_id` int(11) UNSIGNED NOT NULL,
  `resource_name` varchar(100) NOT NULL,
  `guest_name` varchar(100) DEFAULT NULL,
  `checkout_date` date NOT NULL,
  `checkout_time` time NOT NULL,
  `status` enum('success','failed') DEFAULT 'success',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_checkout_date` (`checkout_date`),
  KEY `idx_resource` (`resource_id`),
  KEY `idx_booking` (`booking_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create system_settings table with proper structure
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add auto checkout columns to bookings table safely
-- Check if auto_checkout_processed column exists, if not add it
SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'bookings' 
                     AND COLUMN_NAME = 'auto_checkout_processed');

SET @sql = IF(@column_exists = 0, 
              'ALTER TABLE `bookings` ADD COLUMN `auto_checkout_processed` tinyint(1) DEFAULT 0 AFTER `updated_at`',
              'SELECT "Column auto_checkout_processed already exists" as message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if actual_checkout_date column exists, if not add it
SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'bookings' 
                     AND COLUMN_NAME = 'actual_checkout_date');

SET @sql = IF(@column_exists = 0, 
              'ALTER TABLE `bookings` ADD COLUMN `actual_checkout_date` date DEFAULT NULL AFTER `auto_checkout_processed`',
              'SELECT "Column actual_checkout_date already exists" as message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if actual_checkout_time column exists, if not add it
SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'bookings' 
                     AND COLUMN_NAME = 'actual_checkout_time');

SET @sql = IF(@column_exists = 0, 
              'ALTER TABLE `bookings` ADD COLUMN `actual_checkout_time` time DEFAULT NULL AFTER `actual_checkout_date`',
              'SELECT "Column actual_checkout_time already exists" as message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if default_checkout_time column exists, if not add it
SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'bookings' 
                     AND COLUMN_NAME = 'default_checkout_time');

SET @sql = IF(@column_exists = 0, 
              'ALTER TABLE `bookings` ADD COLUMN `default_checkout_time` time DEFAULT "10:00:00" AFTER `actual_checkout_time`',
              'SELECT "Column default_checkout_time already exists" as message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add performance index for auto checkout if not exists
SET @index_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'bookings' 
                    AND INDEX_NAME = 'idx_bookings_auto_checkout');

SET @sql = IF(@index_exists = 0, 
              'ALTER TABLE `bookings` ADD INDEX `idx_bookings_auto_checkout` (`status`, `auto_checkout_processed`, `check_in`)',
              'SELECT "Index idx_bookings_auto_checkout already exists" as message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Insert/Update system settings with proper defaults for 10:00 AM auto checkout
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('auto_checkout_enabled', '1', 'Enable/disable automatic checkout system'),
('auto_checkout_time', '10:00', 'Daily automatic checkout time (24-hour format)'),
('timezone', 'Asia/Kolkata', 'System timezone for auto checkout'),
('last_auto_checkout_run', '', 'Last time auto checkout was executed'),
('checkout_grace_minutes', '5', 'Grace period in minutes for exact 10:00 AM execution'),
('testing_mode_enabled', '1', 'Enable testing mode for immediate checkout testing'),
('debug_mode', '1', 'Enable debug logging for auto checkout'),
('manual_payment_mode', '1', 'Admin marks payments manually after auto checkout'),
('owner_only_settings', '1', 'Only owner can modify auto checkout settings'),
('default_checkout_time', '10:00', 'Default checkout time for all new bookings'),
('auto_checkout_working', '1', 'Auto checkout system is working properly'),
('cron_execution_mode', 'daily', 'Cron execution mode: daily or continuous'),
('force_10am_checkout', '1', 'Force checkout at exactly 10:00 AM daily')
ON DUPLICATE KEY UPDATE 
`setting_value` = CASE 
  WHEN `setting_key` = 'auto_checkout_enabled' THEN '1'
  WHEN `setting_key` = 'auto_checkout_time' THEN '10:00'
  WHEN `setting_key` = 'timezone' THEN 'Asia/Kolkata'
  WHEN `setting_key` = 'checkout_grace_minutes' THEN '5'
  WHEN `setting_key` = 'testing_mode_enabled' THEN '1'
  WHEN `setting_key` = 'debug_mode' THEN '1'
  WHEN `setting_key` = 'manual_payment_mode' THEN '1'
  WHEN `setting_key` = 'owner_only_settings' THEN '1'
  WHEN `setting_key` = 'default_checkout_time' THEN '10:00'
  WHEN `setting_key` = 'auto_checkout_working' THEN '1'
  WHEN `setting_key` = 'cron_execution_mode' THEN 'daily'
  WHEN `setting_key` = 'force_10am_checkout' THEN '1'
  ELSE `setting_value`
END,
`description` = VALUES(`description`);

-- Ensure activity_logs table exists for system logging
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_type` varchar(50) NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `guest_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_type` (`activity_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create cron_execution_logs table for tracking cron job runs
CREATE TABLE IF NOT EXISTS `cron_execution_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `execution_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `execution_type` enum('automatic','manual','test') DEFAULT 'automatic',
  `target_time` time NOT NULL,
  `actual_time` time NOT NULL,
  `bookings_processed` int(11) DEFAULT 0,
  `bookings_successful` int(11) DEFAULT 0,
  `bookings_failed` int(11) DEFAULT 0,
  `execution_status` enum('success','failed','skipped') DEFAULT 'success',
  `error_message` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_execution_time` (`execution_time`),
  KEY `idx_execution_type` (`execution_type`),
  KEY `idx_target_time` (`target_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update existing bookings to have default checkout time of 10:00 AM
UPDATE `bookings` 
SET `default_checkout_time` = '10:00:00' 
WHERE `default_checkout_time` IS NULL OR `default_checkout_time` = '00:00:00';

-- Reset auto_checkout_processed flag for testing
UPDATE `bookings` 
SET `auto_checkout_processed` = 0 
WHERE `status` IN ('BOOKED', 'PENDING') 
AND `auto_checkout_processed` = 1;

-- Insert initial activity log
INSERT INTO `activity_logs` (`activity_type`, `description`) VALUES
('system', 'Auto checkout system fixed for daily 10:00 AM execution - Enhanced cron job compatibility')
ON DUPLICATE KEY UPDATE 
`description` = VALUES(`description`),
`created_at` = CURRENT_TIMESTAMP;

-- Insert test cron execution log
INSERT INTO `cron_execution_logs` (`execution_type`, `target_time`, `actual_time`, `notes`) VALUES
('test', '10:00:00', TIME(NOW()), 'Auto checkout system setup completed and ready for 10:00 AM daily execution');