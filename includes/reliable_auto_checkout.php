<?php
/**
 * Reliable Auto Checkout System for L.P.S.T Hotel
 * 
 * This system uses a mandatory popup approach to ensure auto-checkout
 * happens daily when any admin logs in after 10:00 AM.
 */

class ReliableAutoCheckout {
    private $pdo;
    private $timezone;
    private $targetTime;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->timezone = 'Asia/Kolkata';
        $this->targetTime = '10:00:00';
        date_default_timezone_set($this->timezone);
    }
    
    /**
     * Check if auto-checkout popup should be shown to admin
     * Called on every admin login
     */
    public function shouldShowPopup($adminId) {
        try {
            $currentDate = date('Y-m-d');
            $currentTime = date('H:i:s');
            
            // Only show popup after 10:00 AM
            if ($currentTime < '10:00:00') {
                return [
                    'show_popup' => false,
                    'reason' => 'before_target_time',
                    'message' => 'Auto-checkout popup will appear after 10:00 AM'
                ];
            }
            
            // Check if auto-checkout already completed today
            $stmt = $this->pdo->prepare("
                SELECT is_completed, completed_at, completion_method 
                FROM daily_auto_checkout_status 
                WHERE checkout_date = ?
            ");
            $stmt->execute([$currentDate]);
            $status = $stmt->fetch();
            
            if ($status && $status['is_completed']) {
                return [
                    'show_popup' => false,
                    'reason' => 'already_completed',
                    'message' => 'Auto-checkout already completed today at ' . $status['completed_at']
                ];
            }
            
            // Check if this admin already saw popup today
            $stmt = $this->pdo->prepare("
                SELECT popup_shown, popup_action_taken 
                FROM admin_login_tracking 
                WHERE admin_id = ? AND login_date = ?
            ");
            $stmt->execute([$adminId, $currentDate]);
            $loginTrack = $stmt->fetch();
            
            if ($loginTrack && $loginTrack['popup_action_taken']) {
                return [
                    'show_popup' => false,
                    'reason' => 'already_processed',
                    'message' => 'Auto-checkout already processed by this admin today'
                ];
            }
            
            // Get pending rooms for checkout
            $pendingRooms = $this->getPendingRoomsForCheckout($currentDate);
            
            if (empty($pendingRooms)) {
                // No rooms to checkout, mark as completed
                $this->markDayCompleted($currentDate, $adminId, 'popup', 0, 0, 0, 'No rooms required checkout');
                
                return [
                    'show_popup' => false,
                    'reason' => 'no_pending_rooms',
                    'message' => 'No rooms require auto-checkout today'
                ];
            }
            
            // Record admin login and prepare popup
            $this->recordAdminLogin($adminId, $currentDate, $currentTime);
            
            return [
                'show_popup' => true,
                'pending_rooms' => $pendingRooms,
                'checkout_date' => $currentDate,
                'target_time' => $this->targetTime,
                'total_rooms' => count($pendingRooms)
            ];
            
        } catch (Exception $e) {
            error_log("Auto-checkout popup check error: " . $e->getMessage());
            return [
                'show_popup' => false,
                'reason' => 'error',
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get rooms that need auto-checkout for the specified date
     */
    private function getPendingRoomsForCheckout($checkoutDate) {
        try {
            // Get bookings that were active yesterday and need checkout
            $yesterdayDate = date('Y-m-d', strtotime($checkoutDate . ' -1 day'));
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    b.id as booking_id,
                    b.resource_id,
                    b.client_name,
                    b.client_mobile,
                    b.check_in,
                    b.actual_check_in,
                    b.status,
                    COALESCE(r.custom_name, r.display_name) as resource_name,
                    r.type as resource_type
                FROM bookings b 
                JOIN resources r ON b.resource_id = r.id 
                WHERE b.status IN ('BOOKED', 'PENDING')
                AND DATE(COALESCE(b.actual_check_in, b.check_in)) <= ?
                AND (b.auto_checkout_processed IS NULL OR b.auto_checkout_processed = 0)
                AND b.status != 'COMPLETED'
                ORDER BY b.check_in ASC
            ");
            $stmt->execute([$yesterdayDate]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Error getting pending rooms: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Process auto-checkout for all pending rooms
     * Called when admin confirms the popup
     */
    public function processAutoCheckout($adminId, $checkoutDate) {
        try {
            $this->pdo->beginTransaction();
            
            $startTime = microtime(true);
            $checkoutTimestamp = $checkoutDate . ' 10:00:00';
            
            // Get pending rooms
            $pendingRooms = $this->getPendingRoomsForCheckout($checkoutDate);
            
            if (empty($pendingRooms)) {
                $this->markDayCompleted($checkoutDate, $adminId, 'popup', 0, 0, 0, 'No rooms required checkout');
                $this->pdo->commit();
                
                return [
                    'success' => true,
                    'message' => 'No rooms required auto-checkout today',
                    'rooms_processed' => 0
                ];
            }
            
            $successful = 0;
            $failed = 0;
            $processedRooms = [];
            $failedRooms = [];
            
            foreach ($pendingRooms as $room) {
                try {
                    // Update booking to completed with 10:00 AM checkout time
                    $stmt = $this->pdo->prepare("
                        UPDATE bookings 
                        SET status = 'COMPLETED',
                            actual_check_out = ?,
                            auto_checkout_processed = 1,
                            auto_checkout_date = ?,
                            auto_checkout_timestamp = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $checkoutTimestamp,
                        $checkoutDate,
                        $checkoutTimestamp,
                        $room['booking_id']
                    ]);
                    
                    // Log the checkout
                    $stmt = $this->pdo->prepare("
                        INSERT INTO auto_checkout_logs 
                        (booking_id, resource_id, resource_name, guest_name, checkout_date, checkout_time, status, notes) 
                        VALUES (?, ?, ?, ?, ?, '10:00:00', 'success', ?)
                    ");
                    $stmt->execute([
                        $room['booking_id'],
                        $room['resource_id'],
                        $room['resource_name'],
                        $room['client_name'],
                        $checkoutDate,
                        "Auto-checkout via admin popup - Checkout time set to 10:00 AM"
                    ]);
                    
                    // Record in pending rooms table
                    $stmt = $this->pdo->prepare("
                        INSERT INTO auto_checkout_pending_rooms 
                        (checkout_date, booking_id, resource_id, resource_name, client_name, client_mobile, check_in_time, is_processed, processed_at, checkout_timestamp) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)
                        ON DUPLICATE KEY UPDATE
                        is_processed = 1,
                        processed_at = NOW(),
                        checkout_timestamp = VALUES(checkout_timestamp)
                    ");
                    $stmt->execute([
                        $checkoutDate,
                        $room['booking_id'],
                        $room['resource_id'],
                        $room['resource_name'],
                        $room['client_name'],
                        $room['client_mobile'],
                        $room['actual_check_in'] ?: $room['check_in'],
                        $checkoutTimestamp
                    ]);
                    
                    // Send SMS notification
                    $this->sendCheckoutSMS($room);
                    
                    $successful++;
                    $processedRooms[] = $room;
                    
                } catch (Exception $e) {
                    $failed++;
                    $failedRooms[] = [
                        'room' => $room,
                        'error' => $e->getMessage()
                    ];
                    
                    // Log failed checkout
                    try {
                        $stmt = $this->pdo->prepare("
                            INSERT INTO auto_checkout_logs 
                            (booking_id, resource_id, resource_name, guest_name, checkout_date, checkout_time, status, notes) 
                            VALUES (?, ?, ?, ?, ?, '10:00:00', 'failed', ?)
                        ");
                        $stmt->execute([
                            $room['booking_id'],
                            $room['resource_id'],
                            $room['resource_name'],
                            $room['client_name'],
                            $checkoutDate,
                            'Error: ' . $e->getMessage()
                        ]);
                    } catch (Exception $logError) {
                        error_log("Failed to log checkout error: " . $logError->getMessage());
                    }
                }
            }
            
            $processingDuration = round((microtime(true) - $startTime), 2);
            
            // Mark day as completed
            $this->markDayCompleted($checkoutDate, $adminId, 'popup', count($pendingRooms), $successful, $failed);
            
            // Record execution log
            $stmt = $this->pdo->prepare("
                INSERT INTO auto_checkout_execution_logs 
                (execution_date, execution_time, execution_method, triggered_by_admin_id, bookings_found, bookings_processed, bookings_successful, bookings_failed, execution_status, processing_duration_seconds, notes) 
                VALUES (?, '10:00:00', 'popup', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $executionStatus = ($failed === 0) ? 'success' : (($successful > 0) ? 'partial' : 'failed');
            $notes = "Auto-checkout triggered by admin popup - All checkout times set to 10:00 AM";
            
            $stmt->execute([
                $checkoutDate,
                $adminId,
                count($pendingRooms),
                $successful + $failed,
                $successful,
                $failed,
                $executionStatus,
                $processingDuration,
                $notes
            ]);
            
            // Mark admin as having taken action
            $stmt = $this->pdo->prepare("
                UPDATE admin_login_tracking 
                SET popup_action_taken = 1 
                WHERE admin_id = ? AND login_date = ?
            ");
            $stmt->execute([$adminId, $checkoutDate]);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => "Auto-checkout completed: $successful successful, $failed failed",
                'rooms_processed' => count($pendingRooms),
                'successful' => $successful,
                'failed' => $failed,
                'processed_rooms' => $processedRooms,
                'failed_rooms' => $failedRooms,
                'processing_duration' => $processingDuration
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Auto-checkout processing error: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Auto-checkout failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Record admin login for popup tracking
     */
    private function recordAdminLogin($adminId, $loginDate, $loginTime) {
        try {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            
            $stmt = $this->pdo->prepare("
                INSERT INTO admin_login_tracking 
                (admin_id, login_date, login_time, user_agent, ip_address) 
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                login_time = VALUES(login_time),
                login_timestamp = CURRENT_TIMESTAMP,
                user_agent = VALUES(user_agent),
                ip_address = VALUES(ip_address)
            ");
            $stmt->execute([$adminId, $loginDate, $loginTime, $userAgent, $ipAddress]);
            
        } catch (Exception $e) {
            error_log("Failed to record admin login: " . $e->getMessage());
        }
    }
    
    /**
     * Mark popup as shown for admin
     */
    public function markPopupShown($adminId, $date) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE admin_login_tracking 
                SET popup_shown = 1 
                WHERE admin_id = ? AND login_date = ?
            ");
            $stmt->execute([$adminId, $date]);
        } catch (Exception $e) {
            error_log("Failed to mark popup shown: " . $e->getMessage());
        }
    }
    
    /**
     * Mark day as completed
     */
    private function markDayCompleted($date, $adminId, $method, $found, $successful, $failed, $errorMessage = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO daily_auto_checkout_status 
                (checkout_date, target_checkout_time, is_completed, completed_at, completed_by_admin_id, completion_method, rooms_processed, rooms_successful, rooms_failed, error_message) 
                VALUES (?, '10:00:00', 1, NOW(), ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                is_completed = 1,
                completed_at = NOW(),
                completed_by_admin_id = VALUES(completed_by_admin_id),
                completion_method = VALUES(completion_method),
                rooms_processed = VALUES(rooms_processed),
                rooms_successful = VALUES(rooms_successful),
                rooms_failed = VALUES(rooms_failed),
                error_message = VALUES(error_message)
            ");
            $stmt->execute([$date, $adminId, $method, $found, $successful, $failed, $errorMessage]);
            
        } catch (Exception $e) {
            error_log("Failed to mark day completed: " . $e->getMessage());
        }
    }
    
    /**
     * Send checkout SMS notification
     */
    private function sendCheckoutSMS($room) {
        try {
            if (file_exists(__DIR__ . '/sms_functions.php')) {
                require_once __DIR__ . '/sms_functions.php';
                send_checkout_confirmation_sms($room['booking_id'], $this->pdo);
            }
        } catch (Exception $e) {
            error_log("SMS error for booking {$room['booking_id']}: " . $e->getMessage());
        }
    }
    
    /**
     * Get today's auto-checkout status
     */
    public function getTodayStatus() {
        try {
            $currentDate = date('Y-m-d');
            
            $stmt = $this->pdo->prepare("
                SELECT * FROM daily_auto_checkout_status 
                WHERE checkout_date = ?
            ");
            $stmt->execute([$currentDate]);
            $status = $stmt->fetch();
            
            $pendingCount = 0;
            if (!$status || !$status['is_completed']) {
                $pendingRooms = $this->getPendingRoomsForCheckout($currentDate);
                $pendingCount = count($pendingRooms);
            }
            
            return [
                'date' => $currentDate,
                'is_completed' => $status ? (bool)$status['is_completed'] : false,
                'completed_at' => $status['completed_at'] ?? null,
                'completion_method' => $status['completion_method'] ?? null,
                'rooms_processed' => $status['rooms_processed'] ?? 0,
                'pending_rooms_count' => $pendingCount,
                'current_time' => date('H:i:s'),
                'target_time' => $this->targetTime
            ];
            
        } catch (Exception $e) {
            error_log("Error getting today's status: " . $e->getMessage());
            return [
                'date' => date('Y-m-d'),
                'is_completed' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Backup cron execution (verification only)
     */
    public function backupCronExecution() {
        try {
            $currentDate = date('Y-m-d');
            $currentTime = date('H:i:s');
            
            // Only run between 10:00-10:30 AM
            if ($currentTime < '10:00:00' || $currentTime > '10:30:00') {
                return [
                    'success' => false,
                    'message' => 'Backup cron only runs between 10:00-10:30 AM',
                    'current_time' => $currentTime
                ];
            }
            
            // Check if already completed
            $stmt = $this->pdo->prepare("
                SELECT is_completed FROM daily_auto_checkout_status 
                WHERE checkout_date = ? AND is_completed = 1
            ");
            $stmt->execute([$currentDate]);
            
            if ($stmt->fetchColumn()) {
                return [
                    'success' => true,
                    'message' => 'Auto-checkout already completed today',
                    'action' => 'none_required'
                ];
            }
            
            // Check if any admin is currently logged in (popup should handle it)
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM admin_login_tracking 
                WHERE login_date = ? AND login_time >= '10:00:00'
            ");
            $stmt->execute([$currentDate]);
            $adminLoginsToday = $stmt->fetchColumn();
            
            if ($adminLoginsToday > 0) {
                return [
                    'success' => true,
                    'message' => 'Admin login detected - popup system will handle auto-checkout',
                    'action' => 'waiting_for_admin_popup'
                ];
            }
            
            // If no admin login after 10:30 AM, process automatically as backup
            if ($currentTime > '10:30:00') {
                $result = $this->processAutoCheckout(1, $currentDate); // Use admin ID 1 as fallback
                $result['backup_execution'] = true;
                return $result;
            }
            
            return [
                'success' => true,
                'message' => 'Waiting for admin login to trigger popup',
                'action' => 'waiting'
            ];
            
        } catch (Exception $e) {
            error_log("Backup cron execution error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Backup cron failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
}
?>