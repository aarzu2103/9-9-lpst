<?php
/**
 * REBUILT Auto Checkout Cron Job - Day 7 Final Solution
 * GUARANTEED daily 10:00 AM execution with bulletproof logic
 * SIMPLIFIED - NO PAYMENT CALCULATION
 * 
 * HOSTINGER CRON JOB COMMAND:
 * 0 10 * * * /usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/auto_checkout_cron.php
 */

// Set timezone FIRST
date_default_timezone_set('Asia/Kolkata');

// Create logs directory
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Enhanced logging function
function logMessage($message, $level = 'INFO') {
    global $logDir;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message";
    
    // Write to daily log file
    $dailyLogFile = $logDir . '/auto_checkout_' . date('Y-m-d') . '.log';
    file_put_contents($dailyLogFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
    
    // Write to main log file
    $mainLogFile = $logDir . '/auto_checkout.log';
    file_put_contents($mainLogFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
    
    // Output for manual runs
    if (isset($_GET['manual_run']) || isset($_GET['test']) || php_sapi_name() !== 'cli') {
        echo $logMessage . "<br>\n";
    }
}

// Check if this is a manual run
$isManualRun = isset($_GET['manual_run']) || isset($_GET['test']) || php_sapi_name() !== 'cli';

if ($isManualRun) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>Auto Checkout Execution</title>";
    echo "<style>body{font-family:Arial;margin:20px;line-height:1.6;} .success{color:green;font-weight:bold;} .error{color:red;font-weight:bold;} .warning{color:orange;font-weight:bold;} .info{color:blue;font-weight:bold;}</style>";
    echo "</head><body>";
    echo "<h2>🕙 Daily 10:00 AM Auto Checkout Execution - Day 7 Final Fix</h2>";
    echo "<p><strong>Current Time:</strong> " . date('H:i:s') . " (Asia/Kolkata)</p>";
    echo "<p><strong>Current Date:</strong> " . date('Y-m-d') . "</p>";
    echo "<p><strong>Execution Mode:</strong> " . ($isManualRun ? 'MANUAL TEST' : 'AUTOMATIC CRON') . "</p>";
    echo "<p><strong>Payment Mode:</strong> MANUAL ONLY (No automatic calculation)</p>";
    logMessage("=== MANUAL AUTO CHECKOUT TEST STARTED ===", 'TEST');
} else {
    logMessage("=== DAILY 10:00 AM AUTO CHECKOUT STARTED ===", 'CRON');
}

logMessage("Execution mode: " . ($isManualRun ? 'MANUAL TEST' : 'AUTOMATIC CRON'));
logMessage("Target execution time: 10:00-10:05 AM daily");
logMessage("Current server time: " . date('H:i:s'));
logMessage("Payment mode: MANUAL ONLY (no automatic calculation)");

// Database connection with proper error handling
try {
    $host = 'localhost';
    $dbname = 'u261459251_patel';
    $username = 'u261459251_levagt';
    $password = 'GtPatelsamaj@0330';
    
    logMessage("Connecting to database: $host/$dbname");
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 30
    ]);
    
    $pdo->exec("SET time_zone = '+05:30'");
    logMessage("Database connection successful");
    
} catch(PDOException $e) {
    $error = "Database connection failed: " . $e->getMessage();
    logMessage($error, 'CRITICAL');
    
    if ($isManualRun) {
        echo "<p class='error'>$error</p>";
        echo "</body></html>";
    }
    exit(1);
}

// Verify required tables exist
$requiredTables = ['bookings', 'resources', 'auto_checkout_logs', 'system_settings', 'cron_execution_logs'];
foreach ($requiredTables as $table) {
    try {
        $stmt = $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        logMessage("Table '$table' verified");
    } catch (Exception $e) {
        $error = "Required table '$table' is missing: " . $e->getMessage();
        logMessage($error, 'CRITICAL');
        
        if ($isManualRun) {
            echo "<p class='error'>$error</p>";
            echo "<p class='warning'>Please run the SQL migration file to create missing tables.</p>";
            echo "</body></html>";
        }
        exit(1);
    }
}

// Load and execute auto checkout
try {
    require_once dirname(__DIR__) . '/includes/auto_checkout.php';
    
    logMessage("AutoCheckout class loaded successfully");
    
    $autoCheckout = new AutoCheckout($pdo);
    
    // Execute the daily checkout
    $result = $autoCheckout->executeDailyCheckout();
    
    // Log detailed results
    $logLevel = ($result['status'] === 'error') ? 'ERROR' : 'INFO';
    logMessage("Auto Checkout Result: " . json_encode($result), $logLevel);
    
    // Output results for manual runs
    if ($isManualRun) {
        echo "<h3>Execution Results:</h3>";
        echo "<div style='background: #f8f9fa; padding: 1rem; border-radius: 8px; border: 1px solid #ddd;'>";
        echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
        echo "</div>";
        
        if ($result['status'] === 'completed') {
            echo "<p class='success'>✅ Auto checkout completed successfully!</p>";
            echo "<p>Total processed: " . ($result['total_processed'] ?? 0) . " bookings</p>";
            echo "<p>Successful: " . ($result['successful'] ?? 0) . " bookings</p>";
            echo "<p>Failed: " . ($result['failed'] ?? 0) . " bookings</p>";
            echo "<p class='info'>💡 Payment: Admin will mark payments manually in checkout logs</p>";
            
            if (isset($result['details']['successful']) && !empty($result['details']['successful'])) {
                echo "<h4>Successfully Checked Out:</h4>";
                echo "<ul>";
                foreach ($result['details']['successful'] as $booking) {
                    echo "<li>{$booking['resource_name']}: {$booking['client_name']}</li>";
                }
                echo "</ul>";
            }
            
        } elseif ($result['status'] === 'no_bookings') {
            echo "<p class='warning'>⚠️ No active bookings found for checkout</p>";
        } elseif ($result['status'] === 'wrong_hour' || $result['status'] === 'wrong_minute') {
            echo "<p class='info'>ℹ️ Not time for auto checkout yet</p>";
            echo "<p>Current: " . date('H:i') . ", Target: 10:00-10:05 AM</p>";
        } elseif ($result['status'] === 'already_executed') {
            echo "<p class='info'>ℹ️ Auto checkout already executed today</p>";
        } else {
            echo "<p class='error'>❌ Auto checkout failed: " . ($result['message'] ?? 'Unknown error') . "</p>";
        }
        
        echo "<div style='margin-top: 2rem;'>";
        echo "<a href='../admin/auto_checkout_logs.php' style='background:#007bff; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; margin-right:10px;'>📋 View Logs</a>";
        echo "<a href='../owner/settings.php' style='background:#28a745; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; margin-right:10px;'>⚙️ Settings</a>";
        echo "<a href='../grid.php' style='background:#6c757d; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>🏠 Dashboard</a>";
        echo "</div>";
        echo "</body></html>";
    }
    
    // Exit with appropriate code
    exit($result['status'] === 'error' ? 1 : 0);
    
} catch (Exception $e) {
    $errorMessage = "Auto Checkout Critical Error: " . $e->getMessage();
    logMessage($errorMessage, 'CRITICAL');
    
    if ($isManualRun) {
        echo "<p class='error'>Critical Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<div style='background:#f8d7da; padding:15px; border-radius:8px; margin:10px 0;'>";
        echo "<h4>Error Details:</h4>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        echo "</div>";
        echo "</body></html>";
    }
    
    exit(1);
}
?>