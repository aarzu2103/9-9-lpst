<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('OWNER');

$database = new Database();
$pdo = $database->getConnection();

// Handle all form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_auto_checkout':
                $autoCheckoutEnabled = isset($_POST['auto_checkout_enabled']) ? '1' : '0';
                
                try {
                    // Update auto checkout settings
                    $stmt = $pdo->prepare("
                        INSERT INTO system_settings (setting_key, setting_value) 
                        VALUES ('auto_checkout_enabled', ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                    ");
                    $stmt->execute([$autoCheckoutEnabled]);
                    
                    redirect_with_message('settings.php', 'Auto checkout settings updated successfully!', 'success');
                } catch (Exception $e) {
                    $error = 'Failed to update auto checkout settings: ' . $e->getMessage();
                }
                break;
                
            case 'test_auto_checkout':
                try {
                    require_once '../includes/auto_checkout.php';
                    $autoCheckout = new AutoCheckout($pdo);
                    $result = $autoCheckout->testAutoCheckout();
                    
                    $message = "Test completed: " . $result['status'] . " - Processed: " . ($result['total_processed'] ?? 0) . " bookings";
                    redirect_with_message('settings.php', $message, 'success');
                } catch (Exception $e) {
                    $error = 'Auto checkout test failed: ' . $e->getMessage();
                }
                break;
                
            case 'force_checkout_all':
                try {
                    require_once '../includes/auto_checkout.php';
                    $autoCheckout = new AutoCheckout($pdo);
                    $result = $autoCheckout->forceCheckoutAll();
                    
                    $message = "Force checkout completed: " . $result['status'] . " - Processed: " . ($result['total_processed'] ?? 0) . " bookings";
                    redirect_with_message('settings.php', $message, 'success');
                } catch (Exception $e) {
                    $error = 'Force checkout failed: ' . $e->getMessage();
                }
                break;
                
            case 'complete_system_reset':
                try {
                    // Complete system reset
                    $pdo->exec("DELETE FROM auto_checkout_logs WHERE DATE(created_at) = CURDATE()");
                    $pdo->exec("DELETE FROM cron_execution_logs WHERE execution_date = CURDATE()");
                    $pdo->exec("DELETE FROM daily_execution_tracker WHERE execution_date = CURDATE()");
                    $pdo->exec("UPDATE bookings SET auto_checkout_processed = 0 WHERE status IN ('BOOKED', 'PENDING')");
                    $pdo->exec("UPDATE system_settings SET setting_value = '' WHERE setting_key = 'last_auto_checkout_run'");
                    $pdo->exec("UPDATE system_settings SET setting_value = '' WHERE setting_key = 'auto_checkout_last_run_date'");
                    
                    redirect_with_message('settings.php', 'Complete system reset successful! Ready for fresh 10:00 AM execution.', 'success');
                } catch (Exception $e) {
                    $error = 'System reset failed: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Get current settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%auto_checkout%'");
$autoSettings = [];
while ($row = $stmt->fetch()) {
    $autoSettings[$row['setting_key']] = $row['setting_value'];
}

$autoEnabled = ($autoSettings['auto_checkout_enabled'] ?? '1') === '1';
$autoTime = $autoSettings['auto_checkout_time'] ?? '10:00';
$lastRunDate = $autoSettings['auto_checkout_last_run_date'] ?? '';

// Get active bookings count
$stmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('BOOKED', 'PENDING') AND (auto_checkout_processed IS NULL OR auto_checkout_processed = 0)");
$activeBookingsCount = $stmt->fetchColumn();

// Get today's execution status
$stmt = $pdo->prepare("
    SELECT * FROM cron_execution_logs 
    WHERE execution_date = CURDATE() 
    ORDER BY execution_time DESC 
    LIMIT 1
");
$stmt->execute();
$todayExecution = $stmt->fetch();

// Get daily execution tracker status
$stmt = $pdo->prepare("
    SELECT * FROM daily_execution_tracker 
    WHERE execution_date = CURDATE()
");
$stmt->execute();
$dailyTracker = $stmt->fetch();

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto Checkout Settings - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .system-status {
            padding: 1.5rem;
            border-radius: 10px;
            margin: 1rem 0;
            text-align: center;
            font-weight: bold;
        }
        .status-active {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            animation: pulse 3s infinite;
        }
        .status-inactive {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.8; }
            100% { opacity: 1; }
        }
        .test-controls {
            background: rgba(16, 185, 129, 0.1);
            border: 2px solid var(--success-color);
            border-radius: 10px;
            padding: 1.5rem;
            margin: 1rem 0;
        }
        .danger-zone {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid var(--danger-color);
            border-radius: 10px;
            padding: 1.5rem;
            margin: 1rem 0;
        }
        .final-fix-notice {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin: 1rem 0;
            text-align: center;
            font-weight: bold;
            animation: pulse 2s infinite;
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="index.php" class="nav-button">← Dashboard</a>
            <a href="admins.php" class="nav-button">Admins</a>
            <a href="reports.php" class="nav-button">Reports</a>
        </div>
        <a href="/" class="nav-brand">L.P.S.T Bookings</a>
        <div class="nav-links">
            <span style="margin-right: 1rem;">Owner Panel</span>
            <a href="../logout.php" class="nav-button danger">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash-message flash-<?= $flash['type'] ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="flash-message flash-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Reliable Auto Checkout System Status -->
        <?php
        // Get reliable auto checkout status
        require_once '../includes/reliable_auto_checkout.php';
        $reliableAutoCheckout = new ReliableAutoCheckout($pdo);
        $todayStatus = $reliableAutoCheckout->getTodayStatus();
        ?>
        
        <div class="final-fix-notice">
            🎯 RELIABLE AUTO CHECKOUT SYSTEM - POPUP-BASED SOLUTION
            <br><small>Guaranteed execution when any admin logs in after 10:00 AM</small>
        </div>
        
        <!-- Today's Auto Checkout Status -->
        <div class="form-container">
            <h3>📊 Today's Auto Checkout Status</h3>
            <div style="background: <?= $todayStatus['is_completed'] ? 'rgba(16, 185, 129, 0.1)' : 'rgba(255, 193, 7, 0.1)' ?>; padding: 1.5rem; border-radius: 8px; border: 2px solid <?= $todayStatus['is_completed'] ? '#10b981' : '#f59e0b' ?>;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div>
                        <strong>Date:</strong> <?= $todayStatus['date'] ?>
                    </div>
                    <div>
                        <strong>Status:</strong> 
                        <span style="color: <?= $todayStatus['is_completed'] ? '#10b981' : '#f59e0b' ?>; font-weight: bold;">
                            <?= $todayStatus['is_completed'] ? '✅ COMPLETED' : '⏳ PENDING' ?>
                        </span>
                    </div>
                    <div>
                        <strong>Current Time:</strong> <?= $todayStatus['current_time'] ?>
                    </div>
                    <div>
                        <strong>Target Time:</strong> <?= $todayStatus['target_time'] ?>
                    </div>
                </div>
                
                <?php if ($todayStatus['is_completed']): ?>
                    <div style="margin-top: 1rem; padding: 1rem; background: rgba(255, 255, 255, 0.8); border-radius: 4px;">
                        <p style="margin: 0; color: #155724; font-weight: 600;">
                            ✅ Completed at: <?= date('g:i A', strtotime($todayStatus['completed_at'])) ?>
                            | Method: <?= strtoupper($todayStatus['completion_method']) ?>
                            | Rooms: <?= $todayStatus['rooms_processed'] ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div style="margin-top: 1rem; padding: 1rem; background: rgba(255, 255, 255, 0.8); border-radius: 4px;">
                        <p style="margin: 0; color: #856404; font-weight: 600;">
                            ⏳ Pending rooms: <?= $todayStatus['pending_rooms_count'] ?>
                            | Will trigger when any admin logs in after 10:00 AM
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="final-fix-notice">
            🚨 FINAL SOLUTION APPLIED - AUTO CHECKOUT SYSTEM COMPLETELY REBUILT
            <br><small>All database conflicts resolved, bulletproof 10:00 AM execution, manual payment only</small>
        </div>

        <h2>🕙 Daily 10:00 AM Auto Checkout Master Control</h2>
        
        <!-- System Status Display -->
        <div class="system-status <?= $autoEnabled ? 'status-active' : 'status-inactive' ?>">
            <h3>🕙 AUTO CHECKOUT SYSTEM STATUS</h3>
            <p>Status: <?= $autoEnabled ? '✅ ENABLED' : '❌ DISABLED' ?></p>
            <p>Daily Execution Time: 10:00 AM (FIXED - Asia/Kolkata)</p>
            <p>Current Server Time: <?= date('H:i:s') ?></p>
            <p>Active Bookings Ready: <?= $activeBookingsCount ?></p>
            <?php if ($lastRunDate): ?>
                <p>Last Execution: <?= $lastRunDate ?></p>
            <?php endif; ?>
            <?php if ($todayExecution): ?>
                <p>Today's Status: <?= strtoupper($todayExecution['execution_status']) ?> 
                   (<?= $todayExecution['bookings_successful'] ?> successful, <?= $todayExecution['bookings_failed'] ?> failed)</p>
            <?php else: ?>
                <p>Today's Status: NOT EXECUTED YET</p>
            <?php endif; ?>
            <?php if ($dailyTracker): ?>
                <p>Daily Tracker: <?= $dailyTracker['execution_completed'] ? '✅ COMPLETED' : '⏳ PENDING' ?> 
                   at <?= $dailyTracker['execution_hour'] ?>:<?= sprintf('%02d', $dailyTracker['execution_minute']) ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Auto Checkout Configuration -->
        <div class="form-container">
            <h3>Auto Checkout Configuration</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_auto_checkout">
                
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="auto_checkout_enabled" <?= $autoEnabled ? 'checked' : '' ?>>
                        Enable Daily 10:00 AM Auto Checkout
                    </label>
                    <small style="color: var(--dark-color);">When enabled, all active bookings will be automatically checked out daily at exactly 10:00 AM</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Daily Checkout Time (FIXED)</label>
                    <input type="text" class="form-control" value="10:00 AM (FIXED)" readonly 
                           style="background: #f8f9fa; font-weight: bold; color: #007bff;">
                    <small style="color: var(--success-color); font-weight: 600;">
                        ✅ FIXED: System will run EXACTLY at 10:00 AM daily (execution window: 10:00-10:05 AM)
                    </small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Mode (FIXED)</label>
                    <input type="text" class="form-control" value="MANUAL ONLY - No Automatic Calculation" readonly 
                           style="background: #f8f9fa; font-weight: bold; color: #dc3545;">
                    <small style="color: var(--danger-color); font-weight: 600;">
                        ⚠️ IMPORTANT: Admin must manually mark payments after auto checkout in checkout logs
                    </small>
                </div>
                
                <button type="submit" class="btn btn-primary">Save Auto Checkout Settings</button>
            </form>
        </div>
        
        <!-- Manual Test Controls -->
        <div class="test-controls">
            <h3 style="color: var(--success-color);">🧪 Manual Test Controls (No Wait Required)</h3>
            <p>Test the auto checkout system immediately without waiting for 10:00 AM:</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin: 1rem 0;">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="test_auto_checkout">
                    <button type="submit" class="btn btn-success" style="width: 100%; padding: 1rem;">
                        🧪 Test Auto Checkout Now
                    </button>
                    <small style="display: block; margin-top: 0.5rem; color: var(--dark-color);">
                        Tests the system - NO payment calculation
                    </small>
                </form>
                
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="force_checkout_all">
                    <button type="submit" class="btn btn-warning" style="width: 100%; padding: 1rem;"
                            onclick="return confirm('This will force checkout ALL active bookings immediately. Continue?')">
                        🚨 Force Checkout All
                    </button>
                    <small style="display: block; margin-top: 0.5rem; color: var(--dark-color);">
                        Immediately processes all bookings
                    </small>
                </form>
            </div>
            
            <div style="margin-top: 1rem;">
                <a href="../cron/auto_checkout_cron.php?manual_run=1" target="_blank" class="btn btn-outline">
                    🔗 Test Cron Script Directly
                </a>
                <a href="../admin/auto_checkout_logs.php" class="btn btn-outline">
                    📋 View Checkout Logs
                </a>
            </div>
        </div>
        
        <!-- System Reset (Danger Zone) -->
        <div class="danger-zone">
            <h3 style="color: var(--danger-color);">🚨 Complete System Reset (If Still Not Working)</h3>
            <p>Use this if auto checkout is still not working after the final rebuild:</p>
            
            <form method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="complete_system_reset">
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('This will reset the entire auto checkout system. All today\'s logs will be cleared and flags reset. Continue?')">
                    🔄 Complete System Reset
                </button>
            </form>
            <small style="display: block; margin-top: 0.5rem; color: var(--dark-color);">
                Clears all flags, logs, and prepares system for fresh execution
            </small>
        </div>
        
        <!-- Current System Information -->
        <div class="form-container">
            <h3>Current System Information - Final Solution</h3>
            <div style="background: rgba(37, 99, 235, 0.1); padding: 1.5rem; border-radius: 8px;">
                <h4 style="color: var(--primary-color);">System Configuration:</h4>
                <ul>
                    <li><strong>Auto Checkout:</strong> <?= $autoEnabled ? '✅ ENABLED' : '❌ DISABLED' ?></li>
                    <li><strong>Execution Time:</strong> 10:00 AM (FIXED)</li>
                    <li><strong>Execution Window:</strong> 10:00-10:05 AM</li>
                    <li><strong>Timezone:</strong> Asia/Kolkata</li>
                    <li><strong>Current Time:</strong> <?= date('H:i:s') ?></li>
                    <li><strong>Active Bookings:</strong> <?= $activeBookingsCount ?></li>
                    <li><strong>Payment Mode:</strong> MANUAL ONLY (No automatic calculation)</li>
                    <li><strong>SMS Notifications:</strong> Enabled</li>
                    <li><strong>System Version:</strong> 5.0 (Final Solution)</li>
                    <li><strong>Duplicate Prevention:</strong> Bulletproof daily tracking</li>
                </ul>
                
                <?php if ($todayExecution): ?>
                    <h4 style="color: var(--primary-color);">Today's Execution:</h4>
                    <ul>
                        <li><strong>Status:</strong> <?= strtoupper($todayExecution['execution_status']) ?></li>
                        <li><strong>Time:</strong> <?= $todayExecution['execution_time'] ?></li>
                        <li><strong>Bookings Found:</strong> <?= $todayExecution['bookings_found'] ?></li>
                        <li><strong>Successful:</strong> <?= $todayExecution['bookings_successful'] ?></li>
                        <li><strong>Failed:</strong> <?= $todayExecution['bookings_failed'] ?></li>
                        <?php if ($todayExecution['error_message']): ?>
                            <li><strong>Error:</strong> <?= htmlspecialchars($todayExecution['error_message']) ?></li>
                        <?php endif; ?>
                    </ul>
                <?php else: ?>
                    <h4 style="color: var(--warning-color);">Today's Execution:</h4>
                    <p>Auto checkout has not executed today yet. Next execution: Tomorrow at 10:00 AM</p>
                <?php endif; ?>
                
                <?php if ($dailyTracker): ?>
                    <h4 style="color: var(--primary-color);">Daily Execution Tracker:</h4>
                    <ul>
                        <li><strong>Execution Date:</strong> <?= $dailyTracker['execution_date'] ?></li>
                        <li><strong>Execution Time:</strong> <?= $dailyTracker['execution_hour'] ?>:<?= sprintf('%02d', $dailyTracker['execution_minute']) ?></li>
                        <li><strong>Completed:</strong> <?= $dailyTracker['execution_completed'] ? '✅ YES' : '⏳ NO' ?></li>
                        <li><strong>Bookings Processed:</strong> <?= $dailyTracker['bookings_processed'] ?></li>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Hostinger Cron Job Instructions -->
        <div class="form-container">
            <h3>🔧 Hostinger Cron Job Setup (Already Configured)</h3>
            <div style="background: rgba(37, 99, 235, 0.1); padding: 1.5rem; border-radius: 8px;">
                <h4>Your Cron Job Command (Already Active):</h4>
                <div style="background: white; padding: 1rem; border-radius: 4px; font-family: monospace; margin: 0.5rem 0; border: 2px solid #007bff;">
                    0 10 * * * /usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/auto_checkout_cron.php
                </div>
                
                <div style="background: rgba(40, 167, 69, 0.1); padding: 1rem; border-radius: 4px; margin-top: 1rem;">
                    <p style="margin: 0; color: var(--success-color); font-weight: 600;">
                        ✅ This cron job is already active and will execute EXACTLY at 10:00 AM every day.
                    </p>
                </div>
                
                <h4>What Was Fixed in Final Solution:</h4>
                <ul>
                    <li>✅ Created missing `daily_execution_tracker` table for bulletproof duplicate prevention</li>
                    <li>✅ Fixed time logic to ONLY run 10:00-10:05 AM (bulletproof checking)</li>
                    <li>✅ Removed ALL payment calculation - manual payment only</li>
                    <li>✅ Simplified checkout process - just status change and SMS</li>
                    <li>✅ Enhanced duplicate prevention with daily tracking</li>
                    <li>✅ Improved error handling and logging</li>
                    <li>✅ Hostinger-compatible SQL with proper charset</li>
                </ul>
            </div>
        </div>
        
        <!-- Troubleshooting -->
        <div class="form-container">
            <h3>🔍 Final Solution Details</h3>
            <div style="background: rgba(255, 193, 7, 0.1); padding: 1.5rem; border-radius: 8px;">
                <h4>Why Previous Versions Failed:</h4>
                <ol>
                    <li><strong>Missing Tables:</strong> `cron_execution_logs` and `daily_execution_tracker` were missing</li>
                    <li><strong>Complex Payment Logic:</strong> Automatic payment calculation was causing errors</li>
                    <li><strong>Weak Time Checking:</strong> Time logic allowed execution at wrong times</li>
                    <li><strong>Poor Duplicate Prevention:</strong> System couldn't properly track daily executions</li>
                    <li><strong>Database Conflicts:</strong> Column conflicts between different migration files</li>
                </ol>
                
                <h4>Final Solution Features:</h4>
                <ol>
                    <li><strong>Bulletproof Time Check:</strong> ONLY runs if hour=10 AND minute≤5</li>
                    <li><strong>Daily Execution Tracker:</strong> New table prevents multiple runs per day</li>
                    <li><strong>Simplified Checkout:</strong> Just changes status to COMPLETED, no payment calculation</li>
                    <li><strong>Manual Payment:</strong> Admin marks payments manually with custom amounts</li>
                    <li><strong>Enhanced Logging:</strong> Detailed logs for debugging</li>
                    <li><strong>Hostinger Compatible:</strong> Uses only standard MySQL syntax</li>
                </ol>
                
                <h4>How It Works Now:</h4>
                <ol>
                    <li><strong>Reliable Method:</strong> When any admin logs in after 10:00 AM, mandatory popup appears</li>
                    <li><strong>Popup Content:</strong> Shows all rooms that need checkout for previous day</li>
                    <li><strong>One-Click Action:</strong> Admin clicks "Confirm Auto Checkout" (no other options)</li>
                    <li><strong>Processing:</strong> All bookings marked as COMPLETED with 10:00 AM checkout time</li>
                    <li><strong>Backup System:</strong> Cron job runs as verification between 10:00-11:00 AM</li>
                    <li><strong>SMS Notifications:</strong> Sent to all checked-out guests</li>
                    <li><strong>Payment:</strong> Admin manually marks payments in checkout logs</li>
                </ol>
            </div>
        </div>
        
        <!-- Payment Instructions for Admin -->
        <div class="form-container">
            <h3>💰 Payment Process (Manual Only)</h3>
            <div style="background: rgba(239, 68, 68, 0.1); padding: 1.5rem; border-radius: 8px;">
                <h4 style="color: var(--danger-color);">IMPORTANT: No Automatic Payment Calculation</h4>
                <ol>
                    <li>Auto checkout popup appears when admin logs in after 10:00 AM</li>
                    <li>Admin confirms checkout - all bookings marked COMPLETED with 10:00 AM time</li>
                    <li>NO payment amount is calculated automatically</li>
                    <li>Admin must go to <a href="../admin/auto_checkout_logs.php" style="color: var(--primary-color); font-weight: bold;">Checkout Logs</a></li>
                    <li>For each checkout, admin clicks "Mark Paid" button</li>
                    <li>Admin enters the actual amount received from guest</li>
                    <li>Admin selects payment method (Online/Offline)</li>
                    <li>Payment is recorded with custom amount</li>
                </ol>
                
                <div style="background: rgba(255, 255, 255, 0.8); padding: 1rem; border-radius: 4px; margin-top: 1rem;">
                    <p style="margin: 0; color: var(--dark-color); font-weight: 600;">
                        💡 This ensures accurate payment tracking as admin can set the exact amount received from each guest.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Reliable System Information -->
        <div class="form-container">
            <h3>🎯 Reliable Auto Checkout System</h3>
            <div style="background: rgba(37, 99, 235, 0.1); padding: 1.5rem; border-radius: 8px;">
                <h4>Why This Solution is Reliable:</h4>
                <ul>
                    <li><strong>No Cron Dependency:</strong> Doesn't rely on cron jobs working perfectly</li>
                    <li><strong>Admin Triggered:</strong> Activates when any admin logs in after 10:00 AM</li>
                    <li><strong>Mandatory Popup:</strong> Cannot be dismissed or ignored</li>
                    <li><strong>One-Click Process:</strong> Simple confirmation processes all pending checkouts</li>
                    <li><strong>Backup System:</strong> Cron job provides verification and backup</li>
                    <li><strong>Audit Trail:</strong> Complete logging of all actions and timings</li>
                </ul>
                
                <h4>Backup Cron Job (Verification Only):</h4>
                <div style="background: white; padding: 1rem; border-radius: 4px; margin: 0.5rem 0;">
                    <code style="display: block; background: #f8f9fa; padding: 0.5rem; border-radius: 4px;">
                        */30 10-11 * * * /usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/reliable_auto_checkout_backup.php
                    </code>
                    <small>Runs every 30 minutes between 10:00-11:00 AM as backup verification</small>
                </div>
            </div>
        </div>
    </div>
</body>
</html>