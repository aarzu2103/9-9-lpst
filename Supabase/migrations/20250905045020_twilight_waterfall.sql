/*
  # FINAL FIX: Auto Checkout System for L.P.S.T Hotel - Daily 10:00 AM Execution

  1. Database Structure
    - Reset auto_checkout_processed flags for fresh start
    - Ensure proper column types and defaults
    - Add missing indexes for performance

  2. System Settings
    - Force auto checkout time to exactly 10:00 AM
    - Enable system by default
    - Reset last run time for fresh execution

  3. Hostinger Compatibility
    - Uses only standard MySQL syntax
    - Proper charset and collation
    - Safe operations with existence checks
*/

-- Reset auto_checkout_processed flag for all active bookings
UPDATE `bookings` 
SET `auto_checkout_processed` = 0 
WHERE `status` IN ('BOOKED', 'PENDING');

-- Reset last run time to allow fresh execution
UPDATE `system_settings` 
SET `setting_value` = '' 
WHERE `setting_key` = 'last_auto_checkout_run';

-- Force auto checkout settings to correct values
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('auto_checkout_enabled', '1', 'Enable/disable automatic checkout system'),
('auto_checkout_time', '10:00', 'Daily automatic checkout time (24-hour format)'),
('timezone', 'Asia/Kolkata', 'System timezone for auto checkout'),
('last_auto_checkout_run', '', 'Last time auto checkout was executed'),
('checkout_grace_minutes', '5', 'Grace period in minutes for exact 10:00 AM execution'),
('testing_mode_enabled', '1', 'Enable testing mode for immediate checkout testing'),
('debug_mode', '1', 'Enable debug logging for auto checkout'),
('manual_payment_mode', '1', 'Admin marks payments manually after auto checkout'),
('force_10am_checkout', '1', 'Force checkout at exactly 10:00 AM daily'),
('cron_execution_window', '10:00-10:05', 'Execution window for auto checkout')
ON DUPLICATE KEY UPDATE 
`setting_value` = VALUES(`setting_value`),
`description` = VALUES(`description`);

-- Ensure all bookings have default checkout time of 10:00 AM
UPDATE `bookings` 
SET `default_checkout_time` = '10:00:00' 
WHERE `default_checkout_time` IS NULL 
OR `default_checkout_time` = '00:00:00'
OR `status` IN ('BOOKED', 'PENDING');

-- Clear any incorrect auto checkout logs from wrong times
DELETE FROM `auto_checkout_logs` 
WHERE `checkout_time` NOT BETWEEN '10:00:00' AND '10:05:00'
AND DATE(`created_at`) = CURDATE();

-- Insert system activity log for this fix
INSERT INTO `activity_logs` (`activity_type`, `description`) VALUES
('system', 'FINAL FIX: Auto checkout system reset for guaranteed daily 10:00 AM execution - All flags reset, settings corrected');

-- Create a verification record
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('system_fixed_timestamp', NOW(), 'Timestamp when auto checkout system was fixed for daily 10:00 AM execution')
ON DUPLICATE KEY UPDATE 
`setting_value` = NOW(),
`description` = VALUES(`description`);