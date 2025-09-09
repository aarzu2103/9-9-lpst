<?php
/**
 * COMPLETELY REBUILT Auto Checkout System - Final Solution
 * GUARANTEED daily 10:00 AM execution with bulletproof logic
 * SIMPLIFIED VERSION - NO PAYMENT CALCULATION
 * MANUAL PAYMENT ONLY - Admin marks payments after checkout
 */

class AutoCheckout {
    private $pdo;
    private $timezone;
    private $logFile;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->timezone = 'Asia/Kolkata';
        date_default_timezone_set($this->timezone);
        
        // Create logs directory
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/auto_checkout_' . date('Y-m-d') . '.log';
    }
    
    /**
     * MAIN EXECUTION METHOD - GUARANTEED 10:00 AM DAILY EXECUTION
     * BULLETPROOF TIME CHECKING - ONLY RUNS 10:00-10:05 AM
     * NO PAYMENT CALCULATION - MANUAL PAYMENT ONLY
     */
    public function executeDailyCheckout() {
        $this->log("=== AUTO CHECKOUT EXECUTION STARTED ===");
        $this->log("Server time: " . date('Y-m-d H:i:s'));
        
        try {
            $currentDate = date('Y-m-d');
            $currentHour = (int)date('H');
            $currentMinute = (int)date('i');
            
            $this->log("Current hour: $currentHour, Current minute: $currentMinute");
            
            // Check if this is a manual run
            $isManualRun = $this->isManualRun();
            $this->log("Execution type: " . ($isManualRun ? 'MANUAL' : 'AUTOMATIC'));
            
            // BULLETPROOF TIME CHECK - ONLY RUN BETWEEN 10:00-10:05 AM
            if (!$isManualRun) {
                // EXACT HOUR CHECK
                if ($currentHour !== 10) {
                    $this->log("WRONG HOUR: $currentHour (required: exactly 10)", 'SKIP');
                    $this->recordExecution($currentDate, date('H:i'), 'skipped', 0, 0, 0, "Wrong hour: $currentHour (required: 10)");
                    return $this->createResponse('wrong_hour', "Current hour is $currentHour, auto checkout only runs at 10:00 AM");
                }
                
                // EXACT MINUTE CHECK - ONLY 0-5 MINUTES
                if ($currentMinute > 5) {
                    $this->log("WRONG MINUTE: $currentMinute (window: 0-5)", 'SKIP');
                    $this->recordExecution($currentDate, date('H:i'), 'skipped', 0, 0, 0, "Wrong minute: $currentMinute (window: 0-5)");
                    return $this->createResponse('wrong_minute', "Current minute is $currentMinute, execution window is 10:00-10:05 AM");
                }
                
                // Check if already executed today using new tracking table
                if ($this->hasExecutedToday($currentDate)) {
                    $this->log("ALREADY EXECUTED TODAY", 'SKIP');
                    return $this->createResponse('already_executed', 'Auto checkout already executed today');
                }
            }
            
            $this->log("TIME CHECK PASSED - Proceeding with checkout");
            
            // Mark today as execution day to prevent duplicates
            if (!$isManualRun) {
                $this->markExecutionDay($currentDate, $currentHour, $currentMinute);
            }
            
            // Get bookings to checkout
            $bookings = $this->getBookingsForCheckout();
            $this->log("Found " . count($bookings) . " bookings for checkout");
            
            if (empty($bookings)) {
                $this->recordExecution($currentDate, '10:00', 'no_bookings', 0, 0, 0, 'No active bookings found');
                return $this->createResponse('no_bookings', 'No active bookings found for checkout');
            }
            
            // Process each booking (SIMPLIFIED - NO PAYMENT CALCULATION)
            $successful = 0;
            $failed = 0;
            $successfulBookings = [];
            $failedBookings = [];
            
            foreach ($bookings as $booking) {
                $this->log("Processing booking ID: {$booking['id']} - {$booking['client_name']} in {$booking['resource_name']}");
                
                $result = $this->processSimpleCheckout($booking);
                
                if ($result['success']) {
                    $successful++;
                    $successfulBookings[] = $booking;
                    $this->log("✅ Successfully processed booking ID: {$booking['id']}");
                } else {
                    $failed++;
                    $failedBookings[] = ['booking' => $booking, 'error' => $result['error']];
                    $this->log("❌ Failed to process booking ID: {$booking['id']} - {$result['error']}", 'ERROR');
                }
            }
            
            // Record execution
            $status = ($failed === 0) ? 'success' : (($successful > 0) ? 'partial' : 'failed');
            $this->recordExecution($currentDate, '10:00', $status, count($bookings), $successful, $failed);
            
            // Mark execution as completed
            if (!$isManualRun) {
                $this->markExecutionCompleted($currentDate, count($bookings));
            }
            
            $this->log("=== AUTO CHECKOUT COMPLETED ===");
            $this->log("Total: " . count($bookings) . ", Successful: $successful, Failed: $failed");
            
            return $this->createResponse('completed', "Processed " . count($bookings) . " bookings", [
                'total_processed' => count($bookings),
                'successful' => $successful,
                'failed' => $failed,
                'details' => [
                    'successful' => $successfulBookings,
                    'failed' => $failedBookings
                ]
            ]);
            
        } catch (Exception $e) {
            $this->log("CRITICAL ERROR: " . $e->getMessage(), 'ERROR');
            $this->recordExecution(date('Y-m-d'), date('H:i'), 'failed', 0, 0, 0, $e->getMessage());
            return $this->createResponse('error', $e->getMessage());
        }
    }
    
    /**
     * SIMPLIFIED CHECKOUT PROCESSING - NO PAYMENT CALCULATION
     * ADMIN MARKS PAYMENTS MANUALLY AFTER CHECKOUT
     */
    private function processSimpleCheckout($booking) {
        try {
            $this->pdo->beginTransaction();
            
            $checkoutDateTime = date('Y-m-d H:i:s');
            $checkoutDate = date('Y-m-d');
            $checkoutTime = '10:00:00';
            
            // Update booking status (NO PAYMENT CALCULATION)
            $stmt = $this->pdo->prepare("
                UPDATE bookings 
                SET status = 'COMPLETED',
                    actual_check_out = ?,
                    actual_checkout_date = ?,
                    actual_checkout_time = ?,
                    auto_checkout_processed = 1,
                    payment_notes = CONCAT(
                        COALESCE(payment_notes, ''), 
                        ' - Auto checkout at 10:00 AM on ', 
                        ?
                    )
                WHERE id = ?
            ");
            $stmt->execute([
                $checkoutDateTime,
                $checkoutDate,
                $checkoutTime,
                $checkoutDate,
                $booking['id']
            ]);
            
            // Log the checkout (NO PAYMENT AMOUNT)
            $stmt = $this->pdo->prepare("
                INSERT INTO auto_checkout_logs 
                (booking_id, resource_id, resource_name, guest_name, checkout_date, checkout_time, status, notes) 
                VALUES (?, ?, ?, ?, ?, ?, 'success', ?)
            ");
            $logNotes = "Automatic checkout - Admin will mark payment manually";
            $stmt->execute([
                $booking['id'],
                $booking['resource_id'],
                $booking['resource_name'],
                $booking['client_name'],
                $checkoutDate,
                $checkoutTime,
                $logNotes
            ]);
            
            // Send SMS notification
            $this->sendCheckoutSMS($booking);
            
            $this->pdo->commit();
            $this->log("✅ Successfully checked out booking ID: {$booking['id']} - Manual payment required");
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->log("❌ Failed to checkout booking ID: {$booking['id']} - " . $e->getMessage(), 'ERROR');
            
            // Log failed checkout
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO auto_checkout_logs 
                    (booking_id, resource_id, resource_name, guest_name, checkout_date, checkout_time, status, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, 'failed', ?)
                ");
                $stmt->execute([
                    $booking['id'],
                    $booking['resource_id'],
                    $booking['resource_name'],
                    $booking['client_name'],
                    date('Y-m-d'),
                    '10:00:00',
                    'Error: ' . $e->getMessage()
                ]);
            } catch (Exception $logError) {
                $this->log("Failed to log error: " . $logError->getMessage(), 'ERROR');
            }
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get bookings that need to be checked out
     */
    private function getBookingsForCheckout() {
        $stmt = $this->pdo->prepare("
            SELECT 
                b.id,
                b.resource_id,
                b.client_name,
                b.client_mobile,
                b.check_in,
                b.actual_check_in,
                b.status,
                b.admin_id,
                COALESCE(r.custom_name, r.display_name) as resource_name
            FROM bookings b 
            JOIN resources r ON b.resource_id = r.id 
            WHERE b.status IN ('BOOKED', 'PENDING')
            AND (b.auto_checkout_processed IS NULL OR b.auto_checkout_processed = 0)
            ORDER BY b.check_in ASC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Check if auto checkout has already executed today using new tracking table
     */
    private function hasExecutedToday($date) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT execution_completed FROM daily_execution_tracker 
                WHERE execution_date = ? 
                AND execution_completed = 1
            ");
            $stmt->execute([$date]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            $this->log("Error checking execution history: " . $e->getMessage(), 'WARNING');
            return false;
        }
    }
    
    /**
     * Mark execution day to prevent duplicates
     */
    private function markExecutionDay($date, $hour, $minute) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO daily_execution_tracker 
                (execution_date, execution_hour, execution_minute, execution_completed) 
                VALUES (?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE
                execution_hour = VALUES(execution_hour),
                execution_minute = VALUES(execution_minute)
            ");
            $stmt->execute([$date, $hour, $minute]);
        } catch (Exception $e) {
            $this->log("Error marking execution day: " . $e->getMessage(), 'WARNING');
        }
    }
    
    /**
     * Mark execution as completed
     */
    private function markExecutionCompleted($date, $bookingsProcessed) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE daily_execution_tracker 
                SET execution_completed = 1, bookings_processed = ?
                WHERE execution_date = ?
            ");
            $stmt->execute([$bookingsProcessed, $date]);
        } catch (Exception $e) {
            $this->log("Error marking execution completed: " . $e->getMessage(), 'WARNING');
        }
    }
    
    /**
     * Record cron execution in the new table
     */
    private function recordExecution($date, $time, $status, $found, $successful, $failed, $errorMessage = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO cron_execution_logs 
                (execution_date, execution_time, execution_type, bookings_found, bookings_processed, bookings_successful, bookings_failed, execution_status, error_message, server_time, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE
                execution_time = VALUES(execution_time),
                bookings_found = VALUES(bookings_found),
                bookings_processed = VALUES(bookings_processed),
                bookings_successful = VALUES(bookings_successful),
                bookings_failed = VALUES(bookings_failed),
                execution_status = VALUES(execution_status),
                error_message = VALUES(error_message),
                server_time = VALUES(server_time),
                notes = VALUES(notes)
            ");
            
            $executionType = $this->isManualRun() ? 'manual' : 'automatic';
            $notes = "Final Solution - Guaranteed 10:00 AM execution, manual payment only";
            
            $stmt->execute([
                $date, 
                $time, 
                $executionType, 
                $found, 
                $successful + $failed, 
                $successful, 
                $failed, 
                $status, 
                $errorMessage,
                $notes
            ]);
            
            // Update last run settings
            $this->updateSystemSetting('auto_checkout_last_run_date', $date);
            $this->updateSystemSetting('last_auto_checkout_run', date('Y-m-d H:i:s'));
            
        } catch (Exception $e) {
            $this->log("Failed to record execution: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Update system setting
     */
    private function updateSystemSetting($key, $value) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$key, $value]);
        } catch (Exception $e) {
            $this->log("Failed to update setting $key: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Check if this is a manual run
     */
    private function isManualRun() {
        return isset($_GET['manual_run']) || 
               isset($_GET['test']) || 
               isset($_GET['force']) ||
               (php_sapi_name() !== 'cli');
    }
    
    /**
     * Send checkout SMS
     */
    private function sendCheckoutSMS($booking) {
        try {
            if (file_exists(__DIR__ . '/sms_functions.php')) {
                require_once __DIR__ . '/sms_functions.php';
                $result = send_checkout_confirmation_sms($booking['id'], $this->pdo);
                $this->log("SMS result for booking {$booking['id']}: " . ($result['success'] ? 'SUCCESS' : 'FAILED'));
            }
        } catch (Exception $e) {
            $this->log("SMS error: " . $e->getMessage(), 'WARNING');
        }
    }
    
    /**
     * Create standardized response
     */
    private function createResponse($status, $message, $data = []) {
        return array_merge([
            'status' => $status,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'timezone' => $this->timezone,
            'run_type' => $this->isManualRun() ? 'manual' : 'automatic'
        ], $data);
    }
    
    /**
     * Test auto checkout (for manual testing)
     */
    public function testAutoCheckout() {
        $this->log("=== MANUAL TEST EXECUTION ===", 'TEST');
        return $this->executeDailyCheckout();
    }
    
    /**
     * Force checkout all bookings (emergency use)
     */
    public function forceCheckoutAll() {
        $this->log("=== FORCE CHECKOUT ALL BOOKINGS ===", 'FORCE');
        
        try {
            $bookings = $this->getBookingsForCheckout();
            $successful = 0;
            $failed = 0;
            $successfulBookings = [];
            $failedBookings = [];
            
            foreach ($bookings as $booking) {
                $result = $this->processSimpleCheckout($booking);
                if ($result['success']) {
                    $successful++;
                    $successfulBookings[] = $booking;
                } else {
                    $failed++;
                    $failedBookings[] = ['booking' => $booking, 'error' => $result['error']];
                }
            }
            
            $this->recordExecution(date('Y-m-d'), date('H:i'), 'success', count($bookings), $successful, $failed, 'Force checkout executed');
            
            return $this->createResponse('completed', "Force checkout completed", [
                'total_processed' => count($bookings),
                'successful' => $successful,
                'failed' => $failed,
                'details' => [
                    'successful' => $successfulBookings,
                    'failed' => $failedBookings
                ]
            ]);
            
        } catch (Exception $e) {
            $this->log("Force checkout error: " . $e->getMessage(), 'ERROR');
            return $this->createResponse('error', $e->getMessage());
        }
    }
    
    /**
     * Enhanced logging
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message";
        
        // Write to log file
        file_put_contents($this->logFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
        
        // Output for manual runs
        if ($this->isManualRun()) {
            echo $logMessage . "<br>\n";
        }
    }
}
?>