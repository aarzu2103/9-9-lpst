/*
  # Reliable Auto-Checkout System for L.P.S.T Hotel
  
  This creates a mandatory popup-based auto-checkout system that triggers
  when any admin logs in after 10:00 AM, ensuring reliable daily execution.
  
  1. New Tables
    - `daily_auto_checkout_status` - Tracks daily auto-checkout completion
    - `auto_checkout_pending_rooms` - Rooms pending auto-checkout
    - `admin_login_tracking` - Track admin logins for popup triggering
  
  2. Features
    - Mandatory popup when admin logs in after 10:00 AM
    - One-click confirmation to process all pending checkouts
    - Backup cron job for verification
    - Complete audit trail
  
  3. Hostinger Compatibility
    - Uses standard MySQL syntax only
    - Safe table creation with IF NOT EXISTS
    - Proper charset and collation
    - InnoDB engine for reliability
*/

-- Set proper timezone and charset for Hostinger
SET time_zone = '+05:30';
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create daily auto-checkout status tracking table
CREATE TABLE IF NOT EXISTS `daily_auto_checkout_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `checkout_date` date NOT NULL,
  `target_checkout_time` time NOT NULL DEFAULT '10:00:00',
  `is_completed` tinyint(1) NOT NULL DEFAULT 0,
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by_admin_id` int(11) DEFAULT NULL,
  `completion_method` enum('popup','cron','manual') DEFAULT 'popup',
  `rooms_processed` int(11) DEFAULT 0,
  `rooms_successful` int(11) DEFAULT 0,
  `rooms_failed` int(11) DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_daily_checkout` (`checkout_date`),
  KEY `idx_checkout_date` (`checkout_date`),
  KEY `idx_completed` (`is_completed`),
  FOREIGN KEY (`completed_by_admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create auto-checkout pending rooms tracking table
CREATE TABLE IF NOT EXISTS `auto_checkout_pending_rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `checkout_date` date NOT NULL,
  `booking_id` int(11) UNSIGNED NOT NULL,
  `resource_id` int(11) UNSIGNED NOT NULL,
  `resource_name` varchar(100) NOT NULL,
  `client_name` varchar(255) NOT NULL,
  `client_mobile` varchar(15) NOT NULL,
  `check_in_time` datetime NOT NULL,
  `is_processed` tinyint(1) NOT NULL DEFAULT 0,
  `processed_at` timestamp NULL DEFAULT NULL,
  `checkout_timestamp` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_booking_checkout` (`checkout_date`, `booking_id`),
  KEY `idx_checkout_date` (`checkout_date`),
  KEY `idx_booking` (`booking_id`),
  KEY `idx_resource` (`resource_id`),
  KEY `idx_processed` (`is_processed`),
  FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`resource_id`) REFERENCES `resources`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create admin login tracking table for popup triggering
CREATE TABLE IF NOT EXISTS `admin_login_tracking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `login_date` date NOT NULL,
  `login_time` time NOT NULL,
  `login_timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `popup_shown` tinyint(1) NOT NULL DEFAULT 0,
  `popup_action_taken` tinyint(1) NOT NULL DEFAULT 0,
  `user_agent` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_admin_daily_login` (`admin_id`, `login_date`),
  KEY `idx_login_date` (`login_date`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_popup_shown` (`popup_shown`),
  FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create auto-checkout execution logs table (enhanced)
CREATE TABLE IF NOT EXISTS `auto_checkout_execution_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `execution_date` date NOT NULL,
  `execution_time` time NOT NULL,
  `execution_timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `execution_method` enum('popup','cron','manual','force') DEFAULT 'popup',
  `triggered_by_admin_id` int(11) DEFAULT NULL,
  `bookings_found` int(11) DEFAULT 0,
  `bookings_processed` int(11) DEFAULT 0,
  `bookings_successful` int(11) DEFAULT 0,
  `bookings_failed` int(11) DEFAULT 0,
  `execution_status` enum('success','failed','partial','no_bookings') DEFAULT 'success',
  `error_details` text DEFAULT NULL,
  `processing_duration_seconds` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_execution_date` (`execution_date`),
  KEY `idx_execution_method` (`execution_method`),
  KEY `idx_triggered_by` (`triggered_by_admin_id`),
  KEY `idx_execution_status` (`execution_status`),
  FOREIGN KEY (`triggered_by_admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add auto-checkout columns to bookings table if they don't exist
