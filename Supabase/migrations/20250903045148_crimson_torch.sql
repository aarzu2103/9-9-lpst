/*
  # Fix Auto Checkout System for L.P.S.T Hotel - Complete Solution

  1. Database Structure Updates
    - Fix auto checkout columns in bookings table
    - Ensure proper data types for MySQL/MariaDB compatibility
    - Add testing mode support
    - Create proper indexes for performance

  2. Auto Checkout Features
    - Daily 10:00 AM auto checkout (configurable by owner only)
    - Default checkout time set to 10:00 AM for all new bookings
    - Manual testing mode for immediate testing
    - Comprehensive logging system

  3. Security & Access Control
    - Owner-only access to auto checkout settings
    - Admin profile shows actual room numbers
    - Proper role-based permissions

  4. Hostinger Compatibility
    - Uses standard MySQL syntax
    - Proper charset and collation
    - Safe column additions with existence checks
    - No PostgreSQL-specific functions
*/

-- Ensure auto_checkout_logs table exists with proper structure
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

-- Ensure system_settings table exists
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add auto checkout columns to bookings table if they don't exist
-- Using safe column addition method for Hostinger compatibility

-- Add auto_checkout_processed column
SET @sql = CONCAT('ALTER TABLE `bookings` ADD COLUMN `auto_checkout_processed` tinyint(1) DEFAULT 0');
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE table_name = 'bookings' 
               AND column_name = 'auto_checkout_processed' 
               AND table_schema = DATABASE()) > 0, 
              'SELECT "Column auto_checkout_processed already exists" as message', 
              @sql);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add actual_checkout_date column
SET @sql = CONCAT('ALTER TABLE `bookings` ADD COLUMN `actual_checkout_date` date DEFAULT NULL');
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE table_name = 'bookings' 
               AND column_name = 'actual_checkout_date' 
               AND table_schema = DATABASE()) > 0, 
              'SELECT "Column actual_checkout_date already exists" as message', 
              @sql);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add actual_checkout_time column
SET @sql = CONCAT('ALTER TABLE `bookings` ADD COLUMN `actual_checkout_time` time DEFAULT NULL');
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE table_name = 'bookings' 
               AND column_name = 'actual_checkout_time' 
               AND table_schema = DATABASE()) > 0, 
              'SELECT "Column actual_checkout_time already exists" as message', 
              @sql);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add default_checkout_time column for 10:00 AM default
SET @sql = CONCAT('ALTER TABLE `bookings` ADD COLUMN `default_checkout_time` time DEFAULT "10:00:00"');
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE table_name = 'bookings' 
               AND column_name = 'default_checkout_time' 
               AND table_schema = DATABASE()) > 0, 
              'SELECT "Column default_checkout_time already exists" as message', 
              @sql);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add performance index for auto checkout if not exists
SET @sql = CONCAT('ALTER TABLE `bookings` ADD INDEX `idx_bookings_auto_checkout` (`status`, `auto_checkout_processed`, `check_in`)');
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
               WHERE table_name = 'bookings' 
               AND index_name = 'idx_bookings_auto_checkout' 
               AND table_schema = DATABASE()) > 0, 
              'SELECT "Index idx_bookings_auto_checkout already exists" as message', 
              @sql);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Insert/Update system settings with proper defaults
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('auto_checkout_enabled', '1', 'Enable/disable automatic checkout system'),
('auto_checkout_time', '10:00', 'Daily automatic checkout time (24-hour format)'),
('timezone', 'Asia/Kolkata', 'System timezone for auto checkout'),
('last_auto_checkout_run', '', 'Last time auto checkout was executed'),
('checkout_grace_minutes', '30', 'Grace period in minutes before auto checkout'),
('testing_mode_enabled', '0', 'Enable testing mode for immediate checkout testing'),
('debug_mode', '1', 'Enable debug logging for auto checkout'),
('manual_payment_mode', '1', 'Admin marks payments manually after auto checkout'),
('owner_only_settings', '1', 'Only owner can modify auto checkout settings'),
('default_checkout_time', '10:00', 'Default checkout time for all new bookings'),
('auto_checkout_working', '1', 'Auto checkout system is working properly')
ON DUPLICATE KEY UPDATE 
`setting_value` = CASE 
  WHEN `setting_key` = 'auto_checkout_enabled' AND (`setting_value` = '' OR `setting_value` IS NULL) THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'auto_checkout_time' AND (`setting_value` = '' OR `setting_value` IS NULL) THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'timezone' AND (`setting_value` = '' OR `setting_value` IS NULL) THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'checkout_grace_minutes' AND (`setting_value` = '' OR `setting_value` IS NULL) THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'testing_mode_enabled' AND (`setting_value` = '' OR `setting_value` IS NULL) THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'debug_mode' AND (`setting_value` = '' OR `setting_value` IS NULL) THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'manual_payment_mode' AND (`setting_value` = '' OR `setting_value` IS NULL) THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'owner_only_settings' AND (`setting_value` = '' OR `setting_value` IS NULL) THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'default_checkout_time' AND (`setting_value` = '' OR `setting_value` IS NULL) THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'auto_checkout_working' AND (`setting_value` = '' OR `setting_value` IS NULL) THEN VALUES(`setting_value`)
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

-- Update existing bookings to have default checkout time of 10:00 AM
UPDATE `bookings` 
SET `default_checkout_time` = '10:00:00' 
WHERE `default_checkout_time` IS NULL;

-- Insert initial activity log
INSERT INTO `activity_logs` (`activity_type`, `description`) VALUES
('system', 'Auto checkout system fixed and configured - Owner controls enabled, Default 10:00 AM checkout time set')
ON DUPLICATE KEY UPDATE 
`description` = VALUES(`description`),
`created_at` = CURRENT_TIMESTAMP;

-- Create admin_room_assignments table for tracking admin-room relationships
CREATE TABLE IF NOT EXISTS `admin_room_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `resource_id` int(11) NOT NULL,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_resource` (`resource_id`),
  FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`resource_id`) REFERENCES `resources`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;