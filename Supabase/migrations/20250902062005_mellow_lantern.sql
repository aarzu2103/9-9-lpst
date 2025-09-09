/*
  # Fix Auto Checkout System for L.P.S.T Hotel

  1. Table Updates
    - Ensure all required tables exist with proper structure
    - Add missing columns to existing tables
    - Create proper indexes for performance

  2. Settings
    - Add testing mode settings
    - Update auto checkout configuration
    - Remove automatic payment calculation

  3. Compatibility
    - Works with MySQL/MariaDB on Hostinger
    - Proper charset and collation
    - Safe column additions
*/

-- Create auto_checkout_logs table if not exists
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

-- Create system_settings table if not exists
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
-- Check and add auto_checkout_processed column
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'bookings' 
   AND column_name = 'auto_checkout_processed' 
   AND table_schema = DATABASE()) > 0,
  'SELECT ''Column auto_checkout_processed already exists'' as message;',
  'ALTER TABLE `bookings` ADD COLUMN `auto_checkout_processed` tinyint(1) DEFAULT 0;'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add actual_checkout_date column
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'bookings' 
   AND column_name = 'actual_checkout_date' 
   AND table_schema = DATABASE()) > 0,
  'SELECT ''Column actual_checkout_date already exists'' as message;',
  'ALTER TABLE `bookings` ADD COLUMN `actual_checkout_date` date DEFAULT NULL;'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add actual_checkout_time column
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'bookings' 
   AND column_name = 'actual_checkout_time' 
   AND table_schema = DATABASE()) > 0,
  'SELECT ''Column actual_checkout_time already exists'' as message;',
  'ALTER TABLE `bookings` ADD COLUMN `actual_checkout_time` time DEFAULT NULL;'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for auto checkout performance if not exists
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
   WHERE table_name = 'bookings' 
   AND index_name = 'idx_bookings_auto_checkout' 
   AND table_schema = DATABASE()) > 0,
  'SELECT ''Index idx_bookings_auto_checkout already exists'' as message;',
  'ALTER TABLE `bookings` ADD INDEX `idx_bookings_auto_checkout` (`status`, `auto_checkout_processed`, `check_in`);'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Insert/Update default auto checkout settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('auto_checkout_enabled', '1', 'Enable/disable automatic checkout system'),
('auto_checkout_time', '10:00', 'Daily automatic checkout time (24-hour format)'),
('timezone', 'Asia/Kolkata', 'System timezone for auto checkout'),
('last_auto_checkout_run', '', 'Last time auto checkout was executed'),
('checkout_grace_minutes', '30', 'Grace period in minutes before auto checkout'),
('testing_mode_enabled', '0', 'Enable testing mode for immediate checkout testing'),
('debug_mode', '1', 'Enable debug logging for auto checkout'),
('manual_payment_mode', '1', 'Admin marks payments manually after auto checkout')
ON DUPLICATE KEY UPDATE 
`setting_value` = CASE 
  WHEN `setting_key` = 'auto_checkout_enabled' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'auto_checkout_time' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'timezone' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'checkout_grace_minutes' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'testing_mode_enabled' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'debug_mode' AND `setting_value` = '' THEN VALUES(`setting_value`)
  WHEN `setting_key` = 'manual_payment_mode' AND `setting_value` = '' THEN VALUES(`setting_value`)
  ELSE `setting_value`
END,
`description` = VALUES(`description`);

-- Create activity_logs table if not exists (for system logging)
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

-- Insert initial activity log
INSERT INTO `activity_logs` (`activity_type`, `description`) VALUES
('system', 'Fixed auto checkout system - Manual payment mode enabled')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);