-- Check and add auto_checkout_processed column
SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'bookings' 
                     AND COLUMN_NAME = 'auto_checkout_processed');

SET @sql = IF(@column_exists = 0, 
              'ALTER TABLE `bookings` ADD COLUMN `auto_checkout_processed` tinyint(1) NOT NULL DEFAULT 0',
              'SELECT "Column auto_checkout_processed already exists" as message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add auto_checkout_date column
SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'bookings' 
                     AND COLUMN_NAME = 'auto_checkout_date');

SET @sql = IF(@column_exists = 0, 
              'ALTER TABLE `bookings` ADD COLUMN `auto_checkout_date` date DEFAULT NULL',
              'SELECT "Column auto_checkout_date already exists" as message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add auto_checkout_timestamp column
SET @column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'bookings' 
                     AND COLUMN_NAME = 'auto_checkout_timestamp');

SET @sql = IF(@column_exists = 0, 
              'ALTER TABLE `bookings` ADD COLUMN `auto_checkout_timestamp` timestamp NULL DEFAULT NULL',
              'SELECT "Column auto_checkout_timestamp already exists" as message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add performance index for auto-checkout queries
SET @index_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'bookings' 
                    AND INDEX_NAME = 'idx_auto_checkout_reliable');

SET @sql = IF(@index_exists = 0, 
              'ALTER TABLE `bookings` ADD INDEX `idx_auto_checkout_reliable` (`status`, `auto_checkout_processed`, `check_in`)',
              'SELECT "Index idx_auto_checkout_reliable already exists" as message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Insert system settings for reliable auto-checkout
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('reliable_auto_checkout_enabled', '1', 'Enable reliable popup-based auto checkout system'),
('auto_checkout_target_time', '10:00:00', 'Target time for auto checkout (always 10:00 AM)'),
('auto_checkout_popup_enabled', '1', 'Enable mandatory popup for admin-triggered auto checkout'),
('auto_checkout_timezone', 'Asia/Kolkata', 'Timezone for auto checkout operations'),
('auto_checkout_grace_period_hours', '2', 'Hours after 10:00 AM to show popup (until 12:00 PM)'),
('auto_checkout_backup_cron_enabled', '1', 'Enable backup cron job for verification'),
('auto_checkout_system_version', '6.0', 'Reliable auto checkout system version'),
('auto_checkout_method', 'popup_primary', 'Primary method: popup, backup: cron'),
('auto_checkout_popup_mandatory', '1', 'Popup is mandatory and cannot be dismissed'),
('auto_checkout_log_retention_days', '90', 'Days to retain auto checkout logs')
ON DUPLICATE KEY UPDATE 
`setting_value` = VALUES(`setting_value`),
`description` = VALUES(`description`);

-- Reset any existing auto-checkout flags for fresh start
UPDATE `bookings` 
SET `auto_checkout_processed` = 0,
    `auto_checkout_date` = NULL,
    `auto_checkout_timestamp` = NULL
WHERE `status` IN ('BOOKED', 'PENDING');

-- Insert initial activity log
INSERT INTO `activity_logs` (`activity_type`, `description`) VALUES
('system', 'Reliable Auto Checkout System Implemented - Popup-based with mandatory admin confirmation for guaranteed daily execution');

-- Create sample data for testing
INSERT INTO `daily_auto_checkout_status` (`checkout_date`, `target_checkout_time`, `is_completed`) VALUES
(CURDATE(), '10:00:00', 0)
ON DUPLICATE KEY UPDATE 
`target_checkout_time` = VALUES(`target_checkout_time`);

-- Insert verification record
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('reliable_system_installed', NOW(), 'Timestamp when reliable auto checkout system was installed'),
('system_ready_for_production', '1', 'System is ready for production use with popup-based auto checkout'),
('next_auto_checkout_method', 'admin_popup', 'Next auto checkout will be triggered by admin popup